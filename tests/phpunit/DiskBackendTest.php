<?php
/**
 * DiskBackend 单元测试。
 *
 * 重点在两件容易被写错的事：
 * 1. **精准失效**：删一个 URL 只能删它自己的缓存，不能顺手清掉同路径的分页/参数变体；
 * 2. **原子写**：写入必须走"临时文件 + rename"，否则并发请求会读到半截 HTML。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Cache\Backend\DiskBackend;
use AT8\SiteAccelerator\Cache\CachePath;
use AT8\SiteAccelerator\Support\Filesystem;

/**
 * Class DiskBackendTest
 */
final class DiskBackendTest extends TestCase {

	/**
	 * 本次用例独占的缓存根目录。
	 *
	 * 必须放在 `WP_CONTENT_DIR . '/cache'` 之下——`Filesystem::is_inside_cache_root()`
	 * 会拒绝这个范围之外的任何路径（那正是它的职责）。
	 *
	 * @var string
	 */
	private $root = '';

	/**
	 * 建立干净的缓存根。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = WP_CONTENT_DIR . '/cache/at8sa-unit-disk';

		if ( is_dir( $this->root ) ) {
			Filesystem::rrmdir( $this->root );
		}
	}

	/**
	 * 清理缓存根，避免污染其它用例与后续运行。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->root && is_dir( $this->root ) ) {
			Filesystem::rrmdir( $this->root );
		}

		parent::tearDown();
	}

	/**
	 * 造一个后端实例。
	 *
	 * @return DiskBackend
	 */
	private function backend() {
		return new DiskBackend( $this->root );
	}

	/**
	 * 后端名称固定为 Disk。
	 *
	 * @return void
	 */
	public function test_name() {
		$this->assertSame( 'Disk', $this->backend()->name() );
	}

	/**
	 * 写入后能读回，且内容逐字节一致。
	 *
	 * @return void
	 */
	public function test_set_then_get_roundtrip() {
		$backend = $this->backend();
		$html    = "<!DOCTYPE html><html><body>你好</body></html>";

		$this->assertTrue( $backend->set( 'example.com', '/p/', $html, 0 ) );
		$this->assertSame( $html, $backend->get( 'example.com', '/p/' ) );
	}

	/**
	 * 未写入的 URL 返回 false（而不是空串）。
	 *
	 * 区分"没有缓存"和"缓存了空内容"很重要：返回空串会让 CacheEngine
	 * 认为命中了空白页。
	 *
	 * @return void
	 */
	public function test_get_miss_returns_false() {
		$this->assertFalse( $this->backend()->get( 'example.com', '/nope/' ) );
	}

	/**
	 * 移动端与桌面端变体互不覆盖。
	 *
	 * @return void
	 */
	public function test_mobile_and_desktop_variants_are_separate() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/p/', 'desktop', 0, false );
		$backend->set( 'example.com', '/p/', 'mobile', 0, true );

		$this->assertSame( 'desktop', $backend->get( 'example.com', '/p/', false ) );
		$this->assertSame( 'mobile', $backend->get( 'example.com', '/p/', true ) );
	}

	/**
	 * 写入走临时文件 + rename，不留 `.tmp` 残渣。
	 *
	 * 残留的 `.tmp` 会随着时间堆积成成千上万个文件，是磁盘后端最常见的运维事故。
	 *
	 * @return void
	 */
	public function test_write_leaves_no_temp_files() {
		$backend = $this->backend();

		for ( $i = 0; $i < 5; $i++ ) {
			$backend->set( 'example.com', '/p' . $i . '/', 'x', 0 );
		}

		// 用递归迭代器而不是 glob('**')：PHP 的 glob() 不递归展开 `**`，
		// 那样写会永远返回空数组，测试变成永真断言。
		$leftovers = array();
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'tmp' === strtolower( $file->getExtension() ) ) {
				$leftovers[] = $file->getPathname();
			}
		}

		$this->assertSame( array(), $leftovers, '不应残留 .tmp 文件' );
	}

	/**
	 * 缓存根被放上 index.php 守卫（防止目录被直接列出）。
	 *
	 * @return void
	 */
	public function test_root_gets_index_guard() {
		$backend = $this->backend();
		$backend->set( 'example.com', '/p/', 'x', 0 );

		$this->assertFileExists( $this->root . '/index.php' );
	}

	/**
	 * TTL 到期后视为未命中，并顺手删除文件。
	 *
	 * @return void
	 */
	public function test_expired_entry_is_a_miss_and_is_cleaned() {
		$backend = $this->backend();
		$backend->set( 'example.com', '/p/', 'x', 0 );

		$file = CachePath::disk_file( $this->root, 'example.com', '/p/' );
		$this->assertFileExists( $file );

		// 把 mtime 拨到 1000 秒前，再声明 TTL=60。
		touch( $file, time() - 1000 );
		$GLOBALS['at8sa_runtime_ttl'] = 60;

		$this->assertFalse( $backend->get( 'example.com', '/p/' ) );
		$this->assertFileDoesNotExist( $file, '过期文件应被顺手清掉' );
	}

	/**
	 * TTL=0 表示永不过期。
	 *
	 * @return void
	 */
	public function test_ttl_zero_never_expires() {
		$backend = $this->backend();
		$backend->set( 'example.com', '/p/', 'x', 0 );

		$file = CachePath::disk_file( $this->root, 'example.com', '/p/' );
		touch( $file, time() - 999999 );

		$GLOBALS['at8sa_runtime_ttl'] = 0;

		$this->assertSame( 'x', $backend->get( 'example.com', '/p/' ) );
	}

	/**
	 * delete_url 同时清掉桌面与移动两个变体。
	 *
	 * @return void
	 */
	public function test_delete_url_removes_both_variants() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/p/', 'desktop', 0, false );
		$backend->set( 'example.com', '/p/', 'mobile', 0, true );

		$this->assertSame( 2, $backend->delete_url( 'example.com', '/p/' ) );
		$this->assertFalse( $backend->get( 'example.com', '/p/', false ) );
		$this->assertFalse( $backend->get( 'example.com', '/p/', true ) );
	}

	/**
	 * **核心卖点回归测试**：删一个 URL 不能连坐它的分页/参数变体。
	 *
	 * `/hello/` 与 `/hello/?page=2` 是两个不同页面。若 delete_url 用 rrmdir
	 * 清整个目录，改一篇不带分页的文章就会顺手清掉它的所有分页缓存——
	 * 缓存多清一次只是性能损失，但会让"精准失效"名存实亡。
	 *
	 * @return void
	 */
	public function test_delete_url_does_not_touch_query_variants() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/p/', 'plain', 0 );
		$backend->set( 'example.com', '/p/?page=2', 'page2', 0 );

		$deleted = $backend->delete_url( 'example.com', '/p/' );

		$this->assertSame( 1, $deleted, '只应删掉不带查询串的那一份' );
		$this->assertFalse( $backend->get( 'example.com', '/p/' ) );
		$this->assertSame( 'page2', $backend->get( 'example.com', '/p/?page=2' ), '分页变体必须保留' );
	}

	/**
	 * 删除不存在的 URL 返回 0，不报错。
	 *
	 * @return void
	 */
	public function test_delete_missing_url_returns_zero() {
		$this->assertSame( 0, $this->backend()->delete_url( 'example.com', '/nope/' ) );
	}

	/**
	 * delete_urls 累加各 URL 的删除数。
	 *
	 * @return void
	 */
	public function test_delete_urls_aggregates() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/a/', 'x', 0 );
		$backend->set( 'example.com', '/b/', 'x', 0 );
		$backend->set( 'example.com', '/c/', 'x', 0 );

		$this->assertSame( 2, $backend->delete_urls( 'example.com', array( '/a/', '/b/', '/missing/' ) ) );
		$this->assertSame( 'x', $backend->get( 'example.com', '/c/' ), '未列出的 URL 不受影响' );
	}

	/**
	 * 不同主机的同名路径互不影响（多站点隔离）。
	 *
	 * @return void
	 */
	public function test_hosts_are_isolated() {
		$backend = $this->backend();

		$backend->set( 'a.test', '/p/', 'A', 0 );
		$backend->set( 'b.test', '/p/', 'B', 0 );

		$backend->delete_url( 'a.test', '/p/' );

		$this->assertFalse( $backend->get( 'a.test', '/p/' ) );
		$this->assertSame( 'B', $backend->get( 'b.test', '/p/' ) );
	}

	/**
	 * flush 清空页面缓存，但保留 config/（drop-in 运行时配置不属于页面缓存）。
	 *
	 * @return void
	 */
	public function test_flush_clears_pages_but_keeps_config() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/p/', 'x', 0 );

		$config_dir = $this->root . '/config';
		mkdir( $config_dir, 0777, true );
		file_put_contents( $config_dir . '/default.php', "<?php\nreturn array();\n" );

		$this->assertTrue( $backend->flush() );

		$this->assertFalse( $backend->get( 'example.com', '/p/' ) );
		$this->assertFileExists( $config_dir . '/default.php', 'config/ 必须保留，否则 drop-in 读不到配置' );
	}

	/**
	 * stats 只统计 `.html`，不把 index.php 守卫算成缓存页。
	 *
	 * @return void
	 */
	public function test_stats_counts_only_html() {
		$backend = $this->backend();

		$backend->set( 'example.com', '/a/', '12345', 0 );
		$backend->set( 'example.com', '/b/', '123', 0 );

		$stats = $backend->stats();

		$this->assertSame( 2, $stats['count'] );
		$this->assertSame( 8, $stats['bytes'] );
	}

	/**
	 * 空缓存根的 stats 全零，且不报错。
	 *
	 * @return void
	 */
	public function test_stats_on_missing_root_is_zero() {
		$this->assertSame(
			array(
				'count' => 0,
				'bytes' => 0,
			),
			$this->backend()->stats()
		);
	}
}

<?php
/**
 * 失效器契约测试（磁盘后端）。
 *
 * 为什么要钉"purge_all() 返回真实条目数"这条契约：
 * 旧实现里 `purge_all()` 先 `bump_cache_version()` 再 `make()`，于是拿到的是**新盐**
 * 的后端实例——`stats()` 在新盐命名空间上统计，恒为 0；`flush()` 也打在新盐上，
 * 什么都没删到。日志里的"失效条目数"因此完全失真，用户点"清缓存"看到的永远是 0 条。
 *
 * Redis 专属的那一半（孤儿索引集合）由 RedisBackendTest 覆盖，因为磁盘后端的
 * 路径不参与盐，观察不到顺序差异。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\Config;
use AT8SA\Core\Settings;
use AT8SA\Purge\Purger;
use AT8SA\Support\Filesystem;
use AT8SA\Support\Logger;

/**
 * Class PurgerTest
 */
final class PurgerTest extends TestCase {

	/**
	 * 每个用例前清掉缓存目录。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Filesystem::rrmdir( AT8SA_CACHE_ROOT );

		$this->set_request( array( 'HTTP_HOST' => 'example.test' ) );
	}

	/**
	 * 造一套失效器。
	 *
	 * @param array $overrides 设置覆盖项。
	 * @return array{0: Purger, 1: BackendFactory, 2: Settings}
	 */
	private function make_purger( array $overrides = array() ) {
		$settings = $this->make_settings(
			array_merge(
				array(
					'page_cache'     => 1,
					'advanced_cache' => 0,
					'cache_backend'  => 'disk',
					'cache_ttl'      => 3600,
				),
				$overrides
			)
		);

		$logger  = new Logger( $settings );
		$factory = new BackendFactory( $settings, $logger );

		return array( new Purger( $settings, $factory, $logger ), $factory, $settings );
	}

	/**
	 * purge_all() 必须返回真实条目数，而不是恒为 0。
	 *
	 * @return void
	 */
	public function test_purge_all_reports_real_count() {
		list( $purger, $factory ) = $this->make_purger();

		$backend = $factory->make();

		$this->assertNotFalse( $backend );

		$backend->set( 'example.test', '/a/', '<html>A</html>', 3600 );
		$backend->set( 'example.test', '/b/', '<html>B</html>', 3600 );

		$this->assertSame( 2, $backend->stats()['count'], '前置条件：两条缓存已写入' );

		$this->assertSame(
			2,
			$purger->purge_all(),
			'purge_all() 必须在换盐**之前**统计，否则永远返回 0'
		);
	}

	/**
	 * purge_all() 后缓存必须真的空了。
	 *
	 * @return void
	 */
	public function test_purge_all_empties_backend() {
		list( $purger, $factory ) = $this->make_purger();

		$factory->make()->set( 'example.test', '/a/', '<html>A</html>', 3600 );

		$purger->purge_all();

		$fresh = ( new BackendFactory( new Settings(), new Logger( new Settings() ) ) )->make();

		$this->assertNotFalse( $fresh );
		$this->assertFalse( $fresh->get( 'example.test', '/a/' ), '清缓存后不该还能读到旧页面' );
	}

	/**
	 * purge_all() 必须递增缓存版本盐，且只递增一次。
	 *
	 * @return void
	 */
	public function test_purge_all_bumps_version_exactly_once() {
		list( $purger, $factory ) = $this->make_purger();

		$before = $factory->cache_version();

		$purger->purge_all();

		$this->assertSame(
			$before + 1,
			$factory->cache_version(),
			'多递增一次会让旧键白白多留一个版本'
		);
	}

	/**
	 * 换盐之后 drop-in 的运行时配置必须同步重写。
	 *
	 * 否则 drop-in 仍按旧盐读键——表现为"缓存了却永远不命中"。
	 *
	 * @return void
	 */
	public function test_purge_all_rewrites_runtime_config_with_new_salt() {
		list( $purger, $factory, $settings ) = $this->make_purger();

		$config = new Config( $settings, $factory );
		$config->write( $config->runtime() );

		$file = AT8SA_CACHE_ROOT . '/config/example.test.php';

		$this->assertFileExists( $file );

		$old_salt = ( include $file )['salt'];

		$purger->purge_all();

		$new_salt = ( include $file )['salt'];

		$this->assertNotSame( $old_salt, $new_salt, '换盐后运行时配置里的盐必须跟着变' );
		$this->assertSame( $factory->salt(), $new_salt );
	}

	/**
	 * purge_url() 只失效指定 URL，不能牵连其它页面。
	 *
	 * @return void
	 */
	public function test_purge_url_is_scoped() {
		list( $purger, $factory ) = $this->make_purger();

		$backend = $factory->make();

		$backend->set( 'example.test', '/keep/', '<html>KEEP</html>', 3600 );
		$backend->set( 'example.test', '/drop/', '<html>DROP</html>', 3600 );

		$purger->purge_url( 'https://example.test/drop/' );

		$this->assertFalse( $backend->get( 'example.test', '/drop/' ), '目标 URL 应被清掉' );
		$this->assertSame(
			'<html>KEEP</html>',
			$backend->get( 'example.test', '/keep/' ),
			'无关 URL 不该被牵连'
		);
	}

	/**
	 * 站外 URL 不得触发任何本地删除。
	 *
	 * @return void
	 */
	public function test_purge_url_ignores_foreign_host() {
		list( $purger, $factory ) = $this->make_purger();

		$backend = $factory->make();
		$backend->set( 'example.test', '/keep/', '<html>KEEP</html>', 3600 );

		$purger->purge_url( 'https://other-site.test/whatever/' );

		$this->assertSame( '<html>KEEP</html>', $backend->get( 'example.test', '/keep/' ) );
	}
}

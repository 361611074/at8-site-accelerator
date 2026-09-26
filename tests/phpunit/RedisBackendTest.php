<?php
/**
 * Redis 后端测试。
 *
 * 为什么必须单独有一组：
 * `RedisBackend` 的分支（索引集合、SCAN 兜底、孤儿清扫）在没有 Redis 的环境里
 * 一行都不会执行，而"降级到磁盘"的测试永远会绿。2026-09-26 真机验证时正是这个盲区
 * 让"失效器清的是 Redis、断言查的是磁盘"这个测试缺陷一直藏到部署。
 *
 * 本组用例在 Redis 不可达时**显式跳过**（而不是伪造通过），
 * 并固定使用 15 号逻辑库——绝不能碰 2 号库，那是插件的默认库，
 * 在开发者本机可能正跑着真实站点。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Cache\Backend\RedisBackend;
use AT8\SiteAccelerator\Support\RedisClient;

/**
 * Class RedisBackendTest
 */
final class RedisBackendTest extends TestCase {

	/**
	 * 本站点令牌（形如真实 COOKIEHASH 的 32 位十六进制）。
	 */
	const TOKEN_A = 'aaaa1111bbbb2222cccc3333dddd4444';

	/**
	 * 另一个站点的令牌（用于验证隔离）。
	 */
	const TOKEN_B = 'eeee5555ffff6666aaaa7777bbbb8888';

	/**
	 * 环境不具备时跳过。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! $this->reachable() ) {
			$this->markTestSkipped( 'Redis 不可达（127.0.0.1:6379），跳过 Redis 后端用例' );
		}

		$this->clean();
	}

	/**
	 * 每个用例后清场。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( $this->reachable() ) {
			$this->clean();
		}

		parent::tearDown();
	}

	/**
	 * Redis 是否可达。
	 *
	 * @return bool
	 */
	private function reachable() {
		$client = new RedisClient( '127.0.0.1', 6379, 1.0, AT8SA_REDIS_DB );

		return false !== $client->ping();
	}

	/**
	 * 清空测试库里的本插件命名空间。
	 *
	 * 只删 `at8sa:` 前缀，不做 FLUSHDB——15 号库也可能被同机的其它项目使用。
	 *
	 * @return void
	 */
	private function clean() {
		$client = new RedisClient( '127.0.0.1', 6379, 1.0, AT8SA_REDIS_DB );

		foreach ( $client->scan( 'at8sa:*' ) as $key ) {
			$client->del( array( $key ) );
		}
	}

	/**
	 * 造一个后端。
	 *
	 * @param string $salt 站点盐。
	 * @return RedisBackend
	 */
	private function backend( $salt ) {
		return new RedisBackend( $salt, '127.0.0.1', 6379, AT8SA_REDIS_DB );
	}

	/**
	 * 列出测试库里的键。
	 *
	 * @param string $pattern 通配。
	 * @return array
	 */
	private function keys( $pattern ) {
		$client = new RedisClient( '127.0.0.1', 6379, 1.0, AT8SA_REDIS_DB );

		return $client->scan( $pattern );
	}

	/**
	 * 基本读写往返。
	 *
	 * @return void
	 */
	public function test_set_get_delete_round_trip() {
		$backend = $this->backend( self::TOKEN_A . '|v1' );

		$this->assertTrue( $backend->set( 'example.test', '/a/', '<html>A</html>', 60 ) );
		$this->assertSame( '<html>A</html>', $backend->get( 'example.test', '/a/' ) );

		// 移动端变体与桌面端必须互不串味。
		$this->assertFalse( $backend->get( 'example.test', '/a/', true ) );

		$this->assertSame( 1, $backend->delete_url( 'example.test', '/a/' ) );
		$this->assertFalse( $backend->get( 'example.test', '/a/' ) );
	}

	/**
	 * set() 必须同时维护索引集合。
	 *
	 * 索引集合是 stats() 与 flush() 的依据，缺了它 flush 只能靠 SCAN 兜底。
	 *
	 * @return void
	 */
	public function test_set_registers_index_entry() {
		$backend = $this->backend( self::TOKEN_A . '|v1' );

		$backend->set( 'example.test', '/a/', '<html>A</html>', 60 );

		$this->assertSame( 1, $backend->stats()['count'] );
		$this->assertNotEmpty( $this->keys( 'at8sa:' . self::TOKEN_A . '|v1|__index' ) );
	}

	/**
	 * flush() 必须删干净当前盐的条目与索引集合。
	 *
	 * @return void
	 */
	public function test_flush_removes_current_salt_entries_and_index() {
		$backend = $this->backend( self::TOKEN_A . '|v1' );

		$backend->set( 'example.test', '/a/', '<html>A</html>', 60 );
		$backend->set( 'example.test', '/b/', '<html>B</html>', 60 );

		$this->assertTrue( $backend->flush() );

		$this->assertFalse( $backend->get( 'example.test', '/a/' ) );
		$this->assertFalse( $backend->get( 'example.test', '/b/' ) );
		$this->assertSame( 0, $backend->stats()['count'] );
		$this->assertSame( array(), $this->keys( 'at8sa:' . self::TOKEN_A . '|v1|__index' ) );
	}

	/**
	 * 核心回归：flush() 必须顺带清掉**历史版本**遗留的孤儿索引集合。
	 *
	 * 旧实现里 purge_all() 先递增盐再 flush，flush 打在新盐命名空间上什么也没删到，
	 * 于是每个旧版本的索引集合（SADD 建的、没有 TTL）被永久孤立在 Redis 里。
	 * 真机测试机上曾观察到 v10~v36 共 21 个这样的孤儿。
	 *
	 * @return void
	 */
	public function test_flush_cleans_orphan_index_sets() {
		// 造三个历史版本，各留下一个索引集合。
		foreach ( array( 'v1', 'v2', 'v3' ) as $version ) {
			$this->backend( self::TOKEN_A . '|' . $version )
				->set( 'example.test', '/' . $version . '/', '<html>' . $version . '</html>', 60 );
		}

		$pattern = 'at8sa:' . self::TOKEN_A . '|*|__index';

		$this->assertCount( 3, $this->keys( $pattern ), '前置条件：三个版本的索引集合都在' );

		// 只 flush 最新盐，孤儿也应被一并清掉。
		$this->backend( self::TOKEN_A . '|v3' )->flush();

		$this->assertSame(
			array(),
			$this->keys( $pattern ),
			'历史版本的索引集合必须被清掉，否则会永久堆积在 Redis 里'
		);
	}

	/**
	 * 清孤儿时绝不能误伤同 Redis 上的其它站点。
	 *
	 * @return void
	 */
	public function test_flush_does_not_touch_other_sites() {
		$other = $this->backend( self::TOKEN_B . '|v1' );
		$other->set( 'other.test', '/x/', '<html>X</html>', 60 );

		$this->backend( self::TOKEN_A . '|v1' )->set( 'example.test', '/a/', '<html>A</html>', 60 );
		$this->backend( self::TOKEN_A . '|v1' )->flush();

		$this->assertSame(
			'<html>X</html>',
			$other->get( 'other.test', '/x/' ),
			'清 A 站点时把 B 站点的缓存删了'
		);
		$this->assertNotEmpty(
			$this->keys( 'at8sa:' . self::TOKEN_B . '|v1|__index' ),
			'B 站点的索引集合被误删'
		);
	}

	/**
	 * 空盐时必须安全退避，不能冒"误清全局"的风险。
	 *
	 * @return void
	 */
	public function test_flush_refuses_empty_salt() {
		$backend = $this->backend( self::TOKEN_A . '|v1' );
		$backend->set( 'example.test', '/a/', '<html>A</html>', 60 );

		$empty = $this->backend( '' );

		$this->assertFalse( $empty->flush(), '空盐 flush 必须返回 false' );
		$this->assertSame(
			'<html>A</html>',
			$backend->get( 'example.test', '/a/' ),
			'空盐 flush 不该删掉任何东西'
		);
	}
}

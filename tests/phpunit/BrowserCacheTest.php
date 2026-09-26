<?php
/**
 * BrowserCache 单元测试。
 *
 * 这个类会**直接改写站点的 .htaccess**，属于"写错一次就可能让整站 500"的操作。
 * 所以用例覆盖：危险组合告警、规则片段形态、以及"改文件前必须备份 + 幂等"。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Optimization\BrowserCache;

/**
 * Class BrowserCacheTest
 */
final class BrowserCacheTest extends TestCase {

	/**
	 * 测试用 .htaccess 路径（落在桩的 ABSPATH 里）。
	 *
	 * @var string
	 */
	private $htaccess = '';

	/**
	 * 建立干净的 .htaccess。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->htaccess = ABSPATH . '.htaccess';

		if ( ! is_dir( ABSPATH ) ) {
			mkdir( ABSPATH, 0777, true );
		}

		$this->remove_htaccess_files();
	}

	/**
	 * 清理测试产生的文件。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_htaccess_files();

		parent::tearDown();
	}

	/**
	 * 删掉 .htaccess 及其备份。
	 *
	 * @return void
	 */
	private function remove_htaccess_files() {
		foreach ( array( $this->htaccess, $this->htaccess . '.at8sa.bak' ) as $file ) {
			if ( '' !== $file && is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * asset_ttl 有 3600 秒下界。
	 *
	 * @param int $configured 配置值。
	 * @param int $expected   期望。
	 * @return void
	 * @dataProvider provide_asset_ttl
	 */
	public function test_asset_ttl_has_lower_bound( $configured, $expected ) {
		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache_ttl' => $configured ) ) );

		$this->assertSame( $expected, $browser->asset_ttl() );
	}

	/**
	 * asset_ttl 用例。
	 *
	 * @return array
	 */
	public static function provide_asset_ttl() {
		return array(
			'零被抬到 3600'   => array( 0, 3600 ),
			'负数被抬到 3600' => array( -1, 3600 ),
			'一分钟被抬到 3600' => array( 60, 3600 ),
			'一年原样保留'    => array( 31536000, 31536000 ),
		);
	}

	/**
	 * 危险组合判定：只在"移除 ?ver= + 长缓存"同时成立时为真。
	 *
	 * 为什么这是危险组合：`style.css?ver=1.2` 里的版本号是主题更新后
	 * 强制刷新缓存的唯一手段。把它删掉再叠一年的 `immutable` 缓存，
	 * 访客会永远停在旧样式上——而站长完全看不出原因。
	 *
	 * @param int  $browser_cache        开启静态资源长缓存。
	 * @param int  $remove_query_strings 移除 ?ver=。
	 * @param int  $ttl                  TTL。
	 * @param bool $expected             期望。
	 * @return void
	 * @dataProvider provide_risky_combinations
	 */
	public function test_has_risky_combination( $browser_cache, $remove_query_strings, $ttl, $expected ) {
		$browser = new BrowserCache(
			$this->make_settings(
				array(
					'browser_cache'         => $browser_cache,
					'remove_query_strings'  => $remove_query_strings,
					'browser_cache_ttl'     => $ttl,
				)
			)
		);

		$this->assertSame( $expected, $browser->has_risky_combination() );
	}

	/**
	 * 危险组合用例。
	 *
	 * @return array
	 */
	public static function provide_risky_combinations() {
		$year = 31536000;
		$week = 604800;

		return array(
			'两者都开且长缓存 → 危险' => array( 1, 1, $year, true ),
			'关掉长缓存 → 不危险'     => array( 0, 1, $year, false ),
			'关掉移除版本号 → 不危险' => array( 1, 0, $year, false ),
			'两者都关 → 不危险'       => array( 0, 0, $year, false ),
			'TTL 恰为 7 天 → 不危险'  => array( 1, 1, $week, false ),
			'TTL 超过 7 天 → 危险'    => array( 1, 1, $week + 1, true ),
		);
	}

	/**
	 * Apache 规则片段形态正确。
	 *
	 * @return void
	 */
	public function test_apache_rules_shape() {
		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache_ttl' => 31536000 ) ) );
		$rules   = $browser->apache_rules();

		$this->assertStringContainsString( '# ==== BEGIN AT8 Site Accelerator ====', $rules );
		$this->assertStringContainsString( '# ==== END AT8 Site Accelerator ====', $rules );
		$this->assertStringContainsString( '<IfModule mod_expires.c>', $rules );
		$this->assertStringContainsString( 'ExpiresByType text/css', $rules );

		// HTML 绝不能长缓存：它的新鲜度由插件的整页缓存负责，
		// 两边都做缓存会让"发布新文章后访客看不到"变成常态。
		$this->assertStringContainsString(
			'ExpiresByType text/html "access plus 0 seconds"',
			$rules,
			'HTML 必须显式禁止浏览器缓存'
		);
	}

	/**
	 * nginx 规则片段形态正确。
	 *
	 * @return void
	 */
	public function test_nginx_rules_shape() {
		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache_ttl' => 31536000 ) ) );
		$rules   = $browser->nginx_rules();

		$this->assertStringContainsString( 'expires 31536000s;', $rules );
		$this->assertStringContainsString( 'immutable', $rules );
		$this->assertStringContainsString( 'access_log off;', $rules );
		$this->assertStringContainsString( 'no-cache', $rules, 'HTML 必须 no-cache' );
	}

	/**
	 * server_type 由 SERVER_SOFTWARE 推断。
	 *
	 * @param string $software 软件标识。
	 * @param string $expected 期望。
	 * @return void
	 * @dataProvider provide_server_software
	 */
	public function test_server_type( $software, $expected ) {
		$this->set_request( array( 'SERVER_SOFTWARE' => $software ) );

		$this->assertSame( $expected, ( new BrowserCache( $this->make_settings() ) )->server_type() );
	}

	/**
	 * 服务器软件用例。
	 *
	 * @return array
	 */
	public static function provide_server_software() {
		return array(
			'nginx'          => array( 'nginx/1.24.0', 'nginx' ),
			'nginx 大写'     => array( 'NGINX/1.24.0', 'nginx' ),
			'Apache'         => array( 'Apache/2.4.58 (Ubuntu)', 'apache' ),
			'LiteSpeed'      => array( 'LiteSpeed', 'litespeed' ),
			'未知'           => array( 'Caddy', 'unknown' ),
			'空值'           => array( '', 'unknown' ),
		);
	}

	/**
	 * 没有 .htaccess 时写入返回失败（而不是凭空创建）。
	 *
	 * 不自动创建是刻意的：某些站点根本没有 .htaccess（nginx），
	 * 凭空造一个反而会让用户困惑。
	 *
	 * @return void
	 */
	public function test_write_fails_when_htaccess_missing() {
		$result = ( new BrowserCache( $this->make_settings() ) )->write_htaccess();

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * 写入流程：追加规则块 + 生成备份 + 幂等。
	 *
	 * @return void
	 */
	public function test_write_then_remove_htaccess_cycle() {
		$original = "# 站点原有规则\nRewriteEngine On\n";
		file_put_contents( $this->htaccess, $original );

		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache_ttl' => 31536000 ) ) );

		$this->assertFalse( $browser->htaccess_has_rules(), '写入前不应有本插件标记' );

		$result = $browser->write_htaccess();

		$this->assertTrue( $result['ok'] );
		$this->assertFileExists( $this->htaccess . '.at8sa.bak', '必须先备份' );
		$this->assertSame( $original, file_get_contents( $this->htaccess . '.at8sa.bak' ), '备份内容必须是原文' );
		$this->assertTrue( $browser->htaccess_has_rules() );

		$written = file_get_contents( $this->htaccess );

		$this->assertStringContainsString( 'RewriteEngine On', $written, '原有规则不能被破坏' );
		$this->assertStringContainsString( '# ==== BEGIN AT8 Site Accelerator ====', $written );

		// 再写一次必须幂等，不能叠加第二份规则。
		$again = $browser->write_htaccess();

		$this->assertTrue( $again['ok'] );
		$this->assertSame(
			1,
			substr_count( file_get_contents( $this->htaccess ), '# ==== BEGIN AT8 Site Accelerator ====' ),
			'规则块不能被重复追加'
		);

		// 移除后原有内容应恢复。
		$removed = $browser->remove_htaccess();

		$this->assertTrue( $removed['ok'] );
		$this->assertFalse( $browser->htaccess_has_rules() );
		$this->assertStringContainsString( 'RewriteEngine On', file_get_contents( $this->htaccess ) );
		$this->assertStringNotContainsString( 'AT8 Site Accelerator', file_get_contents( $this->htaccess ) );
	}

	/**
	 * 没有本插件规则时移除返回失败，不会误删别人的内容。
	 *
	 * @return void
	 */
	public function test_remove_without_rules_reports_failure() {
		file_put_contents( $this->htaccess, "# 别人的规则\n" );

		$result = ( new BrowserCache( $this->make_settings() ) )->remove_htaccess();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( "# 别人的规则\n", file_get_contents( $this->htaccess ), '内容必须原样不动' );
	}
}

<?php
/**
 * 整页缓存写入的「响应头禁止信号」守卫测试（3.0.6.4 / WordPress.org 终审 P0）。
 *
 * 对应任务书要求：移除 HTML 的 `Cache-Control: public` 并不等于整页缓存
 * 写入天然安全——两项必须分别验证。本文件验证写入侧：
 * 带 Set-Cookie / Vary: Cookie / Cache-Control: private|no-store|no-cache
 * 的响应**不得**进入共享 HTML 缓存；普通匿名公共页面照常缓存。
 *
 * 断言落在**真实的缓存后端文件**上（DiskBackend 写入 fake-wp 缓存根后
 * 用 get() 回读），而不是只测辅助函数的布尔返回值。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\CacheEngine;
use AT8SA\Core\Settings;
use AT8SA\Optimization\HtmlMinifier;
use AT8SA\Support\Logger;

/**
 * 可注入响应头的 CacheEngine 测试替身。
 *
 * `headers_list()` 是 PHP 内建函数、无法桩掉，因此生产代码在构造参数上
 * 留了一个可选的响应头读取器（仅测试注入）；测试用例用闭包共享数组。
 */

/**
 * Class CacheEngineHeaderGuardTest
 */
final class CacheEngineHeaderGuardTest extends TestCase {

	/**
	 * 注入给引擎替身的响应头。
	 *
	 * @var array
	 */
	private $headers;

	/**
	 * 注入给引擎的状态码。
	 *
	 * @var int|false
	 */
	private $status_code = false;

	/**
	 * 被测引擎。
	 *
	 * @var CacheEngine
	 */
	private $engine;

	/**
	 * 真实磁盘后端（用于断言缓存文件是否落盘）。
	 *
	 * @var \AT8SA\Cache\Backend\DiskBackend
	 */
	private $backend;

	/**
	 * 每个用例开始前构建引擎与后端。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->headers = array();
		$this->set_request();
		// 条件标签开关由 TestCase::setUp() 的 reset_wp_state() 统一复位。

		// 清空 fake-wp 缓存根：salt/host/uri 在各用例里相同，
		// 不清的话上一个用例的缓存文件会污染下一个用例的断言。
		$this->clear_cache_root();

		$settings = $this->make_settings(
			array(
				'page_cache'   => 1,
				'cache_backend' => 'disk',
				'html_minify'  => 0,
			)
		);

		$logger   = new Logger();
		$factory  = new BackendFactory( $settings, $logger );
		$backend  = $factory->make();
		$minifier = new HtmlMinifier( $settings );

		// backend 必须是 DiskBackend（cache_backend=disk 时工厂保证）。
		$this->assertInstanceOf( 'AT8SA\\Cache\\Backend\\DiskBackend', $backend );
		$this->backend = $backend;

		$headers = &$this->headers;
		$status  = &$this->status_code;

		$this->engine = new CacheEngine(
			$settings,
			$factory,
			$logger,
			$minifier,
			static function () use ( &$headers ) {
				return $headers;
			},
			static function () use ( &$status ) {
				return $status;
			}
		);
	}

	/**
	 * 递归清空测试缓存根（保留目录本身）。
	 *
	 * @return void
	 */
	private function clear_cache_root() {
		$root = AT8SA_CACHE_ROOT;

		if ( ! is_dir( $root ) ) {
			return;
		}

		$stack = array( $root );

		while ( ! empty( $stack ) ) {
			$dir = array_pop( $stack );

			foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
				$path = $dir . '/' . $item;

				if ( is_dir( $path ) ) {
					$stack[] = $path;
				} else {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}

			if ( $dir !== $root ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * 匿名公共页面：无任何响应头时照常缓存。
	 *
	 * @return void
	 */
	public function test_anonymous_public_page_is_cached() {
		$html = '<html><body>public page v1</body></html>';

		$out = $this->engine->store( $html );

		$this->assertStringContainsString(
			'public page v1',
			(string) $out,
			'返回给首访者的响应体应包含原始内容（可另附指纹注释）'
		);
		$this->assertNotFalse(
			$this->backend->get( 'example.test', '/', false ),
			'普通匿名页面必须落盘'
		);
		$this->assertStringContainsString(
			'public page v1',
			(string) $this->backend->get( 'example.test', '/', false ),
			'落盘内容必须是本次响应体'
		);
	}

	/**
	 * Set-Cookie 出现即拒缓存（大小写不敏感）。
	 *
	 * @return void
	 */
	public function test_set_cookie_blocks_caching() {
		foreach ( array( 'Set-Cookie: ab_group=1; Path=/', 'set-cookie: consent=1' ) as $line ) {
			$this->headers = array( 'Content-Type: text/html; charset=UTF-8', $line );

			$this->engine->store( '<html>personalized</html>' );

			$this->assertFalse(
				$this->backend->get( 'example.test', '/', false ),
				'带 Set-Cookie 的响应不得落盘（头：' . $line . '）'
			);
		}
	}

	/**
	 * Vary 含 Cookie（或通配 *）即拒缓存；普通 Vary 不拦。
	 *
	 * @return void
	 */
	public function test_vary_variants() {
		$blocking = array(
			'Vary: Cookie',
			'vary: cookie',
			'Vary: Accept-Language, Cookie',
			'Vary: Accept-Encoding, Cookie, User-Agent',
			'Vary: *',
		);
		$benign   = array(
			'Vary: Accept-Encoding',
			'Vary: Accept-Language, User-Agent',
		);

		foreach ( $blocking as $line ) {
			$this->headers = array( $line );
			$this->engine->store( '<html>vary blocked</html>' );
			$this->assertFalse(
				$this->backend->get( 'example.test', '/', false ),
				'Vary 含 Cookie/* 的响应不得落盘（头：' . $line . '）'
			);
		}

		foreach ( $benign as $line ) {
			$this->headers = array( $line );
			$this->engine->store( '<html>vary benign ' . md5( $line ) . '</html>' );
			$this->assertNotFalse(
				$this->backend->get( 'example.test', '/', false ),
				'不含 Cookie 的 Vary 不应拦截缓存（头：' . $line . '）'
			);
			// 清场，避免影响下一轮断言。
			$this->backend->delete_url( 'example.test', '/', false );
		}
	}

	/**
	 * Cache-Control 的 private / no-store / no-cache 指令拒缓存；
	 * 大小写、多指令、多同名头都要接住。
	 *
	 * @return void
	 */
	public function test_cache_control_directive_matrix() {
		$blocking = array(
			array( 'Cache-Control: private' ),
			array( 'cache-control: NO-STORE' ),
			array( 'Cache-Control: no-cache' ),
			array( 'Cache-Control: max-age=3600, private, must-revalidate' ),
			array( 'Cache-Control: max-age=3600', 'Cache-Control: no-store' ),
			array( 'Cache-Control: private="Session-ID"' ),
		);
		$benign   = array(
			array( 'Cache-Control: max-age=3600' ),
			array( 'Cache-Control: public, max-age=600' ),
			array( 'Cache-Control: max-age=0, must-revalidate' ),
		);

		foreach ( $blocking as $lines ) {
			$this->headers = $lines;
			$this->engine->store( '<html>cc blocked</html>' );
			$this->assertFalse(
				$this->backend->get( 'example.test', '/', false ),
				'Cache-Control 含禁止共享指令的响应不得落盘（头：' . implode( ' | ', $lines ) . '）'
			);
		}

		foreach ( $benign as $lines ) {
			$this->headers = $lines;
			$this->engine->store( '<html>cc benign ' . implode( '', $lines ) . '</html>' );
			$this->assertNotFalse(
				$this->backend->get( 'example.test', '/', false ),
				'不含禁止指令的 Cache-Control 不应拦截缓存（头：' . implode( ' | ', $lines ) . '）'
			);
			$this->backend->delete_url( 'example.test', '/', false );
		}
	}

	/**
	 * 跨访客隔离：第一位访客的个性化响应被跳过后，
	 * 第二位访客不能收到第一位访客的 HTML。
	 *
	 * @return void
	 */
	public function test_personalized_response_is_not_leaked_to_next_visitor() {
		// 访客 A：响应带 Set-Cookie（个性化），内容含 A 的标记。
		$this->headers = array( 'Set-Cookie: ab_group=A; Path=/' );
		$this->engine->store( '<html>visitor A dashboard</html>' );

		$this->assertFalse(
			$this->backend->get( 'example.test', '/', false ),
			'A 的个性化响应必须被跳过，缓存里不应有任何条目'
		);

		// 访客 B：无 Set-Cookie，内容含 B 的标记，正常写入。
		$this->headers = array();
		$this->engine->store( '<html>visitor B page</html>' );

		$cached = (string) $this->backend->get( 'example.test', '/', false );

		$this->assertStringContainsString( 'visitor B page', $cached, 'B 的公共响应应正常落盘' );
		$this->assertStringNotContainsString( 'visitor A dashboard', $cached, 'B 绝不能读到 A 的 HTML' );

		// 反向顺序：B 先写好缓存，A 的个性化响应随后仍不得污染它。
		$this->backend->delete_url( 'example.test', '/', false );
		$this->engine->store( '<html>visitor B page 2</html>' );

		$this->headers = array( 'Set-Cookie: ab_group=A; Path=/' );
		$this->engine->store( '<html>visitor A dashboard 2</html>' );

		$cached = (string) $this->backend->get( 'example.test', '/', false );

		$this->assertStringContainsString( 'visitor B page 2', $cached, 'B 的缓存不应被 A 的跳过写入破坏' );
		$this->assertStringNotContainsString( 'visitor A dashboard 2', $cached, 'A 的个性化 HTML 不得进入缓存' );
	}

	/**
	 * 既有准入规则不被本次改动削弱：登录用户 / 密码保护页仍不写缓存。
	 *
	 * @return void
	 */
	public function test_existing_bypass_rules_still_hold() {
		$GLOBALS['at8sa_test_logged_in'] = true;
		$this->headers                   = array();
		$this->engine->store( '<html>logged in view</html>' );
		$this->assertFalse( $this->backend->get( 'example.test', '/', false ), '登录用户的响应不得落盘' );

		$GLOBALS['at8sa_test_logged_in']       = false;
		$GLOBALS['at8sa_test_password_required'] = true;
		$this->engine->store( '<html>password protected</html>' );
		$this->assertFalse( $this->backend->get( 'example.test', '/', false ), '密码保护页不得落盘' );
	}

	/**
	 * 非 200（带正文的重定向/错误）不落盘；既有状态码防线仍生效。
	 *
	 * @return void
	 */
	public function test_non_2xx_status_not_cached() {
		$this->status_code = 301;
		$this->headers     = array();
		$this->engine->store( '<html>moved with body</html>' );
		$this->assertFalse( $this->backend->get( 'example.test', '/', false ), '301 响应不得落盘' );

		$this->status_code = 404;
		$this->engine->store( '<html>not found body</html>' );
		$this->assertFalse( $this->backend->get( 'example.test', '/', false ), '404 响应不得落盘' );

		$this->status_code = 200;
	}
}

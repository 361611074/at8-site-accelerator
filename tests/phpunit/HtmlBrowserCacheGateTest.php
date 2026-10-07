<?php
/**
 * HTML 浏览器缓存「公共响应安全 Gate」测试。
 *
 * 对应 WordPress.org 人工审核的 P1-02：
 *
 * > Public HTML browser-cache headers are applied based only on login status, so
 * > anonymous cart, password-protected, or other personalized responses can be
 * > marked cacheable by shared intermediaries.
 *
 * 修之前的判定只有一句 `if ( ! is_user_logged_in() )`，匿名购物车 / 密码保护 /
 * 带 Set-Cookie 的响应都会被标成 `public`，从而可能被中间层端给别的访客。
 *
 * 本文件把审核要求的整张矩阵逐条跑一遍，并额外钉住两条不变式：
 * - 开关关闭时**永远**不发公共头（哪怕其它条件全部满足）；
 * - Gate 与整页缓存共用同一套 Cookie / 路径规则，两边不会各说各话。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\RequestGuard;
use AT8SA\Optimization\BrowserCache;

/**
 * Class HtmlBrowserCacheGateTest
 */
final class HtmlBrowserCacheGateTest extends TestCase {

	/**
	 * 一份"应当通过"的基线请求。
	 *
	 * @return array
	 */
	private function baseline() {
		return array(
			'server'  => array(
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '/blog/hello/',
				'HTTP_HOST'      => 'example.test',
			),
			'cookies' => array(),
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
			'status'  => 200,
			'flags'   => array(),
		);
	}

	/**
	 * 跑一次 Gate。
	 *
	 * @param array $args 场景（server / cookies / headers / status / flags / settings）。
	 * @return bool
	 */
	private function decide( array $args ) {
		$base     = $this->baseline();
		$server   = array_merge( $base['server'], isset( $args['server'] ) ? $args['server'] : array() );
		$cookies  = array_key_exists( 'cookies', $args ) ? $args['cookies'] : $base['cookies'];
		$headers  = array_key_exists( 'headers', $args ) ? $args['headers'] : $base['headers'];
		$status   = array_key_exists( 'status', $args ) ? $args['status'] : $base['status'];
		$settings = array_key_exists( 'settings', $args ) ? $args['settings'] : array();

		foreach ( (array) ( isset( $args['flags'] ) ? $args['flags'] : array() ) as $flag => $value ) {
			$GLOBALS[ $flag ] = $value;
		}

		$_SERVER               = array_merge( $_SERVER, $server );
		$_SERVER['REQUEST_URI'] = $server['REQUEST_URI'];
		$_COOKIE               = (array) $cookies;

		$browser = new BrowserCache(
			$this->make_settings(
				array_merge(
					array(
						'browser_cache'          => 1,
						'browser_cache_html'     => 1,
						'browser_cache_html_ttl' => 3600,
					),
					$settings
				)
			)
		);

		return $browser->allow_public_html_cache( $headers, $status );
	}

	/**
	 * 审核要求的完整矩阵。
	 *
	 * @param array  $args     场景。
	 * @param bool   $expected 期望。
	 * @param string $label    场景名。
	 * @return void
	 * @dataProvider provide_gate_matrix
	 */
	public function test_gate_matrix( array $args, $expected, $label ) {
		$this->assertSame( $expected, $this->decide( $args ), $label );
	}

	/**
	 * 矩阵用例。
	 *
	 * @return array
	 */
	public static function provide_gate_matrix() {
		$html = array( 'Content-Type: text/html; charset=UTF-8' );

		return array(
			// ── 应当通过 ──
			'普通匿名首页'                => array( array( 'server' => array( 'REQUEST_URI' => '/' ) ), true, '普通匿名首页' ),
			'普通匿名文章'                => array( array(), true, '普通匿名文章' ),
			'普通 200 text/html 匿名页面' => array( array( 'headers' => $html, 'status' => 200 ), true, '普通 200 text/html 匿名页面' ),
			'HEAD 请求'                  => array( array( 'server' => array( 'REQUEST_METHOD' => 'HEAD' ) ), true, 'HEAD 请求' ),

			// ── 登录 / 个性化 Cookie ──
			'登录用户'                    => array( array( 'flags' => array( 'at8sa_test_logged_in' => true ) ), false, '登录用户' ),
			'wp-postpass'                => array( array( 'cookies' => array( 'wp-postpass_' . COOKIEHASH => 'x' ) ), false, 'wp-postpass' ),
			'comment_author'             => array( array( 'cookies' => array( 'comment_author_' . COOKIEHASH => 'x' ) ), false, 'comment_author' ),
			'WooCommerce session'        => array( array( 'cookies' => array( 'wp_woocommerce_session_' . COOKIEHASH => 'x' ) ), false, 'WooCommerce session' ),
			'woocommerce_cart_hash'      => array( array( 'cookies' => array( 'woocommerce_cart_hash' => 'x' ) ), false, 'woocommerce_cart_hash' ),
			'woocommerce_items_in_cart'  => array( array( 'cookies' => array( 'woocommerce_items_in_cart' => 'x' ) ), false, 'woocommerce_items_in_cart' ),

			// ── WooCommerce 动态页面 ──
			'购物车'                      => array( array( 'flags' => array( 'at8sa_test_is_cart' => true ) ), false, '购物车' ),
			'Checkout'                   => array( array( 'flags' => array( 'at8sa_test_is_checkout' => true ) ), false, 'Checkout' ),
			'My Account'                 => array( array( 'flags' => array( 'at8sa_test_is_account_page' => true ) ), false, 'My Account' ),
			'WC 端点页'                  => array( array( 'flags' => array( 'at8sa_test_is_wc_endpoint' => true ) ), false, 'WC 端点页' ),
			'购物车路径'                 => array( array( 'server' => array( 'REQUEST_URI' => '/cart/' ) ), false, '购物车路径' ),
			'结算路径'                   => array( array( 'server' => array( 'REQUEST_URI' => '/checkout/' ) ), false, '结算路径' ),

			// ── WordPress 明确动态页面 ──
			'Search'                     => array( array( 'flags' => array( 'at8sa_test_is_search' => true ) ), false, 'Search' ),
			'Feed'                       => array( array( 'flags' => array( 'at8sa_test_is_feed' => true ) ), false, 'Feed' ),
			'Feed 路径'                  => array( array( 'server' => array( 'REQUEST_URI' => '/feed/' ) ), false, 'Feed 路径' ),
			'404'                        => array( array( 'flags' => array( 'at8sa_test_is_404' => true ) ), false, '404' ),
			'Preview'                    => array( array( 'flags' => array( 'at8sa_test_is_preview' => true ) ), false, 'Preview' ),
			'REST'                       => array( array( 'server' => array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ) ), false, 'REST' ),
			'AJAX'                       => array( array( 'flags' => array( 'at8sa_test_doing_ajax' => true ) ), false, 'AJAX' ),
			'wp-admin'                   => array( array( 'flags' => array( 'at8sa_test_is_admin' => true ) ), false, 'wp-admin' ),
			'wp-login.php'               => array( array( 'server' => array( 'REQUEST_URI' => '/wp-login.php' ) ), false, 'wp-login.php' ),

			// ── 请求方法 ──
			'POST'                       => array( array( 'server' => array( 'REQUEST_METHOD' => 'POST' ) ), false, 'POST' ),

			// ── Query String ──
			'URL 有 Query String'        => array( array( 'server' => array( 'REQUEST_URI' => '/blog/hello/?utm_source=x' ) ), false, 'URL 有 Query String' ),
			'fbclid'                     => array( array( 'server' => array( 'REQUEST_URI' => '/blog/hello/?fbclid=abc' ) ), false, 'fbclid' ),

			// ── 状态码 ──
			'301'                        => array( array( 'status' => 301 ), false, '301' ),
			'403'                        => array( array( 'status' => 403 ), false, '403' ),
			'404 状态'                   => array( array( 'status' => 404 ), false, '404 状态' ),
			'500'                        => array( array( 'status' => 500 ), false, '500' ),

			// ── 密码保护 ──
			'password protected'         => array( array( 'flags' => array( 'at8sa_test_password_required' => true ) ), false, 'password protected' ),

			// ── 响应头 ──
			'Set-Cookie'                 => array( array( 'headers' => array_merge( $html, array( 'Set-Cookie: at8sa=1; path=/' ) ) ), false, 'Set-Cookie' ),
			'Vary: Cookie'               => array( array( 'headers' => array_merge( $html, array( 'Vary: Cookie' ) ) ), false, 'Vary: Cookie' ),
			'Cache-Control: private'     => array( array( 'headers' => array_merge( $html, array( 'Cache-Control: private, max-age=600' ) ) ), false, 'Cache-Control: private' ),
			'Cache-Control: no-store'    => array( array( 'headers' => array_merge( $html, array( 'Cache-Control: no-store' ) ) ), false, 'Cache-Control: no-store' ),
			'Cache-Control: no-cache'    => array( array( 'headers' => array_merge( $html, array( 'Cache-Control: no-cache, must-revalidate, max-age=0' ) ) ), false, 'Cache-Control: no-cache' ),
			'Pragma: no-cache'           => array( array( 'headers' => array_merge( $html, array( 'Pragma: no-cache' ) ) ), false, 'Pragma: no-cache' ),
			'Content-Type 非 HTML'       => array( array( 'headers' => array( 'Content-Type: application/json; charset=UTF-8' ) ), false, 'Content-Type 非 HTML' ),
			'缺少 Content-Type'          => array( array( 'headers' => array( 'X-Test: 1' ) ), false, '缺少 Content-Type' ),
		);
	}

	/**
	 * 开关关闭时，其它条件再完美也不发公共头。
	 *
	 * @return void
	 */
	public function test_disabled_switches_never_allow() {
		$this->assertFalse( $this->decide( array( 'settings' => array( 'browser_cache' => 0 ) ) ), 'browser_cache 关闭' );
		$this->assertFalse( $this->decide( array( 'settings' => array( 'browser_cache_html' => 0 ) ) ), 'browser_cache_html 关闭' );
		$this->assertFalse( $this->decide( array( 'settings' => array( 'browser_cache_html_ttl' => 0 ) ) ), 'TTL 为 0' );
	}

	/**
	 * 默认设置下（browser_cache_html 默认关）Gate 必须关闭。
	 *
	 * 这是"不升级也安全"的兜底：老站点升级到 3.0.6 后，除非用户显式打开，
	 * 否则行为与 3.0.5 一致（HTML 走 no-cache）。
	 *
	 * @return void
	 */
	public function test_default_settings_do_not_allow_public_html_cache() {
		$settings = $this->make_settings( array() );
		$browser  = new BrowserCache( $settings );

		$this->assertSame( 0, (int) $settings->get( 'browser_cache_html' ) );
		$this->assertFalse( $browser->allow_public_html_cache( array( 'Content-Type: text/html; charset=UTF-8' ), 200 ) );
	}

	/**
	 * Gate 与整页缓存共用同一套 Cookie 规则：整页缓存绕过的 Cookie，Gate 也必须绕过。
	 *
	 * 两边一旦漂移，就会出现"整页缓存不存、浏览器缓存头却照发 public"的半吊子结果，
	 * 而那正是审核点名的形态。
	 *
	 * @return void
	 */
	public function test_gate_reuses_page_cache_cookie_rules() {
		$rules = RequestGuard::default_bypass_cookies();

		$this->assertNotEmpty( $rules );

		foreach ( $rules as $rule ) {
			$name = '*' === substr( $rule, -1 ) ? rtrim( $rule, '*' ) . 'abc123' : $rule;

			$this->assertFalse(
				$this->decide( array( 'cookies' => array( $name => 'x' ) ) ),
				'Cookie ' . $rule . ' 必须同时被 Gate 拦截'
			);
		}
	}

	/**
	 * 用户自定义的 bypass_cookies 同样对 Gate 生效。
	 *
	 * @return void
	 */
	public function test_custom_bypass_cookie_applies_to_gate() {
		$this->assertTrue(
			$this->decide( array( 'settings' => array( 'bypass_cookies' => 'other_cookie' ), 'cookies' => array( 'unrelated' => '1' ) ) ),
			'未命中自定义规则时不应拦截'
		);

		$this->assertFalse(
			$this->decide( array( 'settings' => array( 'bypass_cookies' => 'my_personalization' ), 'cookies' => array( 'my_personalization' => '1' ) ) ),
			'自定义规则必须生效'
		);
	}

	/**
	 * 用户自定义的 exclude_urls 同样对 Gate 生效。
	 *
	 * @return void
	 */
	public function test_custom_excluded_path_applies_to_gate() {
		$this->assertFalse(
			$this->decide(
				array(
					'settings' => array( 'exclude_urls' => '/members' ),
					'server'   => array( 'REQUEST_URI' => '/members/area/' ),
				)
			),
			'自定义排除路径必须生效'
		);
	}

	/**
	 * 关闭 browser_cache_html 时，send_html_headers() 不应抛异常，且 Gate 为 false。
	 *
	 * CLI 下 `header()` 与 `headers_list()` 都是空操作，能断言的只有"不崩"——
	 * 真正的响应头断言在 `tests/unit/smoke.php` 里通过源码级检查完成。
	 *
	 * @return void
	 */
	public function test_send_html_headers_does_not_throw() {
		$this->set_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ) );

		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache' => 1, 'browser_cache_html' => 1 ) ) );
		$browser->send_html_headers();

		$off = new BrowserCache( $this->make_settings( array( 'browser_cache' => 1, 'browser_cache_html' => 0 ) ) );
		$off->send_html_headers();

		// CLI 下 `headers_list()` 恒为空，拿不到 `Content-Type: text/html`，
		// Gate 必须因此判否——这条顺带钉住"Content-Type 缺失时绝不发公共头"。
		$this->assertFalse( $browser->allow_public_html_cache() );
		$this->assertFalse( $off->allow_public_html_cache() );
	}
}

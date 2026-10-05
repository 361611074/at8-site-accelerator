<?php
/**
 * RequestGuard 单元测试。
 *
 * 这个类决定了"这个请求到底能不能被缓存"。判错方向的代价极不对称：
 * 多缓存一个登录用户 → 泄漏他人数据；少缓存一个页面 → 只是性能损失。
 * 所以用例集中在**必须放行的场景一个都不能漏**。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\RequestGuard;

/**
 * Class RequestGuardTest
 */
final class RequestGuardTest extends TestCase {

	/**
	 * 缺失的键返回回退值。
	 *
	 * @return void
	 */
	public function test_server_returns_fallback_when_missing() {
		unset( $_SERVER['HTTP_X_AT8SA_MISSING'] );

		$this->assertSame( '', RequestGuard::server( 'HTTP_X_AT8SA_MISSING' ) );
		$this->assertSame( 'GET', RequestGuard::server( 'HTTP_X_AT8SA_MISSING', 'GET' ) );
	}

	/**
	 * 非标量值返回回退值，而不是抛错或做字符串转换。
	 *
	 * `$_SERVER` 完全可能被其它代码塞进数组（例如某些代理补丁），
	 * 直接 (string) 转换会触发 "Array to string conversion" 通知并得到 "Array"。
	 *
	 * @return void
	 */
	public function test_server_rejects_non_scalar() {
		$_SERVER['HTTP_X_AT8SA_ARR'] = array( 'a' => 'b' );

		$this->assertSame( 'fallback', RequestGuard::server( 'HTTP_X_AT8SA_ARR', 'fallback' ) );
	}

	/**
	 * 控制字符与 NUL 被剥离。
	 *
	 * 为什么这条是安全用例：`REQUEST_URI` 里混入 NUL 会让下游的字符串函数提前截断，
	 * 攻击者就能用 `/wp-admin%00/..` 之类的形式绕过 `/wp-admin` 前缀匹配，
	 * 把后台页面写进共享缓存。
	 *
	 * @return void
	 */
	public function test_server_strips_control_characters() {
		$_SERVER['HTTP_X_AT8SA_DIRTY'] = "/wp-admin\x00/../public";

		$this->assertSame( '/wp-admin/../public', RequestGuard::server( 'HTTP_X_AT8SA_DIRTY' ) );
		$this->assertStringNotContainsString( "\x00", RequestGuard::server( 'HTTP_X_AT8SA_DIRTY' ) );
	}

	/**
	 * 首尾空白被去掉。
	 *
	 * 防的是 `"GET "` / `" GET"` 这类变体：不 trim 的话
	 * `'GET' !== strtoupper($method)` 判断仍成立（因为 strtoupper 不 trim），
	 * 但其它地方的相等比较会失败，出现"同一个请求两种判定"。
	 *
	 * @return void
	 */
	public function test_server_trims_whitespace() {
		$_SERVER['HTTP_X_AT8SA_WS'] = "  GET \t\n";

		$this->assertSame( 'GET', RequestGuard::server( 'HTTP_X_AT8SA_WS' ) );
	}

	/**
	 * 空串按"缺失"处理。
	 *
	 * @return void
	 */
	public function test_server_treats_empty_as_missing() {
		$_SERVER['HTTP_X_AT8SA_EMPTY'] = '   ';

		$this->assertSame( 'def', RequestGuard::server( 'HTTP_X_AT8SA_EMPTY', 'def' ) );
	}

	/**
	 * 归一化 URI 走 REQUEST_URI，并应用 ignore_query。
	 *
	 * @return void
	 */
	public function test_uri_uses_request_uri_and_ignore_query() {
		$this->set_request( array( 'REQUEST_URI' => '/p/?utm_source=x&page=2' ) );

		$this->assertSame( '/p/?page=2', RequestGuard::uri( $this->make_runtime_config() ) );

		$this->set_request( array( 'REQUEST_URI' => '/p/?keep=1&drop=2' ) );

		$this->assertSame(
			'/p/?keep=1',
			RequestGuard::uri( $this->make_runtime_config( array( 'ignore_query' => array( 'drop' ) ) ) )
		);
	}

	/**
	 * 主机名优先取 HTTP_HOST，缺失时退到 SERVER_NAME。
	 *
	 * @return void
	 */
	public function test_host_prefers_http_host_then_server_name() {
		$this->set_request( array( 'HTTP_HOST' => 'A.Example.COM' ) );
		$this->assertSame( 'a.example.com', RequestGuard::host() );

		unset( $_SERVER['HTTP_HOST'] );
		$_SERVER['SERVER_NAME'] = 'Fallback.Test';
		$this->assertSame( 'fallback.test', RequestGuard::host() );
	}

	/**
	 * 移动端 UA 识别。
	 *
	 * @param string $ua       用户代理。
	 * @param bool   $expected 期望。
	 * @return void
	 * @dataProvider provide_user_agents
	 */
	public function test_is_mobile( $ua, $expected ) {
		$this->set_request( array( 'HTTP_USER_AGENT' => $ua ) );

		$this->assertSame( $expected, RequestGuard::is_mobile() );
	}

	/**
	 * UA 用例。
	 *
	 * @return array
	 */
	public static function provide_user_agents() {
		return array(
			'桌面 Chrome'   => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120', false ),
			'空 UA'         => array( '', false ),
			'iPhone'        => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari', true ),
			'Android'       => array( 'Mozilla/5.0 (Linux; Android 14) Chrome/120', true ),
			'iPad'          => array( 'Mozilla/5.0 (iPad; CPU OS 17_0) Safari', true ),
			'Windows Phone' => array( 'Mozilla/5.0 (Windows Phone 10.0) Edge', true ),
			'大写 MOBILE'   => array( 'SOME MOBILE BROWSER', true ),
		);
	}

	/**
	 * 默认排除路径覆盖计划书 §63 要求的关键路径。
	 *
	 * 逐条断言而不是只数个数：少一条就是一个真实的缓存串页风险。
	 *
	 * @return void
	 */
	public function test_default_excluded_paths_cover_required_entries() {
		$paths = RequestGuard::default_excluded_paths();

		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/admin-ajax.php', '/cart', '/checkout', 'preview=true' ) as $required ) {
			$this->assertContains( $required, $paths, "内置排除路径缺少 {$required}" );
		}
	}

	/**
	 * 默认绕过 Cookie 覆盖密码保护、评论者、购物车会话。
	 *
	 * @return void
	 */
	public function test_default_bypass_cookies_cover_required_entries() {
		$cookies = RequestGuard::default_bypass_cookies();

		foreach ( array( 'wp-postpass_*', 'comment_author_*', 'wp_woocommerce_session_*', 'woocommerce_cart_hash' ) as $required ) {
			$this->assertContains( $required, $cookies, "内置绕过 Cookie 缺少 {$required}" );
		}
	}

	/**
 * **职责归属回归测试**：登录态 Cookie 不允许出现在本表里。
	 *
	 * 登录态只由 `has_auth_cookie()` 负责，3.0.5 起**无条件**触发、无任何开关。
	 * 一旦有人"顺手"把 `wordpress_logged_in_*` 加回本表，两条路径就会重复，
	 * 更糟的是会让 `default_bypass_cookies()` 这个公开方法的语义变得含糊不清
	 * （它到底是"放行规则"还是"身份检测"？）。
	 *
	 * @return void
	 */
	public function test_auth_cookies_are_not_in_bypass_list() {
		$cookies = RequestGuard::default_bypass_cookies();

		foreach ( $cookies as $rule ) {
			$this->assertStringNotContainsString(
				'wordpress_logged_in',
				$rule,
				'登录态 Cookie 不应出现在 bypass 表里：身份检测只走 has_auth_cookie()'
			);
			$this->assertStringNotContainsString( 'wordpress_sec', $rule );
		}
	}

	/**
	 * 前缀规则必须写成 `前缀*`，裸前缀是无效规则。
	 *
	 * 这条是"防回归的元测试"：只要表里出现以 `_` 结尾却不带 `*` 的规则，
	 * 它实际上永远不会匹配任何真实 Cookie（真实名字都带 <COOKIEHASH> 后缀）。
	 *
	 * @return void
	 */
	public function test_prefix_rules_must_end_with_star() {
		$cookies = RequestGuard::default_bypass_cookies();
		$bad     = array();

		foreach ( $cookies as $rule ) {
			// 用 substr 而不是 str_ends_with()：后者是 PHP 8.0+ 才有的函数，
			// 而本插件的下限是 7.4，测试代码也不能用。
			if ( '_' === substr( $rule, -1 ) ) {
				$bad[] = $rule;
			}
		}

		$this->assertSame(
			array(),
			$bad,
			'这些规则以 `_` 结尾却不带 `*`，永远匹配不上真实 Cookie（真实名都带 <COOKIEHASH> 后缀）'
		);
	}

	/**
	 * 普通 GET 请求允许缓存。
	 *
	 * 这是"对照组"：如果它都放行了，说明整条判断链坏掉了。
	 *
	 * @return void
	 */
	public function test_plain_get_is_cacheable() {
		$this->set_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ) );
		$this->set_cookies( array() );

		$this->assertFalse( RequestGuard::should_bypass( $this->make_runtime_config() ) );
	}

	/**
	 * 必须放行的场景矩阵。
	 *
	 * @param array  $config 运行时配置覆盖项。
	 * @param array  $server 超全局覆盖项。
	 * @param array  $cookie 请求 Cookie。
	 * @param string $label  说明。
	 * @return void
	 * @dataProvider provide_bypass_cases
	 */
	public function test_should_bypass_matrix( array $config, array $server, array $cookie, $label ) {
		$this->set_request( $server );
		$this->set_cookies( $cookie );

		$this->assertTrue(
			RequestGuard::should_bypass( $this->make_runtime_config( $config ) ),
			"必须放行但没放行：{$label}"
		);
	}

	/**
	 * 放行用例矩阵。
	 *
	 * @return array
	 */
	public static function provide_bypass_cases() {
		$get_root = array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' );

		return array(
			'插件总开关关闭' => array(
				array( 'enabled' => 0 ),
				$get_root,
				array(),
				'enabled=0',
			),
			'安全模式' => array(
				array( 'safe_mode' => 1 ),
				$get_root,
				array(),
				'safe_mode=1',
			),
			'POST 请求' => array(
				array(),
				array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/' ),
				array(),
				'POST',
			),
			'PUT 请求' => array(
				array(),
				array( 'REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/' ),
				array(),
				'PUT',
			),
			'后台路径' => array(
				array(),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-admin/options.php' ),
				array(),
				'/wp-admin',
			),
			'登录页' => array(
				array(),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-login.php' ),
				array(),
				'/wp-login.php',
			),
			'搜索页' => array(
				array(),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?s=keyword' ),
				array(),
				'?s=',
			),
			'预览参数' => array(
				array(),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/post/?preview=true' ),
				array(),
				'preview=true',
			),
			'登录 Cookie（含 hash）' => array(
				array(),
				$get_root,
				array( 'wordpress_logged_in_' . COOKIEHASH => 'x' ),
				'登录态',
			),
			'登录 Cookie（仅前缀）' => array(
				array(),
				$get_root,
				array( 'wordpress_logged_in_otherhash' => 'x' ),
				'登录态（换站盐）',
			),
			'密码保护 Cookie' => array(
				array(),
				$get_root,
				array( 'wp-postpass_' . COOKIEHASH => 'x' ),
				'密码保护',
			),
			'购物车 Cookie' => array(
				array(),
				$get_root,
				array( 'woocommerce_cart_hash' => 'x' ),
				'购物车',
			),
			'自定义排除路径' => array(
				array( 'excluded_paths' => array( '/secret' ) ),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/secret/page' ),
				array(),
				'自定义排除路径',
			),
			'NUL 注入不绕过前缀匹配' => array(
				array(),
				array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/safe\x00/wp-admin" ),
				array(),
				'NUL 注入',
			),
		);
	}

	/**
	 * 登录态必须**无条件**绕过，配置里写什么都不例外。
	 *
	 * 3.0.5 之前这里有一个 `cache_logged_in` 开关，打开后登录态不再放行。
	 * 那个开关已被彻底移除，因为缓存键只有「站点盐 + host + URI + 移动标记」，
	 * **没有任何用户维度** —— 一旦共享，用户 A 的页面就会被返回给用户 B。
	 *
	 * 关键在于「无条件」：老站点的 `at8sa_settings` 里可能还存着
	 * `cache_logged_in => 1`，如果哪天有人"顺手"把这个配置读回来，
	 * 越权就会静默复活。所以这里显式传 `1` 进去，断言**依然**绕过 ——
	 * 让"这个键彻底失效"成为被测试钉死的事实，而不是靠注释提醒。
	 *
	 * @return void
	 */
	public function test_logged_in_bypasses_even_when_config_says_otherwise() {
		$this->set_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ) );
		$this->set_cookies( array( 'wordpress_logged_in_' . COOKIEHASH => 'x' ) );

		// 传 `cache_logged_in => 1`：模拟老站库里残留的值。
		$this->assertTrue(
			RequestGuard::should_bypass( $this->make_runtime_config( array( 'cache_logged_in' => 1 ) ) ),
			'cache_logged_in 必须完全失效：即使配置为 1，登录态也必须绕过缓存'
		);

		// 传 `false` / 不传：同样绕过。
		$this->assertTrue( RequestGuard::should_bypass( $this->make_runtime_config() ) );
		$this->assertTrue( RequestGuard::should_bypass( $this->make_runtime_config( array( 'cache_logged_in' => 0 ) ) ) );
	}

	/**
	 * 自定义 bypass_cookies 会**替换**内置表，而不是追加。
	 *
	 * 这是刻意设计：站长想只按自己的规则放行时可以完全接管。
	 * 断言这一点是为了防止将来有人"顺手改成 merge"，那会静默改变行为。
	 *
	 * @return void
	 */
	public function test_custom_bypass_cookies_replace_defaults() {
		$this->set_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ) );
		$this->set_cookies( array( 'my_custom_cookie' => '1' ) );

		$this->assertTrue(
			RequestGuard::should_bypass(
				$this->make_runtime_config( array( 'bypass_cookies' => array( 'my_custom_cookie' ) ) )
			)
		);

		// 内置的购物车 Cookie 此时不再生效（已被替换掉）。
		$this->set_cookies( array( 'woocommerce_cart_hash' => '1' ) );

		$this->assertFalse(
			RequestGuard::should_bypass(
				$this->make_runtime_config( array( 'bypass_cookies' => array( 'my_custom_cookie' ) ) )
			)
		);
	}

	/**
	 * 排除路径为空串时不应误伤所有请求。
	 *
	 * 空串会被 stripos 匹配到任何位置（`stripos($uri, '')` 返回 0），
	 * 若不跳过，站长在设置里留一个空行就会导致整站不再缓存。
	 *
	 * @return void
	 */
	public function test_empty_excluded_path_does_not_disable_cache() {
		$this->set_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ) );
		$this->set_cookies( array() );

		$this->assertFalse(
			RequestGuard::should_bypass( $this->make_runtime_config( array( 'excluded_paths' => array( '' ) ) ) )
		);
	}
}

<?php
/**
 * HTML 公共浏览器缓存「已整体移除」回归测试。
 *
 * 对应 WordPress.org 第二轮人工审核（P1-02 终裁）：
 *
 * 3.0.6 的方案是 `send_headers` 钩子上的"公共响应资格 Gate"（十五道检查）。
 * 官方否决了这个方案：Gate 只能看见**发送头那一刻**的响应状态，主题/插件
 * 之后才添加的 `Set-Cookie`、`Cache-Control: private/no-store`、`Vary: Cookie`
 * 或才定义的 `DONOTCACHEPAGE` 它都看不见——正确性无法保证。
 *
 * 因此 Free 版（3.0.6.3 起）**不再为 HTML 响应主动发送任何公共缓存头**：
 * 发送路径整体移除，历史数据库里的 `browser_cache_html=1` 被运行时忽略。
 * 本文件把"移除"本身钉死为不变式，防止未来任何提交把路径悄悄加回来；
 * 同时钉住 v5 要求的另一半：静态资源缓存规则与整页缓存的绕过机制**必须原样保留**。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\RequestGuard;
use AT8SA\Optimization\BrowserCache;

/**
 * Class HtmlPublicCacheRemovedTest
 */
final class HtmlPublicCacheRemovedTest extends TestCase {

	/**
	 * 生产代码目录下需要扫描的 PHP 文件清单（入口 + includes 全量）。
	 *
	 * @return array<string,string> 相对路径 => 源码。
	 */
	private function production_sources() {
		$base   = dirname( __DIR__, 2 ) . '/';
		$files  = array( 'at8-site-accelerator.php', 'uninstall.php' );
		$ources = array();

		foreach ( $files as $file ) {
			$ources[ $file ] = (string) file_get_contents( $base . $file );
		}

		$dirs = array( 'includes', 'templates' );

		foreach ( $dirs as $dir ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base . $dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $spl ) {
				if ( $spl->isFile() && 'php' === strtolower( $spl->getExtension() ) ) {
					$ources[ $dir . '/' . $spl->getBasename() ] = (string) file_get_contents( $spl->getPathname() );
				}
			}
		}

		return $ources;
	}

	/**
	 * 发送路径必须从源码层移除：Gate、钩子注册、public/Expires 的 header() 调用。
	 *
	 * @return void
	 */
	public function test_html_public_cache_code_path_is_removed() {
		$methods = array( 'send_html_headers', 'allow_public_html_cache' );

		foreach ( $methods as $method ) {
			$this->assertFalse(
				method_exists( BrowserCache::class, $method ),
				'BrowserCache::' . $method . '() 必须不存在（公共 HTML 缓存路径已移除）'
			);
		}

		// 整个插件不得再向 send_headers 注册任何回调——那是被否决方案的入口。
		foreach ( $this->production_sources() as $name => $source ) {
			$this->assertStringNotContainsString(
				"add_action( 'send_headers'",
				$source,
				$name . ' 不得注册 send_headers 钩子'
			);

			// PHP 层不得主动发送 HTML 公共缓存头；Expires 同理。
			// （静态资源的 Expires 出现在生成的 .htaccess 规则字符串里，不经 header()。）
			$this->assertDoesNotMatchRegularExpression(
				"/header\\(\\s*['\"]Cache-Control:\\s*public/i",
				$source,
				$name . ' 不得调用 header() 发送 Cache-Control: public'
			);
			$this->assertDoesNotMatchRegularExpression(
				"/header\\(\\s*['\"]Expires:/i",
				$source,
				$name . ' 不得调用 header() 发送 Expires'
			);
		}
	}

	/**
	 * v5 测试要求 1：即使旧数据库里 browser_cache_html = 1，也不会输出 public。
	 *
	 * 双保险：运行时 boot() 不挂任何钩子（do_action 派发不到任何东西），
	 * 且旧值虽然仍能从设置里读出（保留历史数据），但已无任何读取方。
	 *
	 * @return void
	 */
	public function test_legacy_db_value_cannot_reenable_public_html_cache() {
		$settings = $this->make_settings(
			array(
				'browser_cache'          => 1,
				'browser_cache_html'     => 1,
				'browser_cache_html_ttl' => 3600,
			)
		);

		// 旧值确实还在（v5 允许保留历史数据库值）。
		$this->assertSame( 1, (int) $settings->get( 'browser_cache_html' ) );

		$browser = new BrowserCache( $settings );
		$browser->boot();

		$this->assertFalse(
			has_action( 'send_headers' ),
			'browser_cache_html=1 时也绝不允许挂 send_headers 钩子'
		);
		$this->assertFalse(
			method_exists( $browser, 'send_html_headers' ),
			'发送方法必须不存在'
		);

		// 派发钩子应是空操作：没有任何回调会被触发。
		do_action( 'send_headers' );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * v5 测试要求 2：AT8 不会生成 HTML 长缓存的 Expires 头。
	 *
	 * 源码层已由 test_html_public_cache_code_path_is_removed 锁死 header() 调用；
	 * 这里再钉住设置页：HTML 缓存时长控件必须从模板中移除。
	 *
	 * @return void
	 */
	public function test_settings_page_no_longer_offers_html_cache_controls() {
		$template = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/templates/settings-page.php'
		);

		$this->assertStringNotContainsString(
			'browser_cache_html',
			$template,
			'设置页不得再渲染 browser_cache_html 开关（不允许"可开启但无效"的控件）'
		);
		$this->assertStringNotContainsString(
			'browser_cache_html_ttl',
			$template,
			'设置页不得再渲染 HTML 缓存时长输入框'
		);

		// 静态资源开关必须还在——v5 要求静态资源缓存继续可用。
		$this->assertStringContainsString( 'browser_cache', $template );
		$this->assertStringContainsString( 'browser_cache_ttl', $template );
	}

	/**
	 * v5 测试要求 3：CSS / JS / 图片 / 字体的静态资源规则仍正常生成。
	 *
	 * @return void
	 */
	public function test_static_asset_rules_are_still_generated() {
		$browser = new BrowserCache( $this->make_settings( array( 'browser_cache' => 1 ) ) );

		$nginx  = $browser->nginx_rules();
		$apache = $browser->apache_rules();

		// nginx 片段：静态资源长缓存 + HTML 显式 no-cache。
		$this->assertStringContainsString( 'expires', strtolower( $nginx ), 'nginx 片段应包含 expires 规则' );
		$this->assertStringContainsString( 'css|js', $nginx, 'nginx 片段应覆盖 CSS/JS 扩展名' );
		$this->assertStringContainsString( 'no-cache, must-revalidate', $nginx, 'nginx 片段应把 HTML 显式声明为 no-cache' );

		$this->assertStringContainsString( 'ExpiresActive', $apache, 'Apache 片段应启用 Expires' );
		$this->assertStringContainsString( 'ExpiresByType text/css', $apache, 'Apache 片段应覆盖 CSS' );
		$this->assertStringContainsString( 'ExpiresByType image/webp', $apache, 'Apache 片段应覆盖 WebP' );
		$this->assertStringContainsString( 'ExpiresByType text/html "access plus 0 seconds"', $apache, 'Apache 片段应把 HTML 明确归零' );

		$this->assertGreaterThan( 0, $browser->asset_ttl(), '静态资源缓存时长必须为正' );
	}

	/**
	 * v5 测试要求 5（规则层）：登录、密码保护、WooCommerce 的整页缓存绕过机制原样保留。
	 *
	 * `CacheEngine` 的绕过判定与浏览器缓存曾共用 `RequestGuard` 的规则；
	 * 路径排除表与 Cookie 绕过表就是那份共享事实，这里钉住它们不被本次
	 * 移除动作误伤。行为级验证由 smoke / round2 的整页缓存用例继续承担。
	 *
	 * @return void
	 */
	public function test_page_cache_bypass_rules_survive() {
		$cookie_hash = defined( 'COOKIEHASH' ) ? COOKIEHASH : 'abc123';

		// 与 CacheEngine 实际使用的规则同源：默认表 + 用户自定义。
		$rules = RequestGuard::merge_rules( RequestGuard::default_bypass_cookies(), null );

		$this->assertTrue(
			RequestGuard::has_bypass_cookie( $rules, array( 'wp-postpass_' . $cookie_hash => 'x' ) ),
			'密码保护 Cookie 必须绕过整页缓存'
		);
		$this->assertTrue(
			RequestGuard::has_bypass_cookie( $rules, array( 'wp_woocommerce_session_' . $cookie_hash => 'x' ) ),
			'WooCommerce 会话 Cookie 必须绕过整页缓存'
		);
		$this->assertTrue(
			RequestGuard::has_bypass_cookie( $rules, array( 'woocommerce_items_in_cart' => '1' ) ),
			'购物车 Cookie 必须绕过整页缓存'
		);
		$this->assertFalse(
			RequestGuard::has_bypass_cookie( $rules, array( 'theme_pref' => 'dark' ) ),
			'无关 Cookie 不应触发绕过'
		);
	}
}

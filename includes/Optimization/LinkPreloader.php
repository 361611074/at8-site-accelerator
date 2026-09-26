<?php
/**
 * 前端智能预加载（从 2.x 的 AT8_Preloader 迁移）。
 *
 * 需要明确区分两个概念，这是 2.x 文档里没说清、容易误导用户的地方：
 *
 * - **本模块 = 链接预取（link prefetch）**：访客鼠标悬停/触摸链接时，提前把目标页
 *   拉进浏览器缓存。它改善的是"访客点下去之后的体感"，**不会**让服务端产生缓存。
 * - **缓存预热（preload）**：由服务端按 sitemap 批量请求页面、把整页缓存填满。
 *   那是 Pro 阶段的能力（计划书 §65），Free 版不含。
 *
 * 后台文案已按此区分，避免用户误以为开了这个就等于"缓存已预热"。
 *
 * @package AT8\SiteAccelerator\Optimization
 */

namespace AT8\SiteAccelerator\Optimization;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class LinkPreloader
 */
final class LinkPreloader {

	/**
	 * 脚本句柄。
	 */
	const HANDLE = 'at8sa-preloader';

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_head', array( $this, 'output_dns_prefetch' ), 0 );
		add_action( 'wp_head', array( $this, 'output_preconnect' ), 1 );
		add_action( 'wp_head', array( $this, 'output_resource_preload' ), 2 );
	}

	/**
	 * 入队预加载脚本。
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->settings->is_on( 'preload_enable' ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		// Elementor 编辑器/预览环境不预取，避免干扰。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor'] ) ) {
			return;
		}

		$path = AT8SA_PATH . 'assets/js/preloader.js';

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, AT8SA_URL . 'assets/js/preloader.js', array(), AT8SA_VERSION, true );
		wp_localize_script( self::HANDLE, 'AT8SA_Preloader', $this->config() );
	}

	/**
	 * 脚本配置。
	 *
	 * @return array
	 */
	private function config() {
		$settings = $this->settings;
		$strategy = $settings->get( 'preload_strategy', array( 'prefetch' ) );

		$config = array(
			'debug'    => (bool) $settings->is_on( 'preload_debug' ),
			'security' => array(
				'dangerousProtocols'  => array( 'javascript:', 'data:', 'vbscript:', 'file:', 'about:', 'chrome:', 'edge:', 'opera:' ),
				'sensitivePaths'      => array( '/logout', '/wp-login', '/wp-admin', '/cart', '/checkout', '/my-account', 'delete', 'remove', 'trash', 'add-to-cart' ),
				'dangerousExtensions' => array( '.php', '.asp', '.aspx', '.jsp', '.cgi', '.exe', '.dll', '.sh', '.sql' ),
				'maxPreloads'         => (int) $settings->get( 'max_preloads', 20 ),
				'maxPerDomain'        => (int) $settings->get( 'max_per_domain', 10 ),
			),
			'speed'    => array(
				'hoverDelay'        => (int) $settings->get( 'hover_delay', 50 ),
				'touchDelay'        => (int) $settings->get( 'touch_delay', 100 ),
				'useIdleCallback'   => true,
				'preloadInViewport' => (bool) $settings->is_on( 'preload_viewport' ),
				'preloadStrategy'   => is_array( $strategy ) ? array_values( $strategy ) : array( 'prefetch' ),
				'cacheTime'         => (int) $settings->get( 'preload_cooldown', 300 ),
			),
		);

		// 页面类型微调。
		if ( function_exists( 'is_product' ) && is_product() ) {
			$config['speed']['hoverDelay']      = max( 10, (int) ( $config['speed']['hoverDelay'] / 2 ) );
			$config['speed']['preloadStrategy'] = array( 'prefetch' );
		}

		if ( is_front_page() ) {
			$config['security']['maxPreloads'] = max( $config['security']['maxPreloads'], 30 );
		}

		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			$config['speed']['preloadInViewport'] = false;
		}

		/**
		 * 过滤预加载脚本配置。
		 *
		 * @param array $config 配置。
		 */
		return apply_filters( 'at8sa_preloader_config', $config );
	}

	/**
	 * 预加载 preloader.js 自身。
	 *
	 * 2.x 的开关叫 `http2_push`，实现是 `Link: <…>; rel=preload` ——
	 * 那是**资源预加载**，不是 HTTP/2 Server Push（后者已被主流浏览器移除）。
	 * 3.0 沿用这个能力但把名字改成 `resource_preload`，免得用户以为开了个已失效的功能。
	 *
	 * 为什么改成在 `<head>` 里输出 `<link>` 标签，而不是发 HTTP 头：
	 * 本插件的整页缓存命中时，请求由 `advanced-cache.php` 直接吐字节并 exit，
	 * PHP 根本没有机会再发任何响应头。放在响应头里的优化会在命中路径上**全部丢失**。
	 * 写进 HTML 则跟着缓存一起存下来，命中时依然生效。
	 *
	 * @return void
	 */
	public function output_resource_preload() {
		if ( ! $this->settings->is_on( 'preload_enable' ) || ! $this->settings->is_on( 'resource_preload' ) ) {
			return;
		}

		// 只预加载真正入队了的脚本。elementor-preview 等场景 enqueue() 会提前返回，
		// 这时预加载一个不会被用到的文件纯属浪费带宽。
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		$url = add_query_arg( 'ver', AT8SA_VERSION, AT8SA_URL . 'assets/js/preloader.js' );

		echo '<link rel="preload" href="' . esc_url( $url ) . '" as="script">' . "\n";
	}

	/**
	 * 输出 DNS 预取与预连接。
	 *
	 * @return void
	 */
	public function output_dns_prefetch() {
		if ( ! $this->settings->is_on( 'dns_prefetch' ) ) {
			return;
		}

		$site = home_url();

		echo '<meta http-equiv="x-dns-prefetch-control" content="on">' . "\n";
		echo '<link rel="dns-prefetch" href="' . esc_url( $site ) . '">' . "\n";
		echo '<link rel="preconnect" href="' . esc_url( $site ) . '" crossorigin>' . "\n";
	}

	/**
	 * 输出用户配置的第三方预连接域名。
	 *
	 * @return void
	 */
	public function output_preconnect() {
		$hosts = trim( (string) $this->settings->get( 'preconnect_hosts', '' ) );

		if ( '' === $hosts ) {
			return;
		}

		foreach ( preg_split( '/\r?\n|,/', $hosts ) as $host ) {
			$host = trim( $host );

			if ( '' === $host ) {
				continue;
			}

			$host = preg_replace( '#^https?://#', '', $host );
			$host = rtrim( (string) $host, '/' );

			if ( '' === $host ) {
				continue;
			}

			echo '<link rel="preconnect" href="https://' . esc_attr( $host ) . '" crossorigin>' . "\n";
			echo '<link rel="dns-prefetch" href="https://' . esc_attr( $host ) . '">' . "\n";
		}
	}
}

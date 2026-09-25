<?php
/**
 * 前端资源精简与后台瘦身（从 2.x 的 SA_Cleanup 迁移，逐项开关保持不变）。
 *
 * 这些开关的共同特征是"收益确定、风险低、可一键回退"，属于免费版最划算的部分。
 * 每一项都严格挂在 WordPress 官方钩子上，不使用任何字符串替换 HTML 的 hack。
 *
 * @package AT8\SiteAccelerator\Optimization
 */

namespace AT8\SiteAccelerator\Optimization;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class FrontendCleanup
 */
final class FrontendCleanup {

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
		$this->boot_frontend();
		$this->boot_admin();
	}

	/**
	 * 前端相关。
	 *
	 * @return void
	 */
	private function boot_frontend() {
		if ( $this->settings->is_on( 'disable_emoji' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}

		if ( $this->settings->is_on( 'disable_embeds' ) ) {
			add_action( 'init', array( $this, 'disable_embeds' ), 999 );
		}

		if ( $this->settings->is_on( 'remove_wp_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_action( 'wp_head', array( $this, 'start_generator_buffer' ), 0 );
			add_action( 'wp_head', array( $this, 'end_generator_buffer' ), 9999 );
		}

		if ( $this->settings->is_on( 'disable_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'disable_jquery_migrate' ), 999 );
		}

		if ( $this->settings->is_on( 'disable_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_dashicons' ), 99 );
		}

		if ( $this->settings->is_on( 'remove_query_strings' ) ) {
			add_filter( 'style_loader_src', array( $this, 'remove_query_string' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'remove_query_string' ), 9999 );
		}

		if ( $this->settings->is_on( 'disable_block_css' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_block_css' ), 99 );
		}

		$heartbeat = (string) $this->settings->get( 'heartbeat', 'reduce' );

		if ( 'reduce' === $heartbeat ) {
			add_filter( 'heartbeat_settings', array( $this, 'heartbeat_reduce' ) );
		} elseif ( 'disable' === $heartbeat ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'heartbeat_disable_front' ), 99 );
			add_action( 'admin_enqueue_scripts', array( $this, 'heartbeat_disable_admin' ), 99 );
		}
	}

	/**
	 * 后台相关。
	 *
	 * @return void
	 */
	private function boot_admin() {
		if ( ! is_admin() ) {
			return;
		}

		if ( $this->settings->is_on( 'remove_site_health' ) || $this->settings->is_on( 'remove_events_news' ) ) {
			add_action( 'wp_dashboard_setup', array( $this, 'remove_dashboard_widgets' ), 99 );
		}

		if ( $this->settings->is_on( 'remove_site_health' ) ) {
			add_action( 'admin_menu', array( $this, 'remove_site_health_menu' ), 99 );
		}

		if ( $this->settings->is_on( 'disable_version_checks' ) ) {
			add_filter( 'pre_site_transient_php_check', '__return_true' );
			add_action( 'wp_dashboard_setup', array( $this, 'remove_version_nags' ), 99 );
		}

		if ( $this->settings->is_on( 'disable_large_thumbs' ) ) {
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'disable_thumb_sizes' ), 999 );
			add_filter( 'intermediate_image_sizes', array( $this, 'disable_thumb_sizes_ui' ), 999 );
		}
	}

	/**
	 * 禁用 wp-embed。
	 *
	 * @return void
	 */
	public function disable_embeds() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
		add_filter( 'pre_oembed_result', '__return_null', 10 );
		wp_deregister_script( 'wp-embed' );
	}

	/**
	 * 移除 jQuery Migrate 依赖。
	 *
	 * @param \WP_Scripts $scripts 脚本注册表。
	 * @return void
	 */
	public function disable_jquery_migrate( $scripts ) {
		if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
			return;
		}

		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			array( 'jquery-migrate' )
		);
	}

	/**
	 * 前台禁用 Dashicons（非登录用户）。
	 *
	 * @return void
	 */
	public function disable_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * 移除静态资源 ?ver=。
	 *
	 * @param string $src 资源 URL。
	 * @return string
	 */
	public function remove_query_string( $src ) {
		if ( false !== strpos( $src, '?ver=' ) || false !== strpos( $src, '&ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	/**
	 * 前台禁用区块样式。
	 *
	 * @return void
	 */
	public function disable_block_css() {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
	}

	/**
	 * 降低 Heartbeat 频率。
	 *
	 * @param array $settings 心跳设置。
	 * @return array
	 */
	public function heartbeat_reduce( $settings ) {
		$settings['interval'] = 60;

		return $settings;
	}

	/**
	 * 前台禁用 Heartbeat。
	 *
	 * @return void
	 */
	public function heartbeat_disable_front() {
		if ( ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * 后台禁用 Heartbeat。
	 *
	 * @return void
	 */
	public function heartbeat_disable_admin() {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * 移除后台仪表盘小组件。
	 *
	 * @return void
	 */
	public function remove_dashboard_widgets() {
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_browser_nag', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_php_nag', 'dashboard', 'normal' );
	}

	/**
	 * 移除「站点健康」菜单。
	 *
	 * @return void
	 */
	public function remove_site_health_menu() {
		remove_submenu_page( 'tools.php', 'site-health' );
	}

	/**
	 * 移除版本过旧提示。
	 *
	 * @return void
	 */
	public function remove_version_nags() {
		remove_meta_box( 'dashboard_browser_nag', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_php_nag', 'dashboard', 'normal' );
		remove_action( 'dashboard_browser_nag', 'wp_dashboard_browser_nag' );
		remove_action( 'dashboard_php_nag', 'wp_dashboard_php_nag' );
	}

	/**
	 * 禁止生成超大缩略图。
	 *
	 * @param array $sizes 尺寸表。
	 * @return array
	 */
	public function disable_thumb_sizes( $sizes ) {
		unset( $sizes['medium_large'], $sizes['1536x1536'], $sizes['2048x2048'] );

		return $sizes;
	}

	/**
	 * 从后台尺寸选择器里同步移除。
	 *
	 * @param array $sizes 尺寸表。
	 * @return array
	 */
	public function disable_thumb_sizes_ui( $sizes ) {
		return array_diff( $sizes, array( 'medium_large', '1536x1536', '2048x2048' ) );
	}

	/**
	 * 开始捕获 wp_head 输出，用于统一剔除 generator 元标签。
	 *
	 * @return void
	 */
	public function start_generator_buffer() {
		if ( is_admin() ) {
			return;
		}

		ob_start();
	}

	/**
	 * 结束捕获并剔除 generator 标签。
	 *
	 * @return void
	 */
	public function end_generator_buffer() {
		if ( is_admin() ) {
			return;
		}

		$html = ob_get_clean();

		if ( false === $html ) {
			return;
		}

		$html = preg_replace( '/<meta[^>]+name=["\']generator["\'][^>]*>\s*/i', '', $html );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_head 原始输出，此处仅做正则剔除。
	}
}

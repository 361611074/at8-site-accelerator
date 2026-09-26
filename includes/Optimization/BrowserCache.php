<?php
/**
 * 浏览器缓存（HTTP 缓存头）。
 *
 * 这里有一个**必须说清楚的边界**：
 * PHP 只能给"经过 PHP 的响应"（也就是 HTML 文档）加缓存头。CSS/JS/图片由 Web 服务器
 * 直接返回，PHP 根本没机会插手。想给它们加长缓存，只能写服务器规则。
 *
 * 因此本模块分两半：
 * 1. HTML 响应头 —— PHP 直接设置（有效）；
 * 2. 静态资源规则 —— 生成 nginx / Apache 片段供用户**自行**启用。
 *
 * 计划书 §125 明确"不要自动覆盖用户已有规则"，所以本模块**绝不自动写 .htaccess**，
 * 只提供可复制的片段；Apache 用户可以在后台显式点按钮写入带标记的独立块。
 *
 * ⚠️ 组合风险：`remove_query_strings`（移除 ?ver=）与"静态资源长缓存"同时开启时，
 * 主题/插件更新后浏览器仍会使用旧的 CSS/JS，且因为 URL 没变而无法自动失效。
 * 后台会就此给出明确警告，详见 docs/COMPATIBILITY.md。
 *
 * @package AT8\SiteAccelerator\Optimization
 */

namespace AT8\SiteAccelerator\Optimization;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class BrowserCache
 */
final class BrowserCache {

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
		if ( ! $this->settings->is_on( 'browser_cache' ) ) {
			return;
		}

		add_action( 'send_headers', array( $this, 'send_html_headers' ) );
	}

	/**
	 * 为 HTML 响应设置缓存头。
	 *
	 * @return void
	 */
	public function send_html_headers() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) {
			return;
		}

		// 登录用户、购物车等个性化页面绝不缓存。
		if ( is_user_logged_in() ) {
			return;
		}

		if ( $this->settings->is_on( 'browser_cache_html' ) ) {
			$ttl = max( 0, (int) $this->settings->get( 'browser_cache_html_ttl', 3600 ) );

			if ( $ttl > 0 ) {
				header( 'Cache-Control: public, max-age=' . $ttl );
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
			}

			return;
		}

		// 默认：HTML 不做浏览器长缓存，交给本插件的整页缓存去控制新鲜度。
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
	}

	/**
	 * 静态资源缓存时长（秒）。
	 *
	 * @return int
	 */
	public function asset_ttl() {
		return max( 3600, (int) $this->settings->get( 'browser_cache_ttl', 31536000 ) );
	}

	/**
	 * nginx 规则片段。
	 *
	 * @return string
	 */
	public function nginx_rules() {
		$ttl = $this->asset_ttl();

		$rules  = "# ==== AT8 Site Accelerator: 静态资源浏览器缓存 ====\n";
		$rules .= "# 把本段放入站点的 server { } 块内（或 include 本文件）。\n";
		$rules .= "# 若你已有 location ~* \\.(css|js|...)$ 规则，请合并，不要重复声明。\n\n";
		$rules .= "location ~* \\.(?:css|js|mjs|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico|mp4|webm|pdf)$ {\n";
		$rules .= "    expires {$ttl}s;\n";
		$rules .= "    add_header Cache-Control \"public, max-age={$ttl}, immutable\";\n";
		$rules .= "    access_log off;\n";
		$rules .= "}\n\n";
		$rules .= "# HTML 不做长缓存（由插件整页缓存控制新鲜度）\n";
		$rules .= "location ~* \\.(?:html|php)$ {\n";
		$rules .= "    add_header Cache-Control \"no-cache, must-revalidate\";\n";
		$rules .= "}\n";

		return $rules;
	}

	/**
	 * Apache .htaccess 规则片段。
	 *
	 * @return string
	 */
	public function apache_rules() {
		$ttl   = $this->asset_ttl();
		$years = max( 1, (int) round( $ttl / 31536000 ) );

		$rules  = "# ==== BEGIN AT8 Site Accelerator ====\n";
		$rules .= "<IfModule mod_expires.c>\n";
		$rules .= "    ExpiresActive On\n";
		$rules .= "    ExpiresByType text/css \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType application/javascript \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/webp \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/avif \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/jpeg \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/png \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/svg+xml \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType font/woff2 \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType text/html \"access plus 0 seconds\"\n";
		$rules .= "</IfModule>\n";
		$rules .= "# ==== END AT8 Site Accelerator ====\n";

		return $rules;
	}

	/**
	 * 是否已写入 .htaccess 标记块。
	 *
	 * @return bool
	 */
	public function htaccess_has_rules() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		$content = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		return false !== strpos( $content, '# ==== BEGIN AT8 Site Accelerator ====' );
	}

	/**
	 * 显式写入 .htaccess 标记块（仅用户主动触发时调用）。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function write_htaccess() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return array(
				'ok'      => false,
				'message' => __( '未检测到可写的 .htaccess，请手动把规则片段粘贴到你的服务器配置中。', 'at8-site-accelerator' ),
			);
		}

		$original = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== strpos( $original, '# ==== BEGIN AT8 Site Accelerator ====' ) ) {
			return array(
				'ok'      => true,
				'message' => __( '规则已存在，无需重复写入。', 'at8-site-accelerator' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $file . '.at8sa.bak', $original ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '备份 .htaccess 失败，已中止（安全优先）。', 'at8-site-accelerator' ),
			);
		}

		$updated = rtrim( $original ) . "\n\n" . $this->apache_rules();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $file, $updated ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '写入 .htaccess 失败。', 'at8-site-accelerator' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( '规则已追加到 .htaccess 末尾，原文件已备份为 .htaccess.at8sa.bak。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 移除 .htaccess 标记块。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function remove_htaccess() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return array(
				'ok'      => false,
				'message' => __( '.htaccess 不可写。', 'at8-site-accelerator' ),
			);
		}

		$content = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		$updated = preg_replace(
			'/\n*# ==== BEGIN AT8 Site Accelerator ====.*?# ==== END AT8 Site Accelerator ====\n?/s',
			"\n",
			$content
		);

		if ( ! is_string( $updated ) || $updated === $content ) {
			return array(
				'ok'      => false,
				'message' => __( '未找到本插件写入的规则块。', 'at8-site-accelerator' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $updated ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'ok'      => true,
			'message' => __( '规则块已移除。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * .htaccess 路径。
	 *
	 * @return string
	 */
	private function htaccess_path() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * 服务器类型（用于后台给出对应建议）。
	 *
	 * @return string nginx|apache|litespeed|unknown
	 */
	public function server_type() {
		// 这个值只用于 strpos 判断，返回值是下面四个固定常量之一，
		// 不会被回显、不会拼进 SQL / 路径，所以无需额外净化。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';

		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}

		return 'unknown';
	}

	/**
	 * 是否存在"移除 ?ver= + 长缓存"的危险组合。
	 *
	 * @return bool
	 */
	public function has_risky_combination() {
		return $this->settings->is_on( 'browser_cache' )
			&& $this->settings->is_on( 'remove_query_strings' )
			&& $this->asset_ttl() > 604800;
	}
}

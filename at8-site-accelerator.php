<?php
/**
 * Plugin Name:       AT8 Site Accelerator
 * Plugin URI:        https://www.at8.fun/
 * Description:       轻量级整页缓存 + 精准失效 + 智能预加载 + 浏览器缓存 + HTML 压缩 + 图片懒加载 + WebP 自动转换 + 数据库瘦身，多合一站点加速。优先 Redis（不可用时自动降级磁盘），内置 Elementor / WooCommerce 兼容层与第三方缓存冲突检测。
 * Version:           3.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       at8-site-accelerator
 * Domain Path:       /languages
 *
 * @package AT8\SiteAccelerator
 */

defined( 'ABSPATH' ) || exit;

/*
-------------------------------------------------------------------------
 * 常量
 * ----------------------------------------------------------------------
 */

define( 'AT8SA_VERSION', '3.0.1' );
define( 'AT8SA_FILE', __FILE__ );
define( 'AT8SA_PATH', plugin_dir_path( __FILE__ ) );
define( 'AT8SA_URL', plugin_dir_url( __FILE__ ) );
define( 'AT8SA_BASENAME', plugin_basename( __FILE__ ) );
define( 'AT8SA_MIN_WP', '5.8' );
define( 'AT8SA_MIN_PHP', '7.4' );

if ( ! defined( 'AT8SA_CACHE_ROOT' ) ) {
	define( 'AT8SA_CACHE_ROOT', WP_CONTENT_DIR . '/cache/at8-site-accelerator' );
}

/*
-------------------------------------------------------------------------
 * 环境门槛：不满足最低版本时拒绝加载，避免白屏（计划书 §70）
 *
 * 插件头里的 "Requires PHP / Requires at least" 只在**从后台安装**时被 WordPress 校验，
 * 手工上传、must-use 安装、或用脚本批量部署都会绕过它。所以运行时必须自己再判一次。
 * ----------------------------------------------------------------------
 */

/**
 * 输出"环境不满足"的后台提示。
 *
 * 用闭包而不是具名函数：主插件文件是全局作用域，具名函数会污染全局命名空间，
 * 且万一文件被重复 include 会直接 fatal（Cannot redeclare）。
 *
 * @param string $message 提示内容。
 * @return void
 */
$at8sa_environment_notice = static function ( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	);
};

if ( version_compare( PHP_VERSION, AT8SA_MIN_PHP, '<' ) ) {
	$at8sa_environment_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'AT8 Site Accelerator 需要 PHP %1$s 或更高版本，当前为 %2$s。插件未加载。', 'at8-site-accelerator' ),
			AT8SA_MIN_PHP,
			PHP_VERSION
		)
	);

	return;
}

global $wp_version;

if ( isset( $wp_version ) && version_compare( $wp_version, AT8SA_MIN_WP, '<' ) ) {
	$at8sa_environment_notice(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'AT8 Site Accelerator 需要 WordPress %1$s 或更高版本，当前为 %2$s。插件未加载。', 'at8-site-accelerator' ),
			AT8SA_MIN_WP,
			$wp_version
		)
	);

	return;
}

unset( $wp_version, $at8sa_environment_notice );

/*
-------------------------------------------------------------------------
 * PSR-4 风格自动加载（无 Composer 依赖，满足计划书 §44 的目录分层）
 * ----------------------------------------------------------------------
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'AT8\\SiteAccelerator\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );
		$file     = AT8SA_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/*
-------------------------------------------------------------------------
 * 生命周期钩子
 * ----------------------------------------------------------------------
 */
register_activation_hook( AT8SA_FILE, array( 'AT8\\SiteAccelerator\\Core\\Activator', 'activate' ) );
register_deactivation_hook( AT8SA_FILE, array( 'AT8\\SiteAccelerator\\Core\\Deactivator', 'deactivate' ) );

/*
-------------------------------------------------------------------------
 * 启动
 * ----------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	function () {
		AT8\SiteAccelerator\Core\Plugin::instance()->boot();
	},
	1
);

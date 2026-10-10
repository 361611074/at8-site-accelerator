<?php
/**
 * Plugin Name:       AT8 Site Accelerator
 * Plugin URI:        https://www.at8.fun/at8-site-accelerator/
 * Description:       轻量级整页缓存 + 精准失效 + 智能预加载 + 浏览器缓存 + HTML 压缩 + 图片懒加载 + WebP 自动转换 + 数据库瘦身，多合一站点加速。优先 Redis（不可用时自动降级磁盘），内置 Elementor / WooCommerce 兼容层与第三方缓存冲突检测。
 * Version:           3.0.6.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       at8-site-accelerator
 * Domain Path:       /languages
 *
 * @package AT8SA
 */

defined( 'ABSPATH' ) || exit;

/*
-------------------------------------------------------------------------
 * 常量
 * ----------------------------------------------------------------------
 */

define( 'AT8SA_VERSION', '3.0.6.4' );
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
 * 可见范围同样受插件目录指南 11 约束，与 `Admin\Notices::is_allowed_screen()`
 * 用同一条白名单：`admin_notices` 是全局钩子，挂上去就等于"每一个后台页面
 * 都会执行到"，不在白名单里的页面一律不输出。
 *
 * 白名单里只剩插件列表页与仪表盘：本闭包只在"环境门槛不满足、插件拒绝加载"
 * 时执行，此时插件的设置页根本没有注册（后面直接 `return` 了），
 * 而这两个页面恰好是管理员刚激活插件、或想确认"为什么没生效"时会去的地方。
 *
 * @param string $message 提示内容。
 * @return void
 */
$at8sa_environment_notice = static function ( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			$at8sa_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			// 拿不到 screen 时仍然显示：这是"插件未加载"的硬错误，
			// 一旦因为判定不出页面而沉默，管理员就完全没有线索了。
			// （正常运行时的 admin 页面一定能拿到 screen，这条分支几乎不会走到。）
			if ( $at8sa_screen && ! in_array( $at8sa_screen->id, array( 'plugins', 'dashboard' ), true ) ) {
				return;
			}

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

/*
 * WordPress 版本二次校验：只读 `$GLOBALS['wp_version']`。
 *
 * ⚠ 绝不能 `global $wp_version;`，更不能对它 `unset()` —— 本文件是在
 * wp-settings.php 的顶层作用域被 include 的，这里的变量就是全局变量本身。
 * 曾经的 `unset( $wp_version, ... )` 会把核心全局变量真的删掉，导致后续
 * 加载的插件（如 WPForms）拿到 null 直接 Fatal，整站 500。
 */
$at8sa_wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

if ( '' !== $at8sa_wp_version && version_compare( $at8sa_wp_version, AT8SA_MIN_WP, '<' ) ) {
	$at8sa_environment_notice(
		sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'AT8 Site Accelerator 需要 WordPress %1$s 或更高版本，当前为 %2$s。插件未加载。', 'at8-site-accelerator' ),
			AT8SA_MIN_WP,
			$at8sa_wp_version
		)
	);

	unset( $at8sa_wp_version, $at8sa_environment_notice );

	return;
}

unset( $at8sa_wp_version, $at8sa_environment_notice );

/*
-------------------------------------------------------------------------
 * PSR-4 风格自动加载（无 Composer 依赖，满足计划书 §44 的目录分层）
 * ----------------------------------------------------------------------
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'AT8SA\\';
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
register_activation_hook( AT8SA_FILE, array( 'AT8SA\\Core\\Activator', 'activate' ) );
register_deactivation_hook( AT8SA_FILE, array( 'AT8SA\\Core\\Deactivator', 'deactivate' ) );

/*
-------------------------------------------------------------------------
 * 启动
 * ----------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	function () {
		/*
		 * 尽早锁定"浏览器原样发来的 Cookie"。
		 *
		 * 缓存准入判定（RequestGuard::should_bypass）要读购物车/会话 Cookie，
		 * 但它会被调用两次：drop-in 在 WordPress 启动前一次，CacheEngine 在
		 * template_redirect 又一次。而 WooCommerce 的 WC_Cart_Session 在
		 * init 之后会 unset($_COOKIE['woocommerce_items_in_cart'])——
		 * 第二道判定因此看不到这个 Cookie，把本该绕过的请求写进了共享缓存。
		 * 这里在 plugins_loaded（其它插件还没初始化）先建立快照。
		 */
		AT8SA\Cache\RequestGuard::warm_cookies();

		AT8SA\Core\Plugin::instance()->boot();
	},
	1
);

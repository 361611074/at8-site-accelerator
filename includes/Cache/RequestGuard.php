<?php
/**
 * 请求级缓存准入判断（零 WP 依赖）。
 *
 * 这个类同时被两处使用：
 * 1. `advanced-cache.php` drop-in —— 此时 WordPress 尚未加载插件，只能用超全局变量；
 * 2. 插件内的 `CacheEngine` —— 在 RequestGuard 通过后再叠加 WP 条件判断。
 *
 * 因此这里**禁止**出现任何 `is_*()` / `wp_*()` 调用。
 *
 * @package AT8\SiteAccelerator\Cache
 */

namespace AT8\SiteAccelerator\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Class RequestGuard
 */
final class RequestGuard {

	/**
	 * 内置必须绕过的路径片段（计划书 §63）。
	 *
	 * @return array
	 */
	public static function default_excluded_paths() {
		return array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-cron.php',
			'/wp-json',
			'/xmlrpc.php',
			'/wp-signup.php',
			'/wp-activate.php',
			'/admin-ajax.php',
			'/wp-comments-post.php',
			'preview=true',
			'elementor-preview',
			'customize.php',
			'/feed',
			'/cart',
			'/checkout',
			'/my-account',
			'/add-to-cart',
			'?s=',
			'&s=',
		);
	}

	/**
	 * 内置必须绕过的 Cookie 前缀（会话 / 购物车 / 密码保护）。
	 *
	 * @return array
	 */
	public static function default_bypass_cookies() {
		return array(
			'wordpress_logged_in_',
			'wordpress_sec_',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'edd_items_in_cart',
		);
	}

	/**
	 * 当前请求是否必须绕过缓存。
	 *
	 * @param array $config 运行时配置（由 Config::runtime() 生成）。
	 * @return bool true = 绕过（不读也不写缓存）。
	 */
	public static function should_bypass( array $config ) {
		if ( empty( $config['enabled'] ) ) {
			return true;
		}

		// 安全模式 = 全局停缓存。这里必须判，因为 drop-in 命中路径只走本方法，
		// 而 BackendFactory::make() 的 safe_mode 判断在 drop-in 阶段根本不执行——
		// 漏判的后果是"用户以为已经停缓存，访客却还在吃旧页面"。
		if ( ! empty( $config['safe_mode'] ) ) {
			return true;
		}

		// 只缓存幂等的 GET。
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		if ( 'GET' !== $method ) {
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';

		// 路径黑名单。
		$excluded = isset( $config['excluded_paths'] ) && is_array( $config['excluded_paths'] )
			? $config['excluded_paths']
			: self::default_excluded_paths();

		foreach ( $excluded as $needle ) {
			$needle = (string) $needle;

			if ( '' !== $needle && false !== stripos( $uri, $needle ) ) {
				return true;
			}
		}

		// 未登录访客才允许共享缓存。
		if ( empty( $config['cache_logged_in'] ) && self::has_auth_cookie( $config ) ) {
			return true;
		}

		// 会话 / 购物车 Cookie。
		$cookie_prefixes = isset( $config['bypass_cookies'] ) && is_array( $config['bypass_cookies'] )
			? $config['bypass_cookies']
			: self::default_bypass_cookies();

		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			if ( self::cookie_matches( (string) $name, $cookie_prefixes ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 当前请求是否为移动端（用于决定是否走移动端缓存变体）。
	 *
	 * @return bool
	 */
	public static function is_mobile() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

		if ( '' === $ua ) {
			return false;
		}

		$needles = array(
			'mobile',
			'android',
			'iphone',
			'ipod',
			'ipad',
			'windows phone',
			'blackberry',
			'opera mini',
			'opera mobi',
			'iemobile',
			'webos',
			'kindle',
			'silk/',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				// iPad 在部分场景下被视作桌面，这里仍按移动端处理，与主流缓存插件一致。
				return true;
			}
		}

		return false;
	}

	/**
	 * 当前主机名。
	 *
	 * @return string
	 */
	public static function host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';

		if ( '' === $host ) {
			$host = isset( $_SERVER['SERVER_NAME'] ) ? (string) $_SERVER['SERVER_NAME'] : 'localhost';
		}

		return CachePath::normalize_host( $host );
	}

	/**
	 * 当前归一化 URI。
	 *
	 * @param array $config 运行时配置。
	 * @return string
	 */
	public static function uri( array $config ) {
		$raw    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$extra  = isset( $config['ignore_query'] ) && is_array( $config['ignore_query'] ) ? $config['ignore_query'] : array();

		return CachePath::normalize_uri( $raw, $extra );
	}

	/**
	 * 是否存在登录态 Cookie。
	 *
	 * @param array $config 运行时配置。
	 * @return bool
	 */
	private static function has_auth_cookie( array $config ) {
		$hash = isset( $config['cookie_hash'] ) ? (string) $config['cookie_hash'] : '';

		$prefixes = array( 'wordpress_logged_in_', 'wordpress_sec_' );

		if ( '' !== $hash ) {
			$prefixes[] = 'wordpress_logged_in_' . $hash;
			$prefixes[] = 'wordpress_sec_' . $hash;
		}

		foreach ( array_keys( (array) $_COOKIE ) as $name ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( (string) $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Cookie 名是否命中绕过规则（支持 `prefix*` 与精确匹配）。
	 *
	 * @param string $name    Cookie 名。
	 * @param array  $prefixes 规则表。
	 * @return bool
	 */
	private static function cookie_matches( $name, array $prefixes ) {
		foreach ( $prefixes as $rule ) {
			$rule = trim( (string) $rule );

			if ( '' === $rule ) {
				continue;
			}

			if ( '*' === substr( $rule, -1 ) ) {
				$prefix = rtrim( $rule, '*' );

				if ( '' === $prefix || 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			} elseif ( $name === $rule ) {
				return true;
			}
		}

		return false;
	}
}

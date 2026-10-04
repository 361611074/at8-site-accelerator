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
 * @package AT8SA\Cache
 */

namespace AT8SA\Cache;

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
	 * 内置必须绕过的 Cookie 规则（密码保护 / 评论者 / 购物车会话）。
	 *
	 * **规则语法**（见 `cookie_matches()`）：以 `*` 结尾 = 前缀匹配；
	 * 否则 = Cookie 名必须完全相等。
	 *
	 * 这段代码修过两个连在一起的缺陷，改之前请先读完：
	 *
	 * **缺陷 1（内容泄漏）**：`wp-postpass_`、`comment_author_`、
	 * `wp_woocommerce_session_` 曾经写成不带 `*` 的裸前缀，于是退化成
	 * "精确等于 `wp-postpass_`"。而真实 Cookie 名是 `wp-postpass_<COOKIEHASH>`，
	 * 永远匹配不上 → 密码保护页面会被缓存并端给**没输密码**的访客。
	 *
	 * **缺陷 2（开关失效）**：`wordpress_logged_in_*` / `wordpress_sec_*`
	 * 曾经也列在本表里。但登录态本来就由 `has_auth_cookie()` 负责，而它受
	 * `cache_logged_in` 开关控制。两张表同时管同一件事的结果是：
	 * 一旦把前缀补成能真正匹配，`cache_logged_in=1`（"缓存登录用户"）
	 * 就永远被本表拦下，开关变成死开关。
	 *
	 * 所以现在的职责划分是**单一归属**：
	 * - 登录态 Cookie → 只由 `has_auth_cookie()` 管，受 `cache_logged_in` 控制；
	 * - 其它会话 Cookie → 只由本表管，无条件绕过（它们代表"这份内容因人而异"，
	 *   不是"是不是登录用户"的问题，不给开关）。
	 *
	 * @return array
	 */
	public static function default_bypass_cookies() {
		return array(
			// 前缀类：名字后面还会拼 <COOKIEHASH>，必须带 `*`，否则等于没写。
			'wp-postpass_*',
			'comment_author_*',
			'wp_woocommerce_session_*',
			// 精确名类：Cookie 名就是这几个字，加 `*` 反而会误伤同前缀的其它 Cookie。
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'edd_items_in_cart',
		);
	}

	/**
	 * 读取并净化一个 `$_SERVER` 值。
	 *
	 * 为什么不能直接用 `sanitize_text_field()` / `wp_unslash()`：
	 * 本类在 drop-in 阶段（`advanced-cache.php`）就会执行，那时 WordPress 还没加载，
	 * 这些函数根本不存在。更关键的是——`RequestGuard::uri()` 同时被 drop-in 和
	 * `CacheEngine` 调用，两边**必须算出完全一样的缓存键**，否则同一个 URL 会写出
	 * 两份缓存、命中率永远上不去。所以这里刻意只用纯 PHP，不依赖 WP 函数。
	 *
	 * 净化内容：去掉 NUL 与控制字符。`REQUEST_URI` 里混入 NUL 会让下游字符串函数
	 * 提前截断，从而绕过 `/wp-admin` 这类前缀匹配；顺带去掉首尾空白，
	 * 防止 `GET ` 之类的变体绕过请求方法判断。
	 *
	 * 公开是为了让 `advanced-cache.php` 与 `Config::write()` 复用同一个净化入口——
	 * 两边必须对同一个 `HTTP_HOST` 得出同一个主机名，否则会出现
	 * "配置写进了 a.php、drop-in 却去读 b.php" 这种只在真机上才暴露的问题。
	 *
	 * @param string $key      超全局键名。
	 * @param string $fallback 缺失或类型非法时的回退值。
	 * @return string
	 */
	public static function server( $key, $fallback = '' ) {
		// 本方法就是净化点，下面的 preg_replace 会去掉控制字符；
		// drop-in 阶段 WordPress 尚未加载，wp_unslash() 并不存在，也无法用 sanitize_* 系列。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return $fallback;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $_SERVER[ $key ] );

		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = trim( $value );

		return '' === $value ? $fallback : $value;
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
		$method = strtoupper( self::server( 'REQUEST_METHOD', 'GET' ) );

		if ( 'GET' !== $method ) {
			return true;
		}

		$uri = self::server( 'REQUEST_URI', '/' );

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
		$ua = strtolower( self::server( 'HTTP_USER_AGENT' ) );

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
		$host = self::server( 'HTTP_HOST' );

		if ( '' === $host ) {
			$host = self::server( 'SERVER_NAME', 'localhost' );
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
		$raw   = self::server( 'REQUEST_URI', '/' );
		$extra = isset( $config['ignore_query'] ) && is_array( $config['ignore_query'] ) ? $config['ignore_query'] : array();

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

// ── 旧命名空间兼容层（不要删，除非确认线上已无 3.0.2 之前生成的 drop-in）──
// 旧 drop-in 会 require_once 本文件并调用 `\AT8\SiteAccelerator\Cache\RequestGuard`。
// 详见 CachePath.php 末尾同段注释。
if ( ! class_exists( 'AT8\\SiteAccelerator\\Cache\\RequestGuard', false ) ) {
	class_alias( RequestGuard::class, 'AT8\\SiteAccelerator\\Cache\\RequestGuard' );
}

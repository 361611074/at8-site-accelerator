<?php
/**
 * 缓存路径 / 缓存键推导。
 *
 * **本文件必须保持零依赖**：`advanced-cache.php` drop-in 在 WordPress 尚未加载插件时
 * 就要 include 它（计划书 §62 Cache Engine 的 HIT 快速路径），因此这里
 * 不能调用任何 WP 函数，也不能依赖命名空间自动加载。
 *
 * 磁盘布局（对齐 WP Rocket 的可直出结构，见 ARCHITECTURE.md）：
 *
 *   wp-content/cache/at8-site-accelerator/<host>/<path…>/index.html
 *   wp-content/cache/at8-site-accelerator/<host>/<path…>/__m/index.html      移动端变体
 *   wp-content/cache/at8-site-accelerator/<host>/<path…>/q-<hash>/index.html 带查询串
 *
 * 这样 `purge_url()` 就是"删掉一个目录"，而不是"扫全库找 md5"。
 *
 * @package AT8SA\Cache
 */

namespace AT8SA\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Class CachePath
 */
final class CachePath {

	/**
	 * 单个路径段的最大长度，防止超长 slug 撑爆文件系统限制。
	 */
	const MAX_SEGMENT = 120;

	/**
	 * 内置忽略的查询参数（追踪/营销类，不影响服务端渲染）。
	 *
	 * @return array
	 */
	public static function default_ignored_query() {
		return array(
			'utm_*',
			'fbclid',
			'gclid',
			'gclsrc',
			'dclid',
			'msclkid',
			'mc_*',
			'igshid',
			'twclid',
			'yclid',
			'_ga',
			'_gl',
			'wbraid',
			'gbraid',
			'mc_cid',
			'mc_eid',
			'ref_src',
		);
	}

	/**
	 * 归一化请求 URI：剥离追踪参数、统一参数顺序。
	 *
	 * 只影响缓存键，不改变实际渲染。
	 *
	 * @param string $uri      原始 REQUEST_URI。
	 * @param array  $extra    用户追加的忽略规则（支持 `prefix_*` 与 `*`）。
	 * @return string
	 */
	public static function normalize_uri( $uri, array $extra = array() ) {
		$uri = (string) $uri;

		if ( '' === $uri ) {
			return '/';
		}

		$parts = explode( '?', $uri, 2 );

		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			return $parts[0];
		}

		parse_str( $parts[1], $query );

		if ( empty( $query ) ) {
			return $parts[0];
		}

		$rules = array_merge( self::default_ignored_query(), $extra );

		foreach ( array_keys( $query ) as $key ) {
			if ( self::is_ignored_param( (string) $key, $rules ) ) {
				unset( $query[ $key ] );
			}
		}

		ksort( $query );

		$query_string = http_build_query( $query );

		return $parts[0] . ( '' !== $query_string ? '?' . $query_string : '' );
	}

	/**
	 * 单参数是否命中忽略规则。
	 *
	 * @param string $key   参数名。
	 * @param array  $rules 规则表。
	 * @return bool
	 */
	public static function is_ignored_param( $key, array $rules ) {
		$key = strtolower( $key );

		foreach ( $rules as $rule ) {
			$rule = strtolower( trim( (string) $rule ) );

			if ( '' === $rule ) {
				continue;
			}

			if ( '*' === $rule ) {
				return true;
			}

			if ( '*' === substr( $rule, -1 ) ) {
				$prefix = rtrim( $rule, '*' );

				if ( '' === $prefix || 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			} elseif ( $key === $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 归一化主机名（小写、去掉端口以外的杂质）。
	 *
	 * @param string $host 主机名。
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );

		if ( '' === $host ) {
			return 'unknown-host';
		}

		// 只保留字母数字、点、连字符、冒号（IPv6）、下划线，其余替换掉。
		$host = preg_replace( '/[^a-z0-9.\-:_]/', '_', $host );

		return '' === $host ? 'unknown-host' : substr( $host, 0, 190 );
	}

	/**
	 * URI 路径 → 磁盘相对目录段。
	 *
	 * @param string $path URI 的 path 部分（不含查询串）。
	 * @return string 形如 `blog/2026/post-slug`，根路径返回 `__root`。
	 */
	public static function path_segments( $path ) {
		$path = (string) $path;

		// 去掉协议/主机残留，只保留 path。
		if ( false !== strpos( $path, '://' ) ) {
			$parsed = self::parse_url_compat( $path );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		}

		$path = '/' . ltrim( $path, '/' );
		$path = rawurldecode( $path );

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segment = self::sanitize_segment( $segment );

			if ( '' !== $segment ) {
				$segments[] = $segment;
			}
		}

		// 目录索引页（/、/blog/ 等）落到 __root 段，避免与同名文件冲突。
		if ( empty( $segments ) ) {
			return '__root';
		}

		return implode( '/', $segments );
	}

	/**
	 * 单个路径段消毒：只留安全字符，并做长度钳制。
	 *
	 * 这是 Path Traversal 防线的第一环——`..`、`%2e%2e`、控制字符全部被吃掉。
	 *
	 * @param string $segment 原始段。
	 * @return string
	 */
	public static function sanitize_segment( $segment ) {
		$segment = (string) $segment;

		// 显式干掉穿越序列。
		$segment = str_replace( array( '..', '\\' ), '', $segment );
		$segment = preg_replace( '/[\x00-\x1f\x7f]/', '', $segment );
		$segment = preg_replace( '/[^A-Za-z0-9._\-]/', '_', $segment );
		$segment = trim( $segment, '.' );

		if ( '' === $segment || '.' === $segment ) {
			return '';
		}

		if ( strlen( $segment ) > self::MAX_SEGMENT ) {
			$segment = substr( $segment, 0, self::MAX_SEGMENT );
		}

		return $segment;
	}

	/**
	 * 拆分归一化 URI 为 path 与 query。
	 *
	 * @param string $uri 归一化 URI。
	 * @return array{path:string,query:string}
	 */
	public static function split_uri( $uri ) {
		$parts = explode( '?', (string) $uri, 2 );

		return array(
			'path'  => $parts[0],
			'query' => isset( $parts[1] ) ? $parts[1] : '',
		);
	}

	/**
	 * 相对缓存目录（相对 CACHE_ROOT）。
	 *
	 * @param string $host   主机。
	 * @param string $uri    归一化 URI。
	 * @param bool   $mobile 是否移动端变体。
	 * @return string 形如 `example.com/blog/post` 或 `example.com/blog/post/q-1a2b3c4d`。
	 */
	public static function relative_dir( $host, $uri, $mobile = false ) {
		$host  = self::normalize_host( $host );
		$split = self::split_uri( $uri );
		$rel   = $host . '/' . self::path_segments( $split['path'] );

		if ( '' !== $split['query'] ) {
			$rel .= '/q-' . substr( md5( $split['query'] ), 0, 10 );
		}

		if ( $mobile ) {
			$rel .= '/__m';
		}

		return $rel;
	}

	/**
	 * 磁盘上的缓存文件绝对路径。
	 *
	 * @param string $root   缓存根目录（绝对路径）。
	 * @param string $host   主机。
	 * @param string $uri    归一化 URI。
	 * @param bool   $mobile 是否移动端变体。
	 * @return string
	 */
	public static function disk_file( $root, $host, $uri, $mobile = false ) {
		return rtrim( $root, '/\\' ) . '/' . self::relative_dir( $host, $uri, $mobile ) . '/index.html';
	}

	/**
	 * 磁盘上的缓存目录（用于按 URL 精准失效）。
	 *
	 * 注意：**不含** mobile 段，因此一次调用同时覆盖桌面与移动两个变体。
	 *
	 * @param string $root 缓存根目录。
	 * @param string $host 主机。
	 * @param string $uri  归一化 URI。
	 * @return string
	 */
	public static function disk_dir( $root, $host, $uri ) {
		return rtrim( $root, '/\\' ) . '/' . self::relative_dir( $host, $uri, false );
	}

	/**
	 * Redis 缓存键。
	 *
	 * 刻意**不含协议**：同一站点 http/https 混用时不应产生两份缓存，
	 * 否则缓存命中率会被腰斩。
	 *
	 * @param string $salt   站点盐（站点令牌 + 缓存版本）。
	 * @param string $host   主机。
	 * @param string $uri    归一化 URI。
	 * @param bool   $mobile 是否移动端变体。
	 * @return string
	 */
	public static function redis_key( $salt, $host, $uri, $mobile = false ) {
		return 'at8sa:' . $salt . ':' . self::normalize_host( $host ) . ':' . $uri . ( $mobile ? '|m' : '' );
	}

	/**
	 * Redis 键通配前缀（用于按站点整站失效）。
	 *
	 * @param string $salt 站点盐。
	 * @return string
	 */
	public static function redis_pattern( $salt ) {
		return 'at8sa:' . $salt . ':*';
	}

	/**
	 * 无 WP 依赖的 URL 解析（drop-in 阶段 wp_parse_url 可能不可用）。
	 *
	 * @param string $url URL。
	 * @return array
	 */
	private static function parse_url_compat( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( $url );
			return is_array( $parsed ) ? $parsed : array();
		}

		$parsed = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return is_array( $parsed ) ? $parsed : array();
	}
}

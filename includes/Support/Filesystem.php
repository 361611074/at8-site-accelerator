<?php
/**
 * 文件系统助手：受控的目录创建、递归删除、写入。
 *
 * 所有递归删除都做了路径白名单校验（计划书 §69 Path Traversal 防护）：
 * 只允许删除 WP_CONTENT_DIR/cache 之下的路径，杜绝任何形式的手滑越界。
 *
 * @package AT8SA\Support
 */

namespace AT8SA\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class Filesystem
 */
final class Filesystem {

	/**
	 * 判定路径是否位于插件缓存根目录之内。
	 *
	 * 用 realpath 归一化后再比较前缀，可挡住 `../` 穿越与符号链接绕行。
	 *
	 * @param string $path 待判定路径。
	 * @return bool
	 */
	public static function is_inside_cache_root( $path ) {
		$root = self::normalize( WP_CONTENT_DIR . '/cache' );
		$real = self::normalize( $path );

		if ( '' === $root || '' === $real ) {
			return false;
		}

		// normalize() 统一输出正斜杠，因此这里也必须用 '/' 拼接——
		// 在 Windows 上用 DIRECTORY_SEPARATOR（'\'）会导致前缀比较永远不成立。
		return $real === $root || 0 === strpos( $real, $root . '/' );
	}

	/**
	 * 归一化路径（不要求目标存在）。
	 *
	 * @param string $path 路径。
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return '';
		}

		$real = realpath( $path );
		if ( false !== $real ) {
			return rtrim( str_replace( '\\', '/', $real ), '/' );
		}

		// 目标尚不存在时，逐级向上找最近的已存在祖先，再拼回剩余段。
		$parts = array();
		$probe = rtrim( str_replace( '\\', '/', $path ), '/' );

		while ( '' !== $probe && ! is_dir( $probe ) ) {
			$parts[] = basename( $probe );
			$parent  = dirname( $probe );

			if ( $parent === $probe ) {
				return '';
			}

			$probe = $parent;
		}

		$base = realpath( $probe );
		if ( false === $base ) {
			return '';
		}

		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		return $base . ( $parts ? '/' . implode( '/', array_reverse( $parts ) ) : '' );
	}

	/**
	 * 递归删除目录。**仅允许删除缓存根目录之内的路径。**
	 *
	 * @param string $dir 目录。
	 * @return bool
	 */
	public static function rrmdir( $dir ) {
		if ( ! self::is_inside_cache_root( $dir ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( array_diff( $items, array( '.', '..' ) ) as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;

			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// rmdir 没有对应的 WordPress API：WP_Filesystem 需要先做凭证/传输层初始化，
		// 在"每次清缓存都要递归删目录"这种高频内部路径上既无收益、又有失败风险。
		// 这里的路径已由 is_inside_cache_root() 白名单校验，只会删缓存根内的空目录。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		return @rmdir( $dir );
	}

	/**
	 * 递归创建目录（不放置守卫文件）。
	 *
	 * 缓存目录布局是"一个 URL 一个目录"，用 mkdir_guarded 会在每个目录里
	 * 都塞一个 index.php（1000 个页面 = 1000 个多余小文件，还会让
	 * "删完文件顺手删空目录"永远失败）。守卫只需要放在缓存根目录一处。
	 *
	 * @param string $dir 目录。
	 * @return bool
	 */
	public static function ensure_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}

		return wp_mkdir_p( $dir );
	}

	/**
	 * 创建目录并放置 index.php 守卫（计划书 §125）。
	 *
	 * 只应用于缓存根目录与 config/ 这类"结构性目录"。
	 *
	 * @param string $dir 目录。
	 * @return bool
	 */
	public static function mkdir_guarded( $dir ) {
		if ( ! self::ensure_dir( $dir ) ) {
			return false;
		}

		$guard = $dir . '/index.php';

		if ( ! file_exists( $guard ) ) {
			@file_put_contents( $guard, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return true;
	}

	/**
	 * 目录若已"空"（只剩 index.php 守卫）则删除，避免缓存目录里堆满空壳。
	 *
	 * 只对缓存根之内的路径生效。
	 *
	 * @param string $dir 目录。
	 * @return bool 是否删除了目录。
	 */
	public static function prune_empty_dir( $dir ) {
		if ( ! self::is_inside_cache_root( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $items ) ) {
			return false;
		}

		$items = array_diff( $items, array( '.', '..' ) );

		foreach ( $items as $item ) {
			if ( 'index.php' !== $item ) {
				return false;
			}
		}

		if ( in_array( 'index.php', $items, true ) ) {
			wp_delete_file( $dir . '/index.php' );
		}

		// 同 rrmdir()：rmdir 无 WordPress 等价 API，路径已受白名单保护。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		return @rmdir( $dir );
	}

	/**
	 * 原子写文件（先写临时文件再 rename，避免并发读到半个文件）。
	 *
	 * @param string $path    目标路径。
	 * @param string $content 内容。
	 * @return bool
	 */
	public static function put_contents( $path, $content ) {
		$dir = dirname( $path );

		if ( ! self::ensure_dir( $dir ) ) {
			return false;
		}

		$tmp = $path . '.' . substr( md5( (string) microtime( true ) ), 0, 8 ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// rename 是"原子写"的必需品：同目录临时文件 + rename 保证并发下不会读到半个
		// HTML。WP_Filesystem::move() 在 FTP / SSH 传输模式会退化成"读出来再写回去"，
		// 原子性直接消失，并发时访客就会读到半截页面。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		return true;
	}

	/**
	 * 缓存目录是否可写。
	 *
	 * @return bool
	 */
	public static function cache_root_writable() {
		$root = WP_CONTENT_DIR . '/cache';

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		return is_dir( $root ) && wp_is_writable( $root );
	}
}

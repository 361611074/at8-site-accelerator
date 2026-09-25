<?php
/**
 * advanced-cache.php drop-in 的安装 / 卸载，以及 wp-config.php 中 WP_CACHE 的安全开关。
 *
 * 计划书 §104 明令"禁止自动修改 WordPress Core"。这里只动 `wp-content/advanced-cache.php`
 * 与 `wp-config.php` 两个**用户配置文件**，且：
 * - 改 wp-config.php 前强制备份；
 * - 改完做完整性校验（文件非空、仍含 DB_NAME、仍含 wp-settings.php 引入），任一不满足立即回滚；
 * - 只增删带专属标记的那一行，绝不重写整文件；
 * - 卸载时只删自己写的那一行，恢复原状。
 *
 * @package AT8\SiteAccelerator\Cache
 */

namespace AT8\SiteAccelerator\Cache;

use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdvancedCache
 */
final class AdvancedCache {

	/**
	 * wp-config.php 中我们插入的行的标记。
	 */
	const WP_CACHE_MARKER = '// Added by AT8 Site Accelerator';

	/**
	 * drop-in 文件中的归属标记。
	 */
	const DROPIN_MARKER = 'AT8 Site Accelerator —— advanced-cache.php drop-in';

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 构造。
	 *
	 * @param Logger $logger 日志。
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * drop-in 目标路径。
	 *
	 * @return string
	 */
	public function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * 模板路径。
	 *
	 * @return string
	 */
	private function template_path() {
		return AT8SA_PATH . 'templates/advanced-cache.php';
	}

	/**
	 * 已安装的 drop-in 是否属于本插件。
	 *
	 * @return bool
	 */
	public function is_installed() {
		$path = $this->dropin_path();

		if ( ! is_file( $path ) ) {
			return false;
		}

		$head = (string) @file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		return false !== strpos( $head, 'AT8 Site Accelerator' );
	}

	/**
	 * 安装 drop-in（幂等）。
	 *
	 * @return bool
	 */
	public function install() {
		$template = $this->template_path();

		if ( ! is_readable( $template ) ) {
			$this->logger->error( 'advanced-cache 模板不可读', array( 'path' => $template ) );

			return false;
		}

		$content = (string) file_get_contents( $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		$content = str_replace( '{{AT8SA_PATH}}', trailingslashit( wp_normalize_path( AT8SA_PATH ) ), $content );

		$target = $this->dropin_path();

		if ( ! wp_is_writable( dirname( $target ) ) ) {
			$this->logger->error( 'wp-content 不可写，无法安装 advanced-cache.php' );

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== @file_put_contents( $target, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 卸载 drop-in（只删属于自己的）。
	 *
	 * @return bool
	 */
	public function uninstall() {
		if ( ! $this->is_installed() ) {
			return true;
		}

		return (bool) @unlink( $this->dropin_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * WP_CACHE 是否已启用。
	 *
	 * @return bool
	 */
	public function is_wp_cache_enabled() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * wp-config.php 路径。
	 *
	 * @return string
	 */
	private function wp_config_path() {
		return ABSPATH . 'wp-config.php';
	}

	/**
	 * 在 wp-config.php 中启用 WP_CACHE。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function enable_wp_cache() {
		$path = $this->wp_config_path();

		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return array(
				'ok'      => true,
				'message' => __( 'WP_CACHE 已启用。', 'at8-site-accelerator' ),
			);
		}

		if ( ! is_file( $path ) || ! wp_is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 不可写，请手动在文件中加入：define( \'WP_CACHE\', true );', 'at8-site-accelerator' ),
			);
		}

		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( '' === $original ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 读取失败。', 'at8-site-accelerator' ),
			);
		}

		if ( false !== strpos( $original, 'WP_CACHE' ) ) {
			// 已存在但为 false，替换掉它。
			$updated = preg_replace(
				'/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)\s*;/',
				"define( 'WP_CACHE', true ); " . self::WP_CACHE_MARKER,
				$original,
				1
			);

			if ( is_string( $updated ) && $updated !== $original ) {
				return $this->write_wp_config( $path, $original, $updated );
			}

			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 中已有 WP_CACHE 定义且无法自动改写，请手动设为 true。', 'at8-site-accelerator' ),
			);
		}

		$line = "\ndefine( 'WP_CACHE', true ); " . self::WP_CACHE_MARKER . "\n";

		// 插到 "stop editing" 之前，这是 wp-config.php 的标准锚点。
		$anchors = array(
			"/* That's all, stop editing!",
			"require_once ABSPATH . 'wp-settings.php';",
			'require_once ABSPATH . "wp-settings.php";',
		);

		$updated = '';

		foreach ( $anchors as $anchor ) {
			$pos = strpos( $original, $anchor );

			if ( false !== $pos ) {
				$updated = substr( $original, 0, $pos ) . ltrim( $line ) . "\n" . substr( $original, $pos );
				break;
			}
		}

		if ( '' === $updated ) {
			return array(
				'ok'      => false,
				'message' => __( '未能在 wp-config.php 中定位插入点，请手动加入：define( \'WP_CACHE\', true );', 'at8-site-accelerator' ),
			);
		}

		return $this->write_wp_config( $path, $original, $updated );
	}

	/**
	 * 从 wp-config.php 中移除我们插入的 WP_CACHE 行。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function disable_wp_cache() {
		$path = $this->wp_config_path();

		if ( ! is_file( $path ) || ! wp_is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 不可写，请手动移除 WP_CACHE 定义。', 'at8-site-accelerator' ),
			);
		}

		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( false === strpos( $original, self::WP_CACHE_MARKER ) ) {
			return array(
				'ok'      => true,
				'message' => __( '未发现本插件写入的 WP_CACHE 定义，无需处理。', 'at8-site-accelerator' ),
			);
		}

		$updated = preg_replace(
			'/^.*' . preg_quote( self::WP_CACHE_MARKER, '/' ) . '.*$\R?/m',
			'',
			$original
		);

		if ( ! is_string( $updated ) || $updated === $original ) {
			return array(
				'ok'      => false,
				'message' => __( '移除失败，请手动删除带 "Added by AT8 Site Accelerator" 标记的那一行。', 'at8-site-accelerator' ),
			);
		}

		return $this->write_wp_config( $path, $original, $updated );
	}

	/**
	 * 写 wp-config.php：备份 → 写入 → 校验 → 失败回滚。
	 *
	 * @param string $path     路径。
	 * @param string $original 原始内容。
	 * @param string $updated  新内容。
	 * @return array{ok:bool,message:string}
	 */
	private function write_wp_config( $path, $original, $updated ) {
		$backup = $path . '.at8sa.bak';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $backup, $original ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '无法创建 wp-config.php 备份，操作已中止（安全优先）。', 'at8-site-accelerator' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $path, $updated ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '写入 wp-config.php 失败。', 'at8-site-accelerator' ),
			);
		}

		if ( ! $this->verify_wp_config( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $path, $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$this->logger->error( 'wp-config.php 校验失败，已自动回滚' );

			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 写入后校验未通过，已自动回滚。请手动添加 WP_CACHE 定义。', 'at8-site-accelerator' ),
			);
		}

		$this->logger->info( 'wp-config.php 已更新', array( 'backup' => $backup ) );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: backup file path */
				__( '已启用 WP_CACHE。原文件已备份至 %s。', 'at8-site-accelerator' ),
				basename( $backup )
			),
		);
	}

	/**
	 * 校验 wp-config.php 仍然是"看起来能跑的"配置。
	 *
	 * 检查项刻意保守：非空、含 DB_NAME、含 wp-settings.php 引入、大括号配平。
	 *
	 * @param string $path 路径。
	 * @return bool
	 */
	private function verify_wp_config( $path ) {
		$content = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( strlen( $content ) < 100 ) {
			return false;
		}

		if ( false === strpos( $content, 'DB_NAME' ) ) {
			return false;
		}

		if ( false === strpos( $content, 'wp-settings.php' ) ) {
			return false;
		}

		if ( substr_count( $content, '{' ) !== substr_count( $content, '}' ) ) {
			return false;
		}

		return true;
	}
}

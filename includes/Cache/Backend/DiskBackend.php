<?php
/**
 * 磁盘缓存后端。
 *
 * 目录布局见 CachePath 的类注释。相比 2.x 的 `md5(key).html` 扁平布局，
 * 这里的关键改进是**目录即 URL**：精准失效退化成一次 `rrmdir()`，
 * 也让服务器规则可以直接 `try_files` 直出（计划书 §62）。
 *
 * @package AT8\SiteAccelerator\Cache\Backend
 */

namespace AT8\SiteAccelerator\Cache\Backend;

use AT8\SiteAccelerator\Cache\CachePath;
use AT8\SiteAccelerator\Support\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Class DiskBackend
 */
final class DiskBackend implements BackendInterface {

	/**
	 * 缓存根目录。
	 *
	 * @var string
	 */
	private $root;

	/**
	 * 本次请求是否已确保根目录 + 守卫存在。
	 *
	 * @var bool
	 */
	private $root_ready = false;

	/**
	 * 构造。
	 *
	 * @param string $root 缓存根目录。
	 */
	public function __construct( $root ) {
		$this->root = rtrim( (string) $root, '/\\' );
	}

	/**
	 * 根目录。
	 *
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( $host, $uri, $mobile = false ) {
		$file = CachePath::disk_file( $this->root, $host, $uri, $mobile );

		if ( ! is_file( $file ) ) {
			return false;
		}

		$html = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $html || '' === $html ) {
			return false;
		}

		// 过期即视为未命中（同时顺手清理，避免陈旧文件长期占盘）。
		$ttl = $this->ttl_of( $file );
		if ( $ttl > 0 && ( time() - (int) filemtime( $file ) ) > $ttl ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		return $html;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( $host, $uri, $html, $ttl, $mobile = false ) {
		$file = CachePath::disk_file( $this->root, $host, $uri, $mobile );

		// 根目录与 index.php 守卫只在首次写入时建一次。
		if ( ! $this->root_ready ) {
			Filesystem::mkdir_guarded( $this->root );
			$this->root_ready = true;
		}

		if ( ! Filesystem::put_contents( $file, $html ) ) {
			return false;
		}

		// 用 mtime 承载 TTL，省掉一份元数据文件。
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * 只删"这个 URL 自己的"缓存文件（桌面 index.html + 移动 __m/index.html），
	 * 不碰同目录下 `q-<hash>/` 里的带查询串变体。
	 *
	 * 为什么不直接 rrmdir 整个目录：`/hello/` 与 `/hello/?page=2` 是**两个不同的
	 * 页面**，后者是前者的子目录。rrmdir 会让"改一篇不带分页的文章"顺手清掉
	 * 它的分页缓存（以及所有其它参数变体），属于误伤——缓存被多清一次只是性能损失，
	 * 但会让"精准失效"退化成"范围失效"，失去本项目的核心卖点。
	 * 需要连带清掉分页时，由 Purger::related_urls() 显式把分页 URL 一并列出。
	 */
	public function delete_url( $host, $uri ) {
		$dir = CachePath::disk_dir( $this->root, $host, $uri );

		if ( ! Filesystem::is_inside_cache_root( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( array( $dir . '/index.html', $dir . '/__m/index.html' ) as $file ) {
			if ( is_file( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$deleted;
			}
		}

		// 顺手收掉空掉的 __m 与自身目录；父目录还有 q-*/ 时会保留。
		Filesystem::prune_empty_dir( $dir . '/__m' );
		Filesystem::prune_empty_dir( $dir );

		return $deleted;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_urls( $host, array $uris ) {
		$deleted = 0;

		foreach ( $uris as $uri ) {
			$deleted += $this->delete_url( $host, $uri );
		}

		return $deleted;
	}

	/**
	 * {@inheritDoc}
	 */
	public function flush() {
		if ( ! is_dir( $this->root ) ) {
			return true;
		}

		if ( ! Filesystem::is_inside_cache_root( $this->root ) ) {
			return false;
		}

		$entries = @scandir( $this->root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $entries ) ) {
			return false;
		}

		foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			$path = $this->root . DIRECTORY_SEPARATOR . $entry;

			// config/ 里放的是 drop-in 运行时配置，不属于页面缓存，保留。
			if ( 'config' === $entry && is_dir( $path ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				Filesystem::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'Disk';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available() {
		return Filesystem::cache_root_writable();
	}

	/**
	 * {@inheritDoc}
	 */
	public function stats() {
		$count = 0;
		$bytes = 0;

		if ( ! is_dir( $this->root ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'html' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			++$count;
			$bytes += (int) $file->getSize();
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * 读取文件承载的 TTL。目前统一取全局设置，保留方法便于后续做逐页 TTL。
	 *
	 * @param string $file 文件路径。
	 * @return int
	 */
	private function ttl_of( $file ) {
		$ttl = isset( $GLOBALS['at8sa_runtime_ttl'] ) ? (int) $GLOBALS['at8sa_runtime_ttl'] : 0;

		unset( $file );

		return $ttl;
	}
}

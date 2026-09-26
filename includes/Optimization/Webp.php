<?php
/**
 * WebP 自动转换（从 2.x 迁移，行为保持一致）。
 *
 * 上传图片时生成同名 `.webp` 副本，交给服务器规则（`try_files` / `rewrite`）自动下发。
 * 转换在**上传时**做，而不是访问时——访问时转换会把 CPU 压力放到访客路径上，
 * 那是性能插件最不该犯的错误。
 *
 * 几个刻意的保守选择：
 * - 只处理 jpg/jpeg/png，不碰 gif（动图转 WebP 会丢动画）与 svg；
 * - 生成后若体积**反而变大**则删除副本并放弃（PNG 图形/纯色图常见）；
 * - 依赖 GD 的 `imagewebp`，缺失时静默跳过，不报错、不降级到外部服务（那涉及隐私外传）；
 * - 删除附件时同步清理所有副本，不留垃圾。
 *
 * @package AT8\SiteAccelerator\Optimization
 */

namespace AT8\SiteAccelerator\Optimization;

use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Webp
 */
final class Webp {

	/**
	 * WebP 编码质量。
	 */
	const QUALITY = 82;

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 * @param Logger   $logger   日志。
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * 环境是否支持 WebP 转换。
	 *
	 * @return bool
	 */
	public function supported() {
		return function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromjpeg' );
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! $this->settings->is_on( 'webp_convert' ) || ! $this->supported() ) {
			return;
		}

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
	}

	/**
	 * 生成缩略图后，为原图与所有尺寸生成 WebP 副本。
	 *
	 * @param array $metadata      附件元数据。
	 * @param int   $attachment_id 附件 ID。
	 * @return array 原样返回元数据。
	 */
	public function on_generate_metadata( $metadata, $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! is_file( $file ) ) {
			return $metadata;
		}

		$this->convert( $file );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $file );

			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$path = $dir . '/' . $size['file'];

				if ( is_file( $path ) ) {
					$this->convert( $path );
				}
			}
		}

		return $metadata;
	}

	/**
	 * 删除附件时清理所有 WebP 副本。
	 *
	 * @param int $attachment_id 附件 ID。
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file ) {
			return;
		}

		$this->remove_sidecar( $file );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $file );

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$this->remove_sidecar( $dir . '/' . $size['file'] );
				}
			}
		}
	}

	/**
	 * 单文件转换。
	 *
	 * @param string $path 原图绝对路径。
	 * @return bool 是否成功生成副本。
	 */
	public function convert( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		$target = $path . '.webp';

		if ( file_exists( $target ) && filemtime( $target ) >= filemtime( $path ) ) {
			return true; // 副本已存在且不比原图旧。
		}

		$image = $this->load( $path, $extension );

		if ( ! $image ) {
			$this->logger->debug( 'WebP 转换失败：原图无法解码', array( 'file' => $path ) );

			return false;
		}

		$ok = @imagewebp( $image, $target, self::QUALITY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		imagedestroy( $image );

		if ( ! $ok ) {
			$this->logger->debug( 'WebP 编码失败', array( 'file' => $path ) );

			return false;
		}

		// 体积反而变大：删掉副本，避免"优化"变成负优化。
		if ( filesize( $target ) >= filesize( $path ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$this->logger->debug( 'WebP 体积未变小，已放弃副本', array( 'file' => $path ) );

			return false;
		}

		return true;
	}

	/**
	 * 读取图像资源。
	 *
	 * @param string $path      路径。
	 * @param string $extension 扩展名。
	 * @return resource|\GdImage|false
	 */
	private function load( $path, $extension ) {
		if ( 'png' === $extension ) {
			$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $image ) {
				return false;
			}

			imagealphablending( $image, false );
			imagesavealpha( $image, true ); // 保留 PNG 透明通道。

			return $image;
		}

		return @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 删除某文件的 WebP 副本。
	 *
	 * @param string $path 原文件路径。
	 * @return void
	 */
	private function remove_sidecar( $path ) {
		$target = $path . '.webp';

		if ( is_file( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

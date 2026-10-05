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
 * - **每个源格式都在真正调用前单独检测所需 GD 函数**（PNG 需要
 *   `imagecreatefrompng()`、JPEG 需要 `imagecreatefromjpeg()`），缺任一个都只是
 *   跳过该文件，而不是抛 Fatal —— 某些 GD 构建并不编译进全部格式支持；
 * - 删除附件时同步清理所有副本，不留垃圾。
 *
 * @package AT8SA\Optimization
 */

namespace AT8SA\Optimization;

use AT8SA\Core\Settings;
use AT8SA\Support\Logger;

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
	 * 编码所需的 GD 函数集合（按源格式分组）。
	 *
	 * `decoder` 负责把源文件读进内存，`encoder` 负责写出 WebP。
	 * 两者**缺一不可**，因此不能只看 `imagewebp()` 在不在。
	 */
	const REQUIRED_FUNCTIONS = array(
		'jpg'  => array(
			'decoder' => 'imagecreatefromjpeg',
			'encoder' => 'imagewebp',
		),
		'jpeg' => array(
			'decoder' => 'imagecreatefromjpeg',
			'encoder' => 'imagewebp',
		),
		'png'  => array(
			'decoder' => 'imagecreatefrompng',
			'encoder' => 'imagewebp',
		),
	);

	/**
	 * 环境是否支持 WebP 转换（保守判断：所有格式都能转才算支持）。
	 *
	 * 旧实现只检查 `imagewebp()` 与 `imagecreatefromjpeg()`，于是 PNG 上传路径
	 * 会在缺少 `imagecreatefrompng()` 的 GD 构建上直接 Fatal：
	 * "Call to undefined function imagecreatefrompng()"。
	 *
	 * 同时补上 `extension_loaded( 'gd' )` —— `function_exists()` 已经隐含了这一点，
	 * 但显式表达意图、也便于静态分析理解这里的判据。
	 *
	 * @return bool
	 */
	public function supported() {
		if ( ! extension_loaded( 'gd' ) ) {
			return false;
		}

		foreach ( array( 'jpg', 'jpeg', 'png' ) as $extension ) {
			if ( ! $this->can_convert_format( $extension ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 某个源格式是否具备完整的"解码 → 编码"能力。
	 *
	 * 这是真正的能力判定入口：转换单个文件前会再问一次，
	 * 因此即便 `boot()` 之后环境发生变化（例如 `php.ini` 被改），也不会 Fatal。
	 *
	 * @param string $extension 扩展名（小写，不含点）。
	 * @return bool
	 */
	public function can_convert_format( $extension ) {
		$key = strtolower( (string) $extension );

		if ( ! isset( self::REQUIRED_FUNCTIONS[ $key ] ) ) {
			return false;
		}

		foreach ( self::REQUIRED_FUNCTIONS[ $key ] as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
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

		// 调用前的最后一道闸：能力不足就跳过这个文件，绝不进入 GD 调用。
		// `boot()` 时的 `supported()` 只是"整体可行性"，这里按**实际源格式**
		// 再确认一次（PNG 需要 imagecreatefrompng，JPEG 需要 imagecreatefromjpeg）。
		if ( ! $this->can_convert_format( $extension ) ) {
			$this->logger->debug(
				'WebP 转换跳过：当前 GD 构建缺少该格式所需函数',
				array(
					'file'  => $path,
					'needs' => isset( self::REQUIRED_FUNCTIONS[ $extension ] )
						? implode( '+', self::REQUIRED_FUNCTIONS[ $extension ] )
						: '',
				)
			);

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
			wp_delete_file( $target );

			$this->logger->debug( 'WebP 体积未变小，已放弃副本', array( 'file' => $path ) );

			return false;
		}

		return true;
	}

	/**
	 * 读取图像资源。
	 *
	 * 调用方（`convert()`）已按格式做过 `can_convert_format()` 检测，
	 * 这里再做一次类型兜底：即便将来有新的调用方绕过那层，也不会在
	 * 缺少 `imagecreatefrompng()` 的环境里直接 Fatal。
	 *
	 * @param string $path      路径。
	 * @param string $extension 扩展名。
	 * @return resource|\GdImage|false
	 */
	private function load( $path, $extension ) {
		if ( 'png' === $extension ) {
			if ( ! function_exists( 'imagecreatefrompng' ) ) {
				return false;
			}

			$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! $image ) {
				return false;
			}

			imagealphablending( $image, false );
			imagesavealpha( $image, true ); // 保留 PNG 透明通道。

			return $image;
		}

		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return false;
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
			wp_delete_file( $target );
		}
	}
}

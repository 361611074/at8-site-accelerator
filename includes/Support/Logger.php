<?php
/**
 * 结构化日志（计划书 §78 Observability / §79 Audit Log 的插件侧最小实现）。
 *
 * 只写本地文件，**默认关闭**，绝不上报远程（计划书 §60：Free 版默认无远程分析）。
 * 日志中禁止出现密钥、Cookie、License Key（计划书 §79）。
 *
 * @package AT8\SiteAccelerator\Support
 */

namespace AT8\SiteAccelerator\Support;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
final class Logger {

	/**
	 * 级别权重，用于阈值过滤。
	 *
	 * @var array<string, int>
	 */
	private static $weights = array(
		'error'   => 1,
		'warning' => 2,
		'info'    => 3,
		'debug'   => 4,
	);

	/**
	 * 设置实例。
	 *
	 * @var Settings|null
	 */
	private $settings = null;

	/**
	 * 构造。
	 *
	 * @param Settings|null $settings 设置实例。
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * 日志文件绝对路径。
	 *
	 * @return string
	 */
	public function file() {
		return AT8SA_CACHE_ROOT . '/at8sa.log';
	}

	/**
	 * 是否启用。
	 *
	 * @return bool
	 */
	public function enabled() {
		return $this->settings instanceof Settings && $this->settings->is_on( 'log_enabled' );
	}

	/**
	 * 写日志。
	 *
	 * @param string $level   error|warning|info|debug。
	 * @param string $message 消息。
	 * @param array  $context 附加上下文（会被白名单过滤，杜绝敏感信息）。
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		if ( ! $this->enabled() ) {
			return;
		}

		$threshold = (string) $this->settings->get( 'log_level', 'error' );
		$weight    = isset( self::$weights[ $level ] ) ? self::$weights[ $level ] : 4;
		$limit     = isset( self::$weights[ $threshold ] ) ? self::$weights[ $threshold ] : 1;

		if ( $weight > $limit ) {
			return;
		}

		$dir = dirname( $this->file() );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// 日志轮转：超过 1MB 时截断，避免磁盘被写满。
		if ( is_file( $this->file() ) && filesize( $this->file() ) > 1048576 ) {
			@unlink( $this->file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$line = sprintf(
			'[%s] %s: %s %s',
			gmdate( 'Y-m-d\TH:i:s\Z' ),
			strtoupper( $level ),
			$this->redact( $message ),
			$context ? wp_json_encode( $this->redact_array( $context ) ) : ''
		);

		error_log( trim( $line ) . PHP_EOL, 3, $this->file() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * 便捷方法。
	 *
	 * @param string $message 消息。
	 * @param array  $context 上下文。
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * 便捷方法。
	 *
	 * @param string $message 消息。
	 * @param array  $context 上下文。
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * 便捷方法。
	 *
	 * @param string $message 消息。
	 * @param array  $context 上下文。
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * 便捷方法。
	 *
	 * @param string $message 消息。
	 * @param array  $context 上下文。
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * 读取最近 N 行日志（后台诊断页用）。
	 *
	 * @param int $lines 行数。
	 * @return array
	 */
	public function tail( $lines = 200 ) {
		$file = $this->file();

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return array();
		}

		$content = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( '' === $content ) {
			return array();
		}

		$all = preg_split( '/\r?\n/', trim( $content ) );

		return array_slice( $all, -1 * absint( $lines ) );
	}

	/**
	 * 清空日志。
	 *
	 * @return bool
	 */
	public function clear() {
		if ( is_file( $this->file() ) ) {
			return (bool) @unlink( $this->file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return true;
	}

	/**
	 * 单条消息脱敏。
	 *
	 * @param string $text 文本。
	 * @return string
	 */
	private function redact( $text ) {
		$text = (string) $text;

		// 常见密钥形态：长十六进制串、sk_/pk_ 前缀、Bearer token。
		$text = preg_replace( '/\b[0-9a-f]{32,}\b/i', '[redacted]', $text );
		$text = preg_replace( '/\b(?:sk|pk|rk)_[A-Za-z0-9_\-]{8,}/', '[redacted]', $text );
		$text = preg_replace( '/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $text );

		return $text;
	}

	/**
	 * 上下文数组脱敏。
	 *
	 * @param array $context 上下文。
	 * @return array
	 */
	private function redact_array( array $context ) {
		$blocked = array( 'cookie', 'authorization', 'password', 'secret', 'token', 'license', 'key', 'pass' );
		$out     = array();

		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );

			foreach ( $blocked as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					$out[ $key ] = '[redacted]';
					continue 2;
				}
			}

			$out[ $key ] = is_scalar( $value ) ? $this->redact( (string) $value ) : '[array]';
		}

		return $out;
	}
}

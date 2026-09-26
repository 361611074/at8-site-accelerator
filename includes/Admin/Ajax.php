<?php
/**
 * 后台 AJAX 处理器。
 *
 * 安全约定（计划书 §124 所有 AJAX 必须 `check_ajax_referer()`）：
 * 每个动作都先校验 nonce，再校验 `manage_options` 能力，最后才做业务。
 * 校验顺序不能反——先查权限再查 nonce 会让攻击者能通过响应差异探测权限模型。
 *
 * @package AT8\SiteAccelerator\Admin
 */

namespace AT8\SiteAccelerator\Admin;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Compatibility\CachePluginDetector;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Optimization\BrowserCache;
use AT8\SiteAccelerator\Optimization\DatabaseCleanup;
use AT8\SiteAccelerator\Purge\Purger;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax
 */
final class Ajax {

	/**
	 * nonce 动作名。
	 */
	const NONCE = 'at8sa_admin';

	/**
	 * 依赖。
	 *
	 * @var array<string, object>
	 */
	private $deps;

	/**
	 * 构造。
	 *
	 * @param array $deps 依赖映射。
	 */
	public function __construct( array $deps ) {
		$this->deps = $deps;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		$actions = array(
			'purge_all',
			'purge_url',
			'db_preview',
			'db_run',
			'install_dropin',
			'remove_dropin',
			'enable_wp_cache',
			'disable_wp_cache',
			'write_htaccess',
			'remove_htaccess',
			'clear_log',
			'rescan_conflicts',
			'export_settings',
			'import_settings',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_at8sa_' . $action, array( $this, 'dispatch_' . $action ) );
		}
	}

	/**
	 * 统一守卫：nonce + 能力。
	 *
	 * @return void 校验失败时直接终止请求。
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( '权限不足。', 'at8-site-accelerator' ) ),
				403
			);
		}
	}

	/**
	 * 取依赖。
	 *
	 * @param string $key 键。
	 * @return mixed
	 */
	private function dep( $key ) {
		return isset( $this->deps[ $key ] ) ? $this->deps[ $key ] : null;
	}

	/**
	 * 清空整站缓存。
	 *
	 * @return void
	 */
	public function dispatch_purge_all() {
		$this->guard();

		/** @var Purger $purger */
		$purger = $this->dep( 'purger' );

		if ( ! $purger ) {
			wp_send_json_error( array( 'message' => __( '缓存模块不可用。', 'at8-site-accelerator' ) ) );
		}

		$count = $purger->purge_all();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: number of purged entries */
					__( '缓存已清空（处理 %s 个缓存页）。', 'at8-site-accelerator' ),
					number_format_i18n( $count )
				),
				'count'   => $count,
			)
		);
	}

	/**
	 * 清空单个 URL 缓存。
	 *
	 * @return void
	 */
	public function dispatch_purge_url() {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( '请提供要清理的 URL。', 'at8-site-accelerator' ) ) );
		}

		/** @var Purger $purger */
		$purger = $this->dep( 'purger' );
		$count  = $purger ? $purger->purge_url( $url ) : 0;

		wp_send_json_success(
			array(
				'message' => $count > 0
					? __( '该 URL 的缓存已清理。', 'at8-site-accelerator' )
					: __( '该 URL 没有缓存记录（或不属于本站）。', 'at8-site-accelerator' ),
				'count'   => $count,
			)
		);
	}

	/**
	 * 数据库清理预览。
	 *
	 * @return void
	 */
	public function dispatch_db_preview() {
		$this->guard();

		/** @var DatabaseCleanup $cleanup */
		$cleanup = $this->dep( 'db_cleanup' );

		if ( ! $cleanup ) {
			wp_send_json_error( array( 'message' => __( '数据库模块不可用。', 'at8-site-accelerator' ) ) );
		}

		wp_send_json_success( array( 'items' => $cleanup->preview() ) );
	}

	/**
	 * 执行数据库清理。
	 *
	 * @return void
	 */
	public function dispatch_db_run() {
		$this->guard();

		/** @var DatabaseCleanup $cleanup */
		$cleanup = $this->dep( 'db_cleanup' );

		if ( ! $cleanup ) {
			wp_send_json_error( array( 'message' => __( '数据库模块不可用。', 'at8-site-accelerator' ) ) );
		}

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

		$result  = $cleanup->run();
		$preview = $cleanup->preview();
		$lines   = array();

		foreach ( $result as $key => $count ) {
			$label   = isset( $preview[ $key ]['label'] ) ? $preview[ $key ]['label'] : $key;
			$lines[] = $label . '：' . number_format_i18n( $count );
		}

		if ( empty( $lines ) ) {
			wp_send_json_error(
				array( 'message' => __( '没有任何清理项被勾选，请先在上方选择要清理的内容。', 'at8-site-accelerator' ) )
			);
		}

		wp_send_json_success(
			array(
				'message' => __( '清理完成。', 'at8-site-accelerator' ),
				'detail'  => $lines,
				'result'  => $result,
			)
		);
	}

	/**
	 * 安装 advanced-cache drop-in。
	 *
	 * @return void
	 */
	public function dispatch_install_dropin() {
		$this->guard();

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( ! $dropin || ! $dropin->install() ) {
			wp_send_json_error( array( 'message' => __( 'drop-in 写入失败，请检查 wp-content 目录权限。', 'at8-site-accelerator' ) ) );
		}

		/** @var Config $config_builder */
		$config_builder = $this->dep( 'config' );
		$config_builder->write( $config_builder->runtime() );

		wp_send_json_success(
			array(
				'message' => __( 'drop-in 已安装。还需确保 wp-config.php 中 WP_CACHE 为 true。', 'at8-site-accelerator' ),
			)
		);
	}

	/**
	 * 移除 drop-in。
	 *
	 * @return void
	 */
	public function dispatch_remove_dropin() {
		$this->guard();

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( ! $dropin || ! $dropin->uninstall() ) {
			wp_send_json_error( array( 'message' => __( 'drop-in 移除失败。', 'at8-site-accelerator' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'drop-in 已移除。' ) ) );
	}

	/**
	 * 启用 WP_CACHE。
	 *
	 * @return void
	 */
	public function dispatch_enable_wp_cache() {
		$this->guard();

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );
		$result = $dropin->enable_wp_cache();

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * 停用 WP_CACHE。
	 *
	 * @return void
	 */
	public function dispatch_disable_wp_cache() {
		$this->guard();

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );
		$result = $dropin->disable_wp_cache();

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * 写入 .htaccess 规则。
	 *
	 * @return void
	 */
	public function dispatch_write_htaccess() {
		$this->guard();

		/** @var BrowserCache $browser_cache */
		$browser_cache = $this->dep( 'browser_cache' );
		$result        = $browser_cache->write_htaccess();

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * 移除 .htaccess 规则。
	 *
	 * @return void
	 */
	public function dispatch_remove_htaccess() {
		$this->guard();

		/** @var BrowserCache $browser_cache */
		$browser_cache = $this->dep( 'browser_cache' );
		$result        = $browser_cache->remove_htaccess();

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * 清空日志。
	 *
	 * @return void
	 */
	public function dispatch_clear_log() {
		$this->guard();

		/** @var Logger $logger */
		$logger = $this->dep( 'logger' );
		$logger->clear();

		wp_send_json_success( array( 'message' => __( '日志已清空。', 'at8-site-accelerator' ) ) );
	}

	/**
	 * 重新扫描冲突。
	 *
	 * @return void
	 */
	public function dispatch_rescan_conflicts() {
		$this->guard();

		/** @var CachePluginDetector $detector */
		$detector = $this->dep( 'detector' );
		$detector->flush();
		$conflicts = $detector->scan( true );

		wp_send_json_success(
			array(
				'message' => empty( $conflicts )
					? __( '未发现已知冲突。', 'at8-site-accelerator' )
					: __( '检测完成，详见下方列表。', 'at8-site-accelerator' ),
				'count'   => count( $conflicts ),
			)
		);
	}

	/**
	 * 导出设置（JSON）。
	 *
	 * @return void
	 */
	public function dispatch_export_settings() {
		$this->guard();

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );

		wp_send_json_success(
			array(
				'version'  => AT8SA_VERSION,
				'exported' => gmdate( 'c' ),
				'settings' => $settings->all(),
			)
		);
	}

	/**
	 * 导入设置。
	 *
	 * @return void
	 */
	public function dispatch_import_settings() {
		$this->guard();

		// 这是 JSON 导入入口：payload 本身就是一段 JSON 文本，任何"净化"都会破坏它。
		// 安全性由后续三层保证：json_decode 严格解析 → 必须是数组且含 settings →
		// Settings::sanitize() 逐键按类型重建（未知键直接丢弃）。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';

		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_send_json_error( array( 'message' => __( 'JSON 格式不正确，需包含 settings 对象。', 'at8-site-accelerator' ) ) );
		}

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );

		// 强制走 sanitize：导入的 JSON 是不可信输入。
		$sanitized = $settings->sanitize( $data['settings'] );
		$settings->persist( $sanitized );

		/** @var BackendFactory $factory */
		$factory = $this->dep( 'factory' );
		$factory->reset_probe();

		/** @var Config $config_builder */
		$config_builder = $this->dep( 'config' );
		$config_builder->write( $config_builder->runtime() );

		do_action( 'at8sa_settings_saved' );

		wp_send_json_success( array( 'message' => __( '设置已导入并生效。', 'at8-site-accelerator' ) ) );
	}
}

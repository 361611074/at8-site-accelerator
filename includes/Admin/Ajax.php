<?php
/**
 * 后台 AJAX 处理器。
 *
 * 安全约定（计划书 §124 所有 AJAX 必须 `check_ajax_referer()`）：
 * 每个动作都先校验 nonce，再校验 `manage_options` 能力，最后才做业务。
 * 校验顺序不能反——先查权限再查 nonce 会让攻击者能通过响应差异探测权限模型。
 *
 * @package AT8SA\Admin
 */

namespace AT8SA\Admin;

use AT8SA\Cache\AdvancedCache;
use AT8SA\Cache\Config;
use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Compatibility\CachePluginDetector;
use AT8SA\Core\Settings;
use AT8SA\Optimization\BrowserCache;
use AT8SA\Optimization\DatabaseCleanup;
use AT8SA\Purge\Purger;
use AT8SA\Support\Logger;

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
	 * 依赖键由 `Plugin::boot_admin()` 一次性全量注册，所以正常路径下永远命中；
	 * 返回 null 只是给单元测试与将来按需注册留的退路。
	 *
	 * 因此下面各处理器分两类写法：
	 * - 带 `! $x` 判空的（清空缓存 / 库清理 / drop-in 装卸）：`@var` 标注为 `X|null`，
	 *   判空是真检查，不是摆设；
	 * - 不判空的（启用 WP_CACHE / .htaccess / 日志等）：`@var` 标注为非空，
	 *   依赖上面那条装配不变式。
	 *
	 * @param string $key 键。
	 * @return mixed 依赖实例；未注册时 null。
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

		/** @var Purger|null $purger */
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

		/** @var Purger|null $purger */
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

		/** @var DatabaseCleanup|null $cleanup */
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

		/** @var DatabaseCleanup|null $cleanup */
		$cleanup = $this->dep( 'db_cleanup' );

		if ( ! $cleanup ) {
			wp_send_json_error( array( 'message' => __( '数据库模块不可用。', 'at8-site-accelerator' ) ) );
		}

	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

	// 设置页的勾选框属于页面底部的「保存设置」表单，但用户习惯是勾选后直接点
	// 「执行清理」。数据库面板当前的勾选状态随本请求一并带来，先持久化再执行——
	// 否则 run() 读到的仍是上次保存的值，用户必然看到"没有任何清理项被勾选"。
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() 已完成 nonce 与能力校验。
	$at8sa_db_items = isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '';
	$this->persist_db_panel_items( $at8sa_db_items );

	$result = $cleanup->run();
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
	 * 把数据库面板随请求带来的勾选状态持久化到设置。
	 *
	 * 设计约束：
	 * - **白名单**：只接受 7 个 db_* 布尔键与 db_schedule 枚举键，items 里混入的
	 *   其它任何键（cache_backend、exclude_urls……）一律忽略，不可能借道本接口改写；
	 * - **复用 Settings::sanitize()**：布尔归一、枚举校验（非法 db_schedule 回退 off），
	 *   缺键保留当前已存值（sanitize 的局部更新语义），不会误动面板之外的设置；
	 * - **向后兼容**：items 缺失 / 非法 JSON / 空数组时静默跳过，行为与本版之前
	 *   完全一致（读取已保存的设置）。
	 *
	 * @param mixed $raw 原始 items（JSON 字符串，由 admin.js 序列化面板状态）。
	 * @return void
	 */
	private function persist_db_panel_items( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}

		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) ) {
			return;
		}

		$settings = $this->dep( 'settings' );

		if ( ! $settings instanceof Settings ) {
			return;
		}

		$booleans = array(
			'db_revisions',
			'db_auto_drafts',
			'db_trashed_posts',
			'db_spam_comments',
			'db_trashed_comments',
			'db_transients',
			'db_optimize',
		);

		$partial = array();

		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $items ) ) {
				$partial[ $key ] = empty( $items[ $key ] ) ? 0 : 1;
			}
		}

		if ( isset( $items['db_schedule'] ) && is_string( $items['db_schedule'] ) ) {
			$partial['db_schedule'] = $items['db_schedule'];
		}

		if ( empty( $partial ) ) {
			return;
		}

		$settings->persist( $settings->sanitize( $partial ) );
	}

	/**
	 * 安装 advanced-cache drop-in。
	 *
	 * @return void
	 */
	public function dispatch_install_dropin() {
		$this->guard();

		/** @var AdvancedCache|null $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( ! $dropin ) {
			wp_send_json_error( array( 'message' => __( 'drop-in 写入失败，请检查 wp-content 目录权限。', 'at8-site-accelerator' ) ) );
		}

		// 槽位被别的缓存系统占用：明确告知原因，而不是笼统报"写入失败"。
		// 这样用户知道该去停用哪个插件，而不是反复点"安装"然后一头雾水。
		if ( $dropin->has_foreign_dropin() ) {
			wp_send_json_error( array( 'message' => wp_kses_post( $dropin->blocked_reason() ) ) );
		}

		if ( ! $dropin->install() ) {
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

		/** @var AdvancedCache|null $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( ! $dropin || ! $dropin->uninstall() ) {
			wp_send_json_error( array( 'message' => __( 'drop-in 移除失败。', 'at8-site-accelerator' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'drop-in 已移除。', 'at8-site-accelerator' ) ) );
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

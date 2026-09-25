<?php
/**
 * 设置页控制器。
 *
 * 页面结构：顶部标签导航 + 单表单（所有标签页共用一个 <form>，一次保存全部生效）。
 * 这样做的好处是"保存"永远只有一个入口，用户不会因为切标签丢掉未保存的改动。
 *
 * @package AT8\SiteAccelerator\Admin
 */

namespace AT8\SiteAccelerator\Admin;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Compatibility\CachePluginDetector;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Diagnostics\Diagnostics;
use AT8\SiteAccelerator\Optimization\BrowserCache;
use AT8\SiteAccelerator\Optimization\DatabaseCleanup;
use AT8\SiteAccelerator\Purge\Purger;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class SettingsPage
 */
final class SettingsPage {

	/**
	 * 页面 slug。
	 */
	const SLUG = 'at8-site-accelerator';

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
	 * 取依赖。
	 *
	 * @param string $key 键。
	 * @return mixed
	 */
	private function dep( $key ) {
		return isset( $this->deps[ $key ] ) ? $this->deps[ $key ] : null;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'admin_post_at8sa_reset_settings', array( $this, 'handle_reset' ) );
		add_filter( 'plugin_action_links_' . AT8SA_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * 在"插件"列表页给本插件加一个直达"设置"的链接。
	 *
	 * @param array $links 现有链接。
	 * @return array
	 */
	public function action_links( $links ) {
		$links = is_array( $links ) ? $links : array();

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( '设置', 'at8-site-accelerator' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * 注册菜单。
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AT8 Site Accelerator', 'at8-site-accelerator' ),
			__( 'AT8 加速', 'at8-site-accelerator' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-performance',
			81
		);
	}

	/**
	 * 注册设置。
	 *
	 * @return void
	 */
	public function register_settings() {
		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );

		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $settings, 'sanitize' ),
				'default'           => $settings->defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * 资源入队。
	 *
	 * @param string $hook 当前页面 hook。
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'at8sa-admin', AT8SA_URL . 'assets/css/admin.css', array(), AT8SA_VERSION );
		wp_enqueue_script( 'at8sa-admin', AT8SA_URL . 'assets/js/admin.js', array(), AT8SA_VERSION, true );

		wp_localize_script(
			'at8sa-admin',
			'AT8SA_Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					'working'   => __( '处理中…', 'at8-site-accelerator' ),
					'failed'    => __( '请求失败，请重试。', 'at8-site-accelerator' ),
					'confirmDb' => __( '数据库清理不可撤销。确认按当前勾选项执行？', 'at8-site-accelerator' ),
					'confirmAll' => __( '确定清空整站缓存？', 'at8-site-accelerator' ),
					'copied'    => __( '已复制到剪贴板。', 'at8-site-accelerator' ),
				),
			)
		);
	}

	/**
	 * 设置保存后的副作用：重写 drop-in 配置、重置探测、清空缓存。
	 *
	 * 为什么要清空：TTL、后端、排除规则一变，旧缓存条目的语义就不成立了，
	 * 留着它们只会制造"设置改了但页面没变"的困惑。
	 *
	 * @param mixed $old 旧值。
	 * @param mixed $new 新值。
	 * @return void
	 */
	public function on_settings_updated( $old, $new ) {
		unset( $old, $new );

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );
		$settings->flush_cache();

		/** @var BackendFactory $factory */
		$factory = $this->dep( 'factory' );
		$factory->reset_probe();

		/** @var Config $config_builder */
		$config_builder = $this->dep( 'config' );
		$config_builder->write( $config_builder->runtime() );

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( $settings->is_on( 'advanced_cache' ) ) {
			$dropin->install();
		}

		/** @var Purger $purger */
		$purger = $this->dep( 'purger' );
		$purger->purge_all();
	}

	/**
	 * 重置为默认设置。
	 *
	 * @return void
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'at8-site-accelerator' ) );
		}

		check_admin_referer( 'at8sa_reset_settings' );

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );
		$settings->persist( $settings->defaults() );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'at8sa_reset' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 渲染页面。
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'at8-site-accelerator' ) );
		}

		$settings = $this->dep( 'settings' );
		$factory  = $this->dep( 'factory' );

		$backend = $factory->make();

		$data = array(
			'settings'      => $settings->all(),
			'boolean_keys'  => $settings->boolean_keys(),
			'backend'       => $backend,
			'backend_name'  => $backend ? $backend->name() : __( '未生效', 'at8-site-accelerator' ),
			'backend_stats' => $backend ? $backend->stats() : array(
				'count' => 0,
				'bytes' => 0,
			),
			'redis_reachable' => $factory->redis_probe(),
			'advanced_cache'  => $this->dep( 'advanced_cache' ),
			'diagnostics'     => $this->dep( 'diagnostics' )->collect(),
			'conflicts'       => $this->dep( 'detector' )->scan(),
			'conflict_notice' => $this->dep( 'detector' )->notice_text(),
			'browser_cache'   => $this->dep( 'browser_cache' ),
			'db_cleanup'      => $this->dep( 'db_cleanup' ),
			'db_preview'      => $this->dep( 'db_cleanup' )->preview(),
			'logger'          => $this->dep( 'logger' ),
			'log_lines'       => $this->dep( 'logger' )->tail( 200 ),
			'migrated'        => $settings->was_migrated(),
			'tabs'            => $this->tabs(),
			'nonce'           => wp_create_nonce( Ajax::NONCE ),
		);

		$this->render_template( 'settings-page.php', $data );
	}

	/**
	 * 标签定义。
	 *
	 * @return array
	 */
	private function tabs() {
		return array(
			'overview'  => __( '概览', 'at8-site-accelerator' ),
			'cache'     => __( '页面缓存', 'at8-site-accelerator' ),
			'purge'     => __( '失效与预加载', 'at8-site-accelerator' ),
			'optimize'  => __( '优化', 'at8-site-accelerator' ),
			'database'  => __( '数据库', 'at8-site-accelerator' ),
			'compat'    => __( '兼容与诊断', 'at8-site-accelerator' ),
			'tools'     => __( '工具', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 渲染模板（模板内可直接使用 $data 中的键）。
	 *
	 * @param string $file 模板文件名。
	 * @param array  $data 数据。
	 * @return void
	 */
	private function render_template( $file, array $data ) {
		$path = AT8SA_PATH . 'templates/' . $file;

		if ( ! is_readable( $path ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( '模板文件缺失。', 'at8-site-accelerator' ) . '</p></div>';

			return;
		}

		// 模板内用 $data 访问，避免污染全局作用域。
		include $path;
	}
}

<?php
/**
 * REST API 控制器：设置读写。
 *
 * 写入走 `Settings::sanitize()`，与后台表单完全同一条清洗路径——
 * 这样就不存在"通过 REST 绕过校验"的旁路（计划书 §124 所有输入必须 sanitize）。
 *
 * @package AT8SA\REST
 */

namespace AT8SA\REST;

use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\Config;
use AT8SA\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class SettingsController
 */
final class SettingsController {

	/**
	 * 命名空间。
	 */
	const NAMESPACE_V1 = 'at8sa/v1';

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 后端工厂。
	 *
	 * @var BackendFactory
	 */
	private $factory;

	/**
	 * 配置生成器。
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * 构造。
	 *
	 * @param Settings       $settings 设置。
	 * @param BackendFactory $factory  后端工厂。
	 * @param Config         $config   配置生成器。
	 */
	public function __construct( Settings $settings, BackendFactory $factory, Config $config ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->config   = $config;
	}

	/**
	 * 注册路由。
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'settings' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);
	}

	/**
	 * 权限回调。
	 *
	 * @return bool|\WP_Error
	 */
	public function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'at8sa_forbidden',
				__( '需要管理员权限。', 'at8-site-accelerator' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * 读取设置。
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return new \WP_REST_Response(
			array(
				'settings' => $this->settings->all(),
				'defaults' => $this->settings->defaults(),
				'version'  => AT8SA_VERSION,
			),
			200
		);
	}

	/**
	 * 更新设置。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$input = (array) $request->get_param( 'settings' );

		// 与后台表单同一清洗路径。
		$sanitized = $this->settings->sanitize( $input );
		$this->settings->persist( $sanitized );

		$this->factory->reset_probe();

		$runtime = $this->config->runtime();
		$this->config->write( $runtime );

		do_action( 'at8sa_settings_saved' );

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $sanitized,
			),
			200
		);
	}
}

<?php
/**
 * REST API 控制器：诊断信息。
 *
 * 只读，且只返回事实性数据（计划书 §61）。不会返回任何密钥、Cookie、
 * 数据库凭据或站点内容。
 *
 * @package AT8\SiteAccelerator\REST
 */

namespace AT8\SiteAccelerator\REST;

use AT8\SiteAccelerator\Diagnostics\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Class DiagnosticsController
 */
final class DiagnosticsController {

	/**
	 * 命名空间。
	 */
	const NAMESPACE_V1 = 'at8sa/v1';

	/**
	 * 诊断采集器。
	 *
	 * @var Diagnostics
	 */
	private $diagnostics;

	/**
	 * 构造。
	 *
	 * @param Diagnostics $diagnostics 诊断采集器。
	 */
	public function __construct( Diagnostics $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	/**
	 * 注册路由。
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_diagnostics' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
	 * 返回诊断数据。
	 *
	 * @return \WP_REST_Response
	 */
	public function get_diagnostics() {
		return new \WP_REST_Response(
			array(
				'version'     => AT8SA_VERSION,
				'generated'   => gmdate( 'c' ),
				'diagnostics' => $this->diagnostics->collect(),
			),
			200
		);
	}
}

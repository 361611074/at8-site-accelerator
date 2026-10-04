<?php
/**
 * REST API 控制器：缓存管理。
 *
 * 计划书 §124 要求所有 REST 必须声明 `permission_callback`。
 * 这里统一要求 `manage_options`——缓存清空是影响全站的操作，不开放给低权限角色。
 *
 * 命名空间：`at8sa/v1`
 *
 * @package AT8SA\REST
 */

namespace AT8SA\REST;

use AT8SA\Purge\Purger;

defined( 'ABSPATH' ) || exit;

/**
 * Class CacheController
 */
final class CacheController {

	/**
	 * 命名空间。
	 */
	const NAMESPACE_V1 = 'at8sa/v1';

	/**
	 * 失效器。
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * 构造。
	 *
	 * @param Purger $purger 失效器。
	 */
	public function __construct( Purger $purger ) {
		$this->purger = $purger;
	}

	/**
	 * 注册路由。
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/cache',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'purge_all' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/url',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge_url' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
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
	 * 清空整站缓存。
	 *
	 * @return \WP_REST_Response
	 */
	public function purge_all() {
		$count = $this->purger->purge_all();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'purged'  => $count,
			),
			200
		);
	}

	/**
	 * 清空单个 URL。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return \WP_REST_Response
	 */
	public function purge_url( $request ) {
		$url = (string) $request->get_param( 'url' );

		$this->purger->purge_url( $url );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'url'     => $url,
			),
			200
		);
	}

	/**
	 * 缓存状态。
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$backend = $this->purger->backend_status();

		return new \WP_REST_Response( $backend, 200 );
	}
}

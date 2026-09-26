<?php
/**
 * 极简服务容器。
 *
 * 只做两件事：登记工厂、按需单例解析。刻意不引入 league/container
 * 之类的依赖——插件体积与攻击面越小越好（计划书 §87 禁止打包无关依赖）。
 *
 * @package AT8\SiteAccelerator\Core
 */

namespace AT8\SiteAccelerator\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Container
 */
final class Container {

	/**
	 * 服务工厂表。
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * 已解析的单例。
	 *
	 * @var array<string, mixed>
	 */
	private $resolved = array();

	/**
	 * 登记一个服务工厂（惰性、单例）。
	 *
	 * @param string   $id      服务标识，约定为完整类名。
	 * @param callable $factory 接收本容器、返回服务实例的可调用对象。
	 * @return void
	 */
	public function bind( $id, $factory ) {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * 登记一个已构造好的实例。
	 *
	 * @param string $id      服务标识。
	 * @param mixed  $instance 实例。
	 * @return void
	 */
	public function instance( $id, $instance ) {
		$this->resolved[ $id ] = $instance;
	}

	/**
	 * 是否已登记。
	 *
	 * @param string $id 服务标识。
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->factories[ $id ] ) || isset( $this->resolved[ $id ] );
	}

	/**
	 * 解析服务。
	 *
	 * @param string $id 服务标识。
	 * @return mixed|null 未登记时返回 null，调用方必须自行判空。
	 */
	public function get( $id ) {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			return null;
		}

		$factory               = $this->factories[ $id ];
		$this->resolved[ $id ] = call_user_func( $factory, $this );

		return $this->resolved[ $id ];
	}
}

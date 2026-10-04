<?php
/**
 * 单元测试基类。
 *
 * 唯一的职责：把 WordPress 桩的全局状态在每个用例前后恢复干净。
 *
 * 为什么这件事必须显式做：
 * wp-stubs.php 用 `$GLOBALS['at8sa_test_options']` 模拟选项表，它是**进程级**的。
 * PHPUnit 默认在同一个进程里跑完所有用例，于是"上一个用例写进去的 option"
 * 会泄漏到下一个用例——典型症状是单跑绿、全跑红，或者反过来。
 * 所以这里在 setUp / tearDown 双向重置，而不是只在 setUp 里清一次。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Class TestCase
 */
abstract class TestCase extends BaseTestCase {

	/**
	 * 需要重置的桩全局变量。
	 *
	 * @var string[]
	 */
	private static $globals_to_reset = array(
		'at8sa_test_options',
		'at8sa_test_transients',
		'at8sa_test_actions',
		'at8sa_test_filters',
		'at8sa_test_scheduled',
		'at8sa_test_enqueued_scripts',
	);

	/**
	 * 请求相关超全局的原始快照。
	 *
	 * @var array<string, mixed>
	 */
	private $server_snapshot = array();

	/**
	 * Cookie 原始快照。
	 *
	 * @var array<string, mixed>
	 */
	private $cookie_snapshot = array();

	/**
	 * 每个用例开始前重置状态。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->server_snapshot = $_SERVER;
		$this->cookie_snapshot = $_COOKIE;

		$this->reset_wp_state();
	}

	/**
	 * 每个用例结束后恢复状态，避免污染后续用例。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_SERVER = $this->server_snapshot;
		$_COOKIE = $this->cookie_snapshot;

		$this->reset_wp_state();

		parent::tearDown();
	}

	/**
	 * 清空桩全局变量。
	 *
	 * @return void
	 */
	protected function reset_wp_state() {
		foreach ( self::$globals_to_reset as $key ) {
			$GLOBALS[ $key ] = array();
		}

		$GLOBALS['at8sa_test_is_admin']    = false;
		$GLOBALS['at8sa_test_can_manage']  = true;
		$GLOBALS['at8sa_test_logged_in']   = false;
		$GLOBALS['at8sa_test_doing_ajax']  = false;

		// 产品代码用这两个标记防重复处理，必须逐用例清掉。
		unset( $GLOBALS['at8sa_minify_done'] );
		unset( $GLOBALS['at8sa_runtime_ttl'] );
	}

	/**
	 * 设定当前请求的超全局。
	 *
	 * @param array $server $_SERVER 覆盖项。
	 * @return void
	 */
	protected function set_request( array $server = array() ) {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/';
		$_SERVER['HTTP_HOST']      = 'example.test';
		$_SERVER['SERVER_NAME']    = 'example.test';

		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
	}

	/**
	 * 设定请求 Cookie。
	 *
	 * @param array $cookies Cookie 名值对。
	 * @return void
	 */
	protected function set_cookies( array $cookies ) {
		$_COOKIE = $cookies;
	}

	/**
	 * 造一个 Settings 实例，并预置若干选项值。
	 *
	 * @param array $overrides 覆盖的选项（键 => 值）。
	 * @return \AT8SA\Core\Settings
	 */
	protected function make_settings( array $overrides = array() ) {
		$defaults = ( new \AT8SA\Core\Settings() )->defaults();
		$stored   = array_merge( $defaults, $overrides );

		$GLOBALS['at8sa_test_options'][ \AT8SA\Core\Settings::OPTION ] = $stored;

		return new \AT8SA\Core\Settings();
	}

	/**
	 * 造一个缓存运行时配置（RequestGuard::should_bypass 的入参）。
	 *
	 * @param array $overrides 覆盖项。
	 * @return array
	 */
	protected function make_runtime_config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'        => 1,
				'safe_mode'      => 0,
				'cache_logged_in' => 0,
				'cache_mobile'   => 1,
				'cookie_hash'    => COOKIEHASH,
				'excluded_paths' => \AT8SA\Cache\RequestGuard::default_excluded_paths(),
				'bypass_cookies' => \AT8SA\Cache\RequestGuard::default_bypass_cookies(),
				'ignore_query'   => array(),
			),
			$overrides
		);
	}
}

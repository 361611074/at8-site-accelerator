<?php
/**
 * 运行时配置的"过期检测"测试。
 *
 * 背景：`update_option_{$option}` 钩子只能覆盖走 `update_option()` 的写入路径。
 * 直接 `$wpdb->update()`、`wp option import`、站点迁移脚本、DB 层手工修改都不会触发它。
 * `Config::needs_refresh()` 是这层兜底——比对设置指纹，对不上就重写。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Support\Filesystem;
use AT8\SiteAccelerator\Support\Logger;

/**
 * Class ConfigStalenessTest
 */
final class ConfigStalenessTest extends TestCase {

	/**
	 * 运行时配置目录。
	 *
	 * @var string
	 */
	private $config_dir;

	/**
	 * 每个用例前清掉残留配置。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->config_dir = AT8SA_CACHE_ROOT . '/config';

		Filesystem::rrmdir( $this->config_dir );

		$this->set_request( array( 'HTTP_HOST' => 'example.test' ) );
	}

	/**
	 * 造一个 Config 实例。
	 *
	 * 每次都新建：真实场景里 `needs_refresh()` 是在**新请求**中执行的，
	 * 那时 Settings 实例是全新的、缓存反映的是当前库值。
	 *
	 * @param array $overrides 设置覆盖项。
	 * @return Config
	 */
	private function make_config( array $overrides = array() ) {
		$settings = $this->make_settings(
			array_merge(
				array(
					'page_cache'     => 1,
					'advanced_cache' => 0,
					'cache_backend'  => 'disk',
				),
				$overrides
			)
		);

		return new Config( $settings, new BackendFactory( $settings, new Logger( $settings ) ) );
	}

	/**
	 * 造一个 Config 实例，但**不触碰 option**。
	 *
	 * `make_config()` 会顺手把 option 重置成默认值（`make_settings()` 的行为），
	 * 因此凡是"改了 option 再验证"的用例都必须用这个版本，
	 * 否则刚写进去的值会被辅助函数自己冲掉，测试变成恒真。
	 *
	 * @return Config
	 */
	private function make_config_on_current_option() {
		$settings = new Settings();

		return new Config( $settings, new BackendFactory( $settings, new Logger( $settings ) ) );
	}

	/**
	 * 配置文件不存在时必须刷新。
	 *
	 * @return void
	 */
	public function test_needs_refresh_when_file_missing() {
		$this->assertTrue( $this->make_config()->needs_refresh() );
	}

	/**
	 * 刚写完不应判定为过期（否则每个请求都重写，白费 I/O）。
	 *
	 * @return void
	 */
	public function test_not_stale_right_after_write() {
		$config = $this->make_config();
		$config->write( $config->runtime() );

		$this->assertFalse( $config->needs_refresh() );
	}

	/**
	 * 核心回归：设置变了（且写入绕过了 update_option 钩子）必须判定为过期。
	 *
	 * @return void
	 */
	public function test_stale_after_settings_change() {
		$config = $this->make_config();
		$config->write( $config->runtime() );

		$this->assertFalse( $config->needs_refresh() );

		// 绕过 update_option() 直接改库：钩子不会触发，只有指纹兜底能发现。
		$stored                                            = get_option( Settings::OPTION );
		$stored['cache_ttl']                               = 12345;
		$GLOBALS['at8sa_test_options'][ Settings::OPTION ] = $stored;

		$this->assertSame( 12345, get_option( Settings::OPTION )['cache_ttl'], '前置条件：option 已改' );

		$this->assertTrue(
			$this->make_config_on_current_option()->needs_refresh(),
			'设置变了却仍判定为最新，会导致"改了设置不生效"'
		);
	}

	/**
	 * 老版本写下的配置没有指纹字段，必须视为过期以便补上。
	 *
	 * @return void
	 */
	public function test_stale_when_hash_field_absent() {
		$config = $this->make_config();
		$runtime = $config->runtime();

		unset( $runtime['settings_hash'] );

		Filesystem::mkdir_guarded( $this->config_dir );
		Filesystem::put_contents(
			$this->config_dir . '/example.test.php',
			"<?php\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $runtime, true ) . ";\n"
		);

		$this->assertTrue( $this->make_config()->needs_refresh() );
	}

	/**
	 * 指纹必须稳定：同一组设置反复计算应一致，不同设置必须不同。
	 *
	 * 否则会出现"每次请求都判定过期 → 无限重写"或"改了设置永远不重写"。
	 *
	 * @return void
	 */
	public function test_settings_hash_is_stable_and_discriminating() {
		$config = $this->make_config();

		$first = $config->runtime();

		$this->assertArrayHasKey( 'settings_hash', $first );

		// 同一组设置，重新构造对象后再算一次。
		$second = $this->make_config()->runtime();

		$this->assertSame(
			$first['settings_hash'],
			$second['settings_hash'],
			'同一组设置的指纹必须一致'
		);

		// 改一个会影响运行时行为的设置，指纹必须变化。
		$third = $this->make_config( array( 'cache_ttl' => 7200 ) )->runtime();

		$this->assertNotSame(
			$first['settings_hash'],
			$third['settings_hash'],
			'设置变化后指纹必须变化'
		);
	}

	/**
	 * `write()` 与 `needs_refresh()` 必须用同一套主机名归一化。
	 *
	 * 曾经 `Plugin::ensure_runtime_config()` 自己写了一份不同的正则，
	 * 导致"写 A 文件、查 B 文件"——本用例把这条不变式钉住。
	 *
	 * @return void
	 */
	public function test_write_and_refresh_agree_on_host() {
		$this->set_request( array( 'HTTP_HOST' => 'EXAMPLE.test' ) );

		$config = $this->make_config();
		$config->write( $config->runtime() );

		// 归一化后应是小写。
		$this->assertFileExists( $this->config_dir . '/example.test.php' );

		$this->assertFalse( $config->needs_refresh(), '同一主机名下写与读必须一致' );
	}
}

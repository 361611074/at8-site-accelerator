<?php
/**
 * SettingsSync 回归测试。
 *
 * 这组用例对应一个**真机实测到的缺陷**：
 * 「设置变更 → 重写 drop-in 运行时配置」原先挂在 `SettingsPage::boot()` 上，
 * 而那只在 `is_admin()` 为真时执行。于是 WP-CLI / WP-Cron / 其它插件里改设置
 * 完全不生效——连改 6 次 `cache_backend`，前台响应头始终走旧后端。
 *
 * 之所以此前没被测出来，是因为 WordPress 桩的 `do_action()` 是空实现、
 * `update_option()` 也不触发钩子，"钩子挂没挂上"在测试里恒为真。
 * 本文件同时覆盖了修好的桩和修好的产品代码。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Core\SettingsSync;
use AT8\SiteAccelerator\Purge\Purger;
use AT8\SiteAccelerator\Support\Filesystem;
use AT8\SiteAccelerator\Support\Logger;

/**
 * Class SettingsSyncTest
 */
final class SettingsSyncTest extends TestCase {

	/**
	 * 运行时配置目录。
	 *
	 * @var string
	 */
	private $config_dir;

	/**
	 * 每个用例前清掉上次留下的配置与缓存文件。
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
	 * 构造一套真实对象图。
	 *
	 * 刻意不用 mock：这里要验证的正是"各部件按什么顺序真实协作"，
	 * 换成 mock 就等于把被测的协作关系又假定了一遍。
	 *
	 * 设置里显式指定 `cache_backend = 'disk'`、`advanced_cache = 0`：
	 * 前者避开 Redis 探测（无外部依赖），后者避开对 wp-config.php 的改写。
	 *
	 * @param array $overrides 设置覆盖项。
	 * @return array{0: SettingsSync, 1: Settings, 2: Config}
	 */
	private function make_graph( array $overrides = array() ) {
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

		$logger  = new Logger( $settings );
		$factory = new BackendFactory( $settings, $logger );
		$config  = new Config( $settings, $factory );

		$sync = new SettingsSync(
			$settings,
			$factory,
			$config,
			new AdvancedCache( $logger ),
			new Purger( $settings, $factory, $logger )
		);

		return array( $sync, $settings, $config );
	}

	/**
	 * 读回落盘的运行时配置。
	 *
	 * @return array
	 */
	private function read_config() {
		$file = $this->config_dir . '/example.test.php';

		$this->assertFileExists( $file, '运行时配置文件应已写出' );

		$data = include $file;

		$this->assertIsArray( $data, '运行时配置必须是数组' );

		return $data;
	}

	/**
	 * boot() 必须把钩子挂到 update_option_<option> 上，且声明 2 个入参。
	 *
	 * @return void
	 */
	public function test_boot_registers_update_option_hook() {
		list( $sync ) = $this->make_graph();

		$sync->boot();

		$hook = 'update_option_' . Settings::OPTION;

		$this->assertArrayHasKey( $hook, $GLOBALS['at8sa_test_actions'] );
		$this->assertSame( array( $sync, 'on_settings_updated' ), $GLOBALS['at8sa_test_actions'][ $hook ][0][0] );
		$this->assertSame( 2, $GLOBALS['at8sa_test_actions'][ $hook ][0][2], '必须声明接收 2 个参数' );
	}

	/**
	 * sync() 应把运行时配置落盘。
	 *
	 * @return void
	 */
	public function test_sync_writes_runtime_config() {
		list( $sync ) = $this->make_graph();

		$this->assertFileDoesNotExist( $this->config_dir . '/example.test.php' );

		$sync->sync();

		$data = $this->read_config();

		$this->assertSame( 'disk', $data['backend'] );
		$this->assertSame( 1, $data['enabled'] );
	}

	/**
	 * 核心回归：**绕过 Settings 实例缓存**直接写库后，sync() 必须读到新值。
	 *
	 * 这正是真机上失效的那条路径——`wp option update` 直接改库，
	 * 而 Settings 实例里还留着旧数组。
	 *
	 * @return void
	 */
	public function test_sync_picks_up_value_written_behind_settings_cache() {
		list( $sync, $settings ) = $this->make_graph();

		// 先让 Settings 把旧值读进实例缓存。
		$this->assertSame( 'disk', $settings->get( 'cache_backend' ) );

		// 模拟"外部直接写库"：只改 option，不碰 Settings 实例。
		$stored                  = get_option( Settings::OPTION );
		$stored['cache_backend'] = 'redis';
		update_option( Settings::OPTION, $stored );

		// 此刻 Settings 的实例缓存仍是旧值 —— 这是缺陷的温床。
		$this->assertSame( 'disk', $settings->get( 'cache_backend' ), '前置条件：实例缓存尚未刷新' );

		$sync->sync();

		$this->assertSame(
			'redis',
			$this->read_config()['backend'],
			'sync() 必须先刷新设置缓存，否则写出的仍是旧配置'
		);
	}

	/**
	 * 端到端回归：boot() 之后 `update_option()` 应自动触发同步。
	 *
	 * 这是对真机缺陷最直接的复现：不调用任何后台代码，只改 option。
	 *
	 * @return void
	 */
	public function test_update_option_triggers_sync_end_to_end() {
		list( $sync ) = $this->make_graph();

		$sync->boot();
		$sync->sync();

		$this->assertSame( 'disk', $this->read_config()['backend'] );

		$stored                  = get_option( Settings::OPTION );
		$stored['cache_backend'] = 'redis';

		// 关键：只改 option，不手动调 sync()。
		update_option( Settings::OPTION, $stored );

		$this->assertSame(
			'redis',
			$this->read_config()['backend'],
			'update_option() 必须经由钩子把新配置写进运行时配置'
		);
	}

	/**
	 * 值未变化时不应触发同步（与 WP 行为一致）。
	 *
	 * @return void
	 */
	public function test_unchanged_option_does_not_trigger_sync() {
		list( $sync ) = $this->make_graph();

		$sync->boot();

		$stored = get_option( Settings::OPTION );

		$this->assertFalse( update_option( Settings::OPTION, $stored ), '值没变时 update_option 应返回 false' );
		$this->assertFileDoesNotExist(
			$this->config_dir . '/example.test.php',
			'值没变就不该产生任何副作用'
		);
	}

	/**
	 * on_settings_updated() 应等价于 sync()。
	 *
	 * @return void
	 */
	public function test_on_settings_updated_delegates_to_sync() {
		list( $sync ) = $this->make_graph();

		$sync->on_settings_updated( array(), array() );

		$this->assertSame( 'disk', $this->read_config()['backend'] );
	}

	/**
	 * 换后端后旧条目必须被清掉。
	 *
	 * 否则会出现"配置指向磁盘、条目却留在 Redis"的错配。
	 *
	 * @return void
	 */
	public function test_sync_purges_stale_entries() {
		list( $sync, $settings ) = $this->make_graph();

		$backend = ( new BackendFactory( $settings, new Logger( $settings ) ) )->make();

		$this->assertNotFalse( $backend, 'disk 后端应当可用' );

		$backend->set( 'example.test', '/', '<html>cached</html>', 3600 );

		$this->assertNotFalse( $backend->get( 'example.test', '/' ), '前置条件：缓存已写入' );

		$sync->sync();

		$this->assertFalse(
			$backend->get( 'example.test', '/' ),
			'sync() 后旧条目必须被清掉，避免后端切换留下错配缓存'
		);
	}
}

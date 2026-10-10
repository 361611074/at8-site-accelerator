<?php
/**
 * 数据库清理面板"勾选即执行"回归测试（silkuasilk.com 2026-10-09 反馈）。
 *
 * 事故：设置页数据库面板的勾选框属于页面底部的「保存设置」表单，而「执行清理」
 * 是独立 AJAX。3.0.6.1 及之前，服务端 `DatabaseCleanup::run()` 只读**已保存**的
 * 设置——用户勾选后直接点执行，服务端看到的仍是"全关"，必然误报
 * "没有任何清理项被勾选"。
 *
 * 修复：admin.js 把面板当前勾选状态随请求发送；`Ajax::dispatch_db_run()` 先经
 * `persist_db_panel_items()` 持久化再执行。本文件验证持久化逻辑的三条边界：
 * 1. 合法 items 正确落库（勾选生效，未勾选项显式关闭）；
 * 2. 白名单：items 里混入的其它设置键一律被忽略；
 * 3. 局部更新：只带部分键时，缺键保留当前值（sanitize 语义），不误动其它设置。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Admin\Ajax;
use AT8SA\Core\Settings;

/**
 * Class DbPanelSyncTest
 */
final class DbPanelSyncTest extends TestCase {

	/**
	 * 被测对象。
	 *
	 * @var Ajax
	 */
	private $ajax;

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 准备环境。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings = $this->make_settings(
			array(
				'db_revisions'  => 0,
				'db_transients' => 1,
				'cache_backend' => 'disk',
			)
		);

		$this->ajax = new Ajax(
			array(
				'settings'   => $this->settings,
				'db_cleanup' => null,
			)
		);
	}

	/**
	 * 调用私有方法 persist_db_panel_items()。
	 *
	 * @param mixed $raw 原始输入。
	 * @return void
	 */
	private function persist( $raw ) {
		$method = new \ReflectionMethod( Ajax::class, 'persist_db_panel_items' );
		$method->setAccessible( true );
		$method->invoke( $this->ajax, $raw );
	}

	/**
	 * Test 1：合法 items 正确落库。
	 *
	 * @return void
	 */
	public function test_valid_items_are_persisted() {
		$this->persist(
			wp_json_encode(
				array(
					'db_revisions' => 1,
					'db_transients' => 0,
				)
			)
		);

		$this->assertTrue( $this->settings->is_on( 'db_revisions' ), '勾选的项应被保存为开' );
		$this->assertFalse( $this->settings->is_on( 'db_transients' ), '未勾选的项应被显式保存为关（表单 hidden 0 语义）' );
	}

	/**
	 * Test 2：白名单——items 混入其它设置键必须被忽略。
	 *
	 * @return void
	 */
	public function test_non_db_keys_are_ignored() {
		$this->persist(
			wp_json_encode(
				array(
					'db_revisions'  => 1,
					'cache_backend' => 'redis',
					'log_level'     => 'debug',
				)
			)
		);

		$this->assertTrue( $this->settings->is_on( 'db_revisions' ), 'db_* 键应生效' );
		$this->assertSame( 'disk', $this->settings->get( 'cache_backend' ), 'cache_backend 不得被 items 改写' );
		$this->assertSame( 'error', $this->settings->get( 'log_level' ), 'log_level 不得被 items 改写' );
	}

	/**
	 * Test 3：局部更新——缺键保留当前值，不误关面板之外的勾选。
	 *
	 * @return void
	 */
	public function test_partial_items_keep_current_values() {
		$this->persist( wp_json_encode( array( 'db_revisions' => 1 ) ) );

		$this->assertTrue( $this->settings->is_on( 'db_revisions' ), '提供的键应生效' );
		$this->assertTrue( $this->settings->is_on( 'db_transients' ), '未提供的键保留当前值（sanitize 局部更新语义）' );
	}

	/**
	 * Test 4：非法输入静默跳过，不改动任何设置。
	 *
	 * @return void
	 */
	public function test_invalid_input_is_silently_ignored() {
		foreach ( array( '', 'not-json', '"a string"', '23', wp_json_encode( 'array' ) ) as $raw ) {
			$this->persist( $raw );
		}

		$this->assertFalse( $this->settings->is_on( 'db_revisions' ), '非法输入不得改动设置' );
		$this->assertSame( 'disk', $this->settings->get( 'cache_backend' ) );
	}

	/**
	 * Test 5：db_schedule 枚举校验——合法值落库，非法值回退 off。
	 *
	 * @return void
	 */
	public function test_db_schedule_enum_is_validated() {
		$this->persist( wp_json_encode( array( 'db_schedule' => 'weekly' ) ) );
		$this->assertSame( 'weekly', $this->settings->get( 'db_schedule' ), '合法枚举值应落库' );

		$this->persist( wp_json_encode( array( 'db_schedule' => 'hourly-everything' ) ) );
		$this->assertSame( 'off', $this->settings->get( 'db_schedule' ), '非法枚举值应回退默认 off' );
	}
}

<?php
/**
 * Settings 单元测试。
 *
 * 两类风险在这里被拦住：
 * 1. **开关关不掉**：`sanitize()` 对"缺键"的处理是"保留原值"（为了让 REST /
 *    导入这类局部更新不会误关功能）。这个设计一旦写错，用户在后台点开关会毫无反应。
 * 2. **升级丢设置**：`migrate_from_legacy()` 必须幂等、必须保留旧选项（可回滚）。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Core\Settings;

/**
 * Class SettingsTest
 */
final class SettingsTest extends TestCase {

	/**
	 * 默认值必须自洽：布尔键都有默认值，枚举键取值都在白名单内。
	 *
	 * @return void
	 */
	public function test_defaults_are_self_consistent() {
		$settings = new Settings();
		$defaults = $settings->defaults();

		$this->assertNotEmpty( $defaults );

		foreach ( $settings->boolean_keys() as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "布尔键 {$key} 没有默认值" );
			$this->assertContains(
				(int) $defaults[ $key ],
				array( 0, 1 ),
				"布尔键 {$key} 的默认值不是 0/1"
			);
		}
	}

	/**
	 * 默认值里不允许出现"开关开着但没消费方"的键——这里只做基本形态校验。
	 *
	 * @return void
	 */
	public function test_default_enums_are_valid() {
		$defaults = ( new Settings() )->defaults();

		$this->assertContains( $defaults['cache_backend'], array( 'auto', 'disk', 'redis' ) );
		$this->assertContains( $defaults['purge_scope'], array( 'related', 'all' ) );
		$this->assertContains( $defaults['heartbeat'], array( 'default', 'reduce', 'disable' ) );
		$this->assertContains( $defaults['db_schedule'], array( 'off', 'daily', 'weekly' ) );
		$this->assertContains( $defaults['log_level'], array( 'error', 'warning', 'info', 'debug' ) );
	}

	/**
	 * get() 对未知键返回回退值。
	 *
	 * @return void
	 */
	public function test_get_returns_fallback_for_unknown_key() {
		$settings = $this->make_settings();

		$this->assertNull( $settings->get( 'at8sa_no_such_key' ) );
		$this->assertSame( 'x', $settings->get( 'at8sa_no_such_key', 'x' ) );
	}

	/**
	 * 已存值优先于默认值。
	 *
	 * @return void
	 */
	public function test_stored_value_wins_over_default() {
		$settings = $this->make_settings( array( 'cache_ttl' => 7200 ) );

		$this->assertSame( 7200, (int) $settings->get( 'cache_ttl' ) );
	}

	/**
	 * is_on() 的语义是"非空即开"。
	 *
	 * @return void
	 */
	public function test_is_on() {
		$settings = $this->make_settings(
			array(
				'page_cache' => 1,
				'safe_mode'  => 0,
				'log_enabled' => '',
			)
		);

		$this->assertTrue( $settings->is_on( 'page_cache' ) );
		$this->assertFalse( $settings->is_on( 'safe_mode' ) );
		$this->assertFalse( $settings->is_on( 'log_enabled' ) );
		$this->assertFalse( $settings->is_on( 'at8sa_no_such_key' ), '未知键视为关闭' );
	}

	/**
	 * 布尔键被归一化成 0 / 1。
	 *
	 * @return void
	 */
	public function test_sanitize_normalizes_booleans() {
		$settings = $this->make_settings();

		$out = $settings->sanitize(
			array(
				'page_cache'  => '1',
				'safe_mode'   => '0',
				'html_minify' => 5,
				'lazyload'    => '',
			)
		);

		$this->assertSame( 1, $out['page_cache'] );
		$this->assertSame( 0, $out['safe_mode'] );
		$this->assertSame( 1, $out['html_minify'], '非零真值归一为 1' );
		$this->assertSame( 0, $out['lazyload'] );
	}

	/**
	 * **关键设计**：缺键时保留已存值，而不是回退默认值。
	 *
	 * 这条守的是"局部更新"场景：REST 只提交一个开关、或导入只带部分键时，
	 * 其它开关不能被悄悄重置。写反了的表现是"用户导入设置后一堆功能被打开/关闭"。
	 *
	 * @return void
	 */
	public function test_sanitize_preserves_current_value_for_missing_keys() {
		$settings = $this->make_settings(
			array(
				'page_cache' => 0,
				'cache_ttl'  => 7200,
			)
		);

		$out = $settings->sanitize( array() );

		$this->assertSame( 0, $out['page_cache'], '缺键必须保留 0，不能被默认值 1 覆盖' );
		$this->assertSame( 7200, $out['cache_ttl'], '缺键必须保留已存 TTL' );
	}

	/**
	 * 非法枚举回退默认值。
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_invalid_enums() {
		$out = $this->make_settings()->sanitize(
			array(
				'cache_backend' => 'memcached',
				'purge_scope'   => 'everything',
				'heartbeat'     => 'off',
				'db_schedule'   => 'hourly',
				'log_level'     => 'trace',
			)
		);

		$this->assertSame( 'auto', $out['cache_backend'] );
		$this->assertSame( 'related', $out['purge_scope'] );
		$this->assertSame( 'reduce', $out['heartbeat'] );
		$this->assertSame( 'off', $out['db_schedule'] );
		$this->assertSame( 'error', $out['log_level'] );
	}

	/**
	 * 合法枚举原样保留。
	 *
	 * @return void
	 */
	public function test_sanitize_accepts_valid_enums() {
		$out = $this->make_settings()->sanitize(
			array(
				'cache_backend' => 'redis',
				'purge_scope'   => 'all',
				'heartbeat'     => 'disable',
				'db_schedule'   => 'weekly',
				'log_level'     => 'debug',
			)
		);

		$this->assertSame( 'redis', $out['cache_backend'] );
		$this->assertSame( 'all', $out['purge_scope'] );
		$this->assertSame( 'disable', $out['heartbeat'] );
		$this->assertSame( 'weekly', $out['db_schedule'] );
		$this->assertSame( 'debug', $out['log_level'] );
	}

	/**
	 * 整数被钳制到区间内。
	 *
	 * @param mixed $input    输入值。
	 * @param int   $expected 期望。
	 * @return void
	 * @dataProvider provide_clamped_ints
	 */
	public function test_sanitize_clamps_ints( $input, $expected ) {
		$out = $this->make_settings()->sanitize( array( 'cache_ttl' => $input ) );

		$this->assertSame( $expected, $out['cache_ttl'] );
	}

	/**
	 * 钳制用例。
	 *
	 * @return array
	 */
	public static function provide_clamped_ints() {
		return array(
			'低于下界被抬到 60'   => array( 10, 60 ),
			'零被抬到 60'         => array( 0, 60 ),
			'负数被抬到 60'       => array( -100, 60 ),
			'区间内原样保留'      => array( 7200, 7200 ),
			'高于上界被压到 30 天' => array( 99999999, 86400 * 30 ),
			'空串回退默认 3600'   => array( '', 3600 ),
			'非数字串转 0 再抬到 60' => array( 'abc', 60 ),
		);
	}

	/**
	 * 预加载策略走白名单，非法值被剔除；全非法则回退默认三项。
	 *
	 * @return void
	 */
	public function test_sanitize_filters_preload_strategy() {
		$settings = $this->make_settings();

		$out = $settings->sanitize( array( 'preload_strategy' => array( 'prefetch', 'evil', 'prerender' ) ) );

		$this->assertSame( array( 'prefetch', 'prerender' ), $out['preload_strategy'] );

		$out = $settings->sanitize( array( 'preload_strategy' => array( 'evil' ) ) );

		$this->assertSame(
			array( 'prefetch', 'preconnect', 'dns-prefetch' ),
			$out['preload_strategy'],
			'全非法时回退默认三项'
		);
	}

	/**
	 * 文本字段被去标签（防 XSS 通过设置回显）。
	 *
	 * @return void
	 */
	public function test_sanitize_strips_tags_from_text_fields() {
		$out = $this->make_settings()->sanitize(
			array(
				'exclude_urls'   => "/a\n<script>alert(1)</script>",
				'bypass_cookies' => '<b>x</b>',
				'ignore_query'   => '<i>ref</i>',
			)
		);

		$this->assertStringNotContainsString( '<script', $out['exclude_urls'] );
		$this->assertStringNotContainsString( '<b>', $out['bypass_cookies'] );
		$this->assertStringNotContainsString( '<i>', $out['ignore_query'] );
	}

	/**
	 * 非数组输入不致命（表单被篡改时也要能返回一份完整设置）。
	 *
	 * @return void
	 */
	public function test_sanitize_survives_non_array_input() {
		$out = $this->make_settings()->sanitize( 'not-an-array' );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'page_cache', $out );
	}

	/**
	 * sanitize() 的输出永远包含全部默认键。
	 *
	 * 少了键，下游 `$settings->get()` 就会拿到 null，进而出现"设置读不到"的怪现象。
	 *
	 * @return void
	 */
	public function test_sanitize_output_contains_all_default_keys() {
		$settings = new Settings();

		$out = $settings->sanitize( array() );

		foreach ( array_keys( $settings->defaults() ) as $key ) {
			$this->assertArrayHasKey( $key, $out, "sanitize() 输出缺少键 {$key}" );
		}
	}

	/**
	 * 没有旧选项时不执行迁移，但会打标记（避免每次请求都去查旧选项）。
	 *
	 * @return void
	 */
	public function test_migrate_without_legacy_is_a_noop_but_flags() {
		$settings = new Settings();

		$this->assertFalse( $settings->migrate_from_legacy() );
		$this->assertTrue( $settings->was_migrated() );
	}

	/**
	 * 迁移把旧值搬过来，并处理改名键。
	 *
	 * @return void
	 */
	public function test_migrate_copies_values_and_renames_keys() {
		$GLOBALS['at8sa_test_options'][ Settings::LEGACY_OPTION ] = array(
			'page_cache' => 0,
			'cache_ttl'  => 7200,
			'http2_push' => 1,
		);

		$settings = new Settings();

		$this->assertTrue( $settings->migrate_from_legacy() );
		$this->assertSame( 0, (int) $settings->get( 'page_cache' ) );
		$this->assertSame( 7200, (int) $settings->get( 'cache_ttl' ) );
		$this->assertSame( 1, (int) $settings->get( 'resource_preload' ), 'http2_push 的值搬到 resource_preload' );
	}

	/**
	 * 迁移后旧选项必须保留——这是"可回滚"的前提。
	 *
	 * @return void
	 */
	public function test_migrate_keeps_legacy_option_for_rollback() {
		$legacy = array( 'page_cache' => 0 );

		$GLOBALS['at8sa_test_options'][ Settings::LEGACY_OPTION ] = $legacy;

		( new Settings() )->migrate_from_legacy();

		$this->assertSame(
			$legacy,
			get_option( Settings::LEGACY_OPTION ),
			'旧选项被删掉就再也回不去了'
		);
	}

	/**
	 * 迁移幂等：第二次调用直接返回 false，不重复搬运。
	 *
	 * @return void
	 */
	public function test_migrate_is_idempotent() {
		$GLOBALS['at8sa_test_options'][ Settings::LEGACY_OPTION ] = array( 'page_cache' => 0 );

		$this->assertTrue( ( new Settings() )->migrate_from_legacy() );
		$this->assertFalse( ( new Settings() )->migrate_from_legacy(), '第二次必须短路' );
	}

	/**
	 * 迁移会写入 MIGRATED_FLAG 版本号。
	 *
	 * @return void
	 */
	public function test_migrate_records_flag() {
		$GLOBALS['at8sa_test_options'][ Settings::LEGACY_OPTION ] = array( 'page_cache' => 0 );

		( new Settings() )->migrate_from_legacy();

		$this->assertSame( AT8SA_VERSION, get_option( Settings::MIGRATED_FLAG ) );
	}

	/**
	 * persist() 之后 get() 立即读到新值（缓存同步，不需要新实例）。
	 *
	 * @return void
	 */
	public function test_persist_updates_runtime_cache() {
		$settings = $this->make_settings( array( 'cache_ttl' => 3600 ) );

		$settings->persist( $settings->sanitize( array( 'cache_ttl' => 7200 ) ) );

		$this->assertSame( 7200, (int) $settings->get( 'cache_ttl' ), 'persist 后必须刷新实例缓存' );
		$this->assertSame( 7200, (int) get_option( Settings::OPTION )['cache_ttl'] );
	}
}

<?php
/**
 * drop-in 归属判定收紧测试（3.0.6.4 / WordPress.org 终审 P1）。
 *
 * 归属判定从"包含插件名字符串"收紧为：
 * 1. `Owner: at8-site-accelerator` 主标记；或
 * 2. 插件名全称标记 **与** 版本戳标签同时存在（兼容 ≤3.0.6.3 旧安装）。
 *
 * 只在注释里提到本插件名的第三方 drop-in **不得**被认定为本插件所有——
 * 这是"绝不误删别人文件"铁律的判定层。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\AdvancedCache;

/**
 * Class DropinOwnershipTest
 */
final class DropinOwnershipTest extends TestCase {

	/**
	 * 判定矩阵：是我们的文件。
	 *
	 * @return void
	 */
	public function test_our_markers_are_recognized() {
		$this->assertTrue(
			AdvancedCache::head_is_ours( "<?php\n/**\n * AT8 Site Accelerator —— advanced-cache.php drop-in 模板。\n * Owner: at8-site-accelerator\n * @at8sa-dropin-version 3.0.6.4\n */\n" ),
			'带 Owner 主标记的 drop-in 必须识别为 ours'
		);

		$this->assertTrue(
			AdvancedCache::head_is_ours( "<?php\n/**\n * AT8 Site Accelerator —— advanced-cache.php drop-in。\n * @at8sa-dropin-version 3.0.5\n */\n// 旧版安装（无 Owner 行）\n" ),
			'旧版（插件名全称 + 版本戳组合）必须兼容识别为 ours'
		);

		// 真模板必须被识别。
		$tpl = (string) file_get_contents( dirname( __DIR__, 2 ) . '/templates/advanced-cache.php' );
		$this->assertTrue( AdvancedCache::head_is_ours( $tpl ), '自带的 drop-in 模板必须是 ours' );
		$this->assertStringContainsString( 'Owner: at8-site-accelerator', $tpl, '模板必须携带 Owner 主标记' );
	}

	/**
	 * 判定矩阵：不是我们的文件——包括"提到本插件名"的第三方 drop-in。
	 *
	 * @return void
	 */
	public function test_foreign_or_ambiguous_heads_are_rejected() {
		$foreign = array(
			'' => '空串',
			"<?php\n/* WP Super Cache drop-in */\nreturn true;\n" => '其它缓存插件',
			// 关键场景：第三方文件只在注释里提到本插件名——旧判定会误认。
			"<?php\n/**\n * Compatibility shim for AT8 Site Accelerator.\n * W3 Total Cache drop-in.\n */\nreturn true;\n" => '提到插件名的第三方文件',
			// 只有版本戳、没有插件名全称标记的（截断/损坏文件）。
			"<?php\n/* @at8sa-dropin-version 3.0.6.4 */\nreturn true;\n" => '只有版本戳',
			// 只有插件名全称标记、没有版本戳（人为构造）。
			"<?php\n/* AT8 Site Accelerator —— advanced-cache.php drop-in */\nreturn true;\n" => '只有插件名标记',
		);

		foreach ( $foreign as $head => $label ) {
			$this->assertFalse(
				AdvancedCache::head_is_ours( $head ),
				'以下文件不得被认定为本插件所有：' . $label
			);
		}
	}

	/**
	 * 卸载脚本的判定条件必须与 AdvancedCache::head_is_ours() 同步。
	 *
	 * uninstall.php 在插件代码不可用的上下文里运行，判定逻辑是内联副本；
	 * 这里用源码断言钉住两者的一致性，防止改了一处忘了另一处。
	 *
	 * @return void
	 */
	public function test_uninstall_script_stays_in_sync() {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString(
			'Owner: at8-site-accelerator',
			$src,
			'uninstall.php 必须使用 Owner 主标记'
		);
		$this->assertStringContainsString(
			'@at8sa-dropin-version',
			$src,
			'uninstall.php 的旧版兼容路径必须要求版本戳'
		);
		$this->assertStringContainsString(
			'head_is_ours',
			$src,
			'uninstall.php 必须注明与 head_is_ours() 保持一致'
		);

		// 旧的宽松单标记判定不得回归：禁止"只查插件名就删"。
		$this->assertStringNotContainsString(
			"if ( false !== strpos( \$at8sa_head, 'AT8 Site Accelerator' ) ) {",
			$src,
			'宽松的旧判定必须已被移除'
		);
	}
}

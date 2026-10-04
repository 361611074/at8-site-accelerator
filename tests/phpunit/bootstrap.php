<?php
/**
 * PHPUnit 引导文件。
 *
 * 复用 tests/unit/wp-stubs.php 的 WordPress 函数桩，而不是引一套新的——
 * 两套桩必然漂移，漂移之后"冒烟通过、单元失败"这类假信号会浪费大量排查时间。
 *
 * @package AT8SA\Tests
 */

// phpcs:disable

require_once __DIR__ . '/../unit/wp-stubs.php';

// 与 smoke.php 完全一致的 PSR-4 风格自动加载映射：
// AT8SA\Cache\CachePath → includes/Cache/CachePath.php
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AT8SA\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$file = AT8SA_PATH . 'includes/' . str_replace( '\\', '/', substr( $class, $length ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// 测试基类文件名不以 Test.php 结尾（否则 PHPUnit 会把它当用例类扫描），
// 所以必须在这里显式加载。
require_once __DIR__ . '/TestCase.php';

<?php
/**
 * drop-in 命中路径的**真实**执行夹具（子进程脚本）。
 *
 * 为什么必须单开一个进程：
 * `advanced-cache.php` 命中时会 `exit`，在同一个进程里跑会把测试框架一起带走。
 * 所以由 smoke.php 用 proc_open 拉起本脚本，父进程只看 stdout 与退出码。
 *
 * 为什么值得单独测：
 * drop-in 是**唯一**在"插件、主题、WordPress 函数全都还不存在"时运行的代码。
 * 它一旦抛致命错误，表现是整站白屏（而且是缓存命中率最高的那条路径），
 * 属于本项目风险最高的 100 行代码。
 *
 * 用法：
 *   php dropin-hit.php <mode>                     —— 命令行直接跑
 *   echo '<bootstrap>' | php                       —— 由 smoke.php 从 stdin 喂进来
 *
 * 场景：hit / miss / post / preview / safe_mode / no_config / mobile / no_plugin
 *
 * stdout 契约：
 *   命中 → 原样输出缓存内容（随后 exit）
 *   未命中 → 输出 "AT8SA_DROPIN_FELL_THROUGH"
 *
 * @package AT8\SiteAccelerator\Tests
 */

// phpcs:disable

// 两种入口：$at8sa_mode 由 stdin bootstrap 预设，或取命令行参数。
if ( ! isset( $at8sa_mode ) ) {
	$at8sa_mode = isset( $argv[1] ) ? (string) $argv[1] : 'hit';
}

require __DIR__ . '/wp-stubs.php';
require AT8SA_PATH . 'includes/Cache/CachePath.php';
require AT8SA_PATH . 'includes/Cache/RequestGuard.php';

use AT8\SiteAccelerator\Cache\CachePath;
use AT8\SiteAccelerator\Cache\RequestGuard;

$at8sa_host     = 'example.test';
$at8sa_cache    = AT8SA_CACHE_ROOT;
$at8sa_configs  = $at8sa_cache . '/config';

/* ---------------------------------------------------------------------------
 * 0. 清场
 *
 * 每个场景必须从零开始：上一次运行（例如 hit）留下的 index.html 会让
 * 本次 miss 场景"意外命中"，测出来的是上一次的残留而不是本次的逻辑。
 * 只保留 config/，因为它由本脚本自己重写。
 * ------------------------------------------------------------------------ */

$at8sa_wipe = function ( $dir ) use ( &$at8sa_wipe ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
		$path = $dir . '/' . $item;

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			$at8sa_wipe( $path );
		} else {
			@unlink( $path );
		}
	}

	@rmdir( $dir );
};

if ( is_dir( $at8sa_cache ) ) {
	foreach ( array_diff( (array) scandir( $at8sa_cache ), array( '.', '..', 'config' ) ) as $at8sa_item ) {
		$at8sa_path_to_wipe = $at8sa_cache . '/' . $at8sa_item;

		if ( is_dir( $at8sa_path_to_wipe ) && ! is_link( $at8sa_path_to_wipe ) ) {
			$at8sa_wipe( $at8sa_path_to_wipe );
		} else {
			@unlink( $at8sa_path_to_wipe );
		}
	}
}

/* ---------------------------------------------------------------------------
 * 1. 准备 drop-in 副本（把 {{AT8SA_PATH}} 换成真实路径，等价于 AdvancedCache::install()）
 * ------------------------------------------------------------------------ */

$at8sa_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
$at8sa_source = (string) file_get_contents( AT8SA_PATH . 'templates/advanced-cache.php' );

if ( 'no_plugin' === $at8sa_mode ) {
	// 模拟"插件目录被挪走"：把占位符换成一个不存在的路径。
	$at8sa_source = str_replace( '{{AT8SA_PATH}}', '/nonexistent-plugin-dir/', $at8sa_source );
} else {
	$at8sa_source = str_replace( '{{AT8SA_PATH}}', trailingslashit( wp_normalize_path( AT8SA_PATH ) ), $at8sa_source );
}

file_put_contents( $at8sa_dropin, $at8sa_source );

/* ---------------------------------------------------------------------------
 * 2. 准备运行时配置
 * ------------------------------------------------------------------------ */

$at8sa_config = array(
	'enabled'        => 1,
	'safe_mode'      => 'safe_mode' === $at8sa_mode ? 1 : 0,
	'backend'        => 'disk',
	'salt'           => 'testsalt|v1',
	'cache_root'     => $at8sa_cache,
	'cache_mobile'   => 'mobile' === $at8sa_mode ? 1 : 0,
	'cache_logged_in' => 0,
	'cookie_hash'    => COOKIEHASH,
	'ttl'            => 3600,
	'excluded_paths' => RequestGuard::default_excluded_paths(),
	'bypass_cookies' => RequestGuard::default_bypass_cookies(),
	'ignore_query'   => array(),
	'charset'        => 'UTF-8',
	'redis'          => null,
	'debug'          => 0,
	'version'        => AT8SA_VERSION,
);

if ( 'no_config' !== $at8sa_mode ) {
	if ( ! is_dir( $at8sa_configs ) ) {
		mkdir( $at8sa_configs, 0777, true );
	}

	file_put_contents(
		$at8sa_configs . '/' . CachePath::normalize_host( $at8sa_host ) . '.php',
		"<?php\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $at8sa_config, true ) . ";\n"
	);
}

/* ---------------------------------------------------------------------------
 * 3. 准备缓存文件
 * ------------------------------------------------------------------------ */

$at8sa_mobile = ( 'mobile' === $at8sa_mode );

// preview 模式故意把缓存文件写在根路径：如果 RequestGuard 漏判 preview=true，
// drop-in 就会把首页缓存吐给预览请求——这正是最典型的"预览看到旧内容"事故。
$at8sa_uri  = 'preview' === $at8sa_mode ? '/' : '/hello/';
$at8sa_file = CachePath::disk_file( $at8sa_cache, $at8sa_host, $at8sa_uri, $at8sa_mobile );

if ( 'miss' !== $at8sa_mode && 'no_config' !== $at8sa_mode ) {
	if ( ! is_dir( dirname( $at8sa_file ) ) ) {
		mkdir( dirname( $at8sa_file ), 0777, true );
	}

	file_put_contents( $at8sa_file, 'CACHED-BODY:' . $at8sa_mode );
}

/* ---------------------------------------------------------------------------
 * 4. 摆好请求环境
 * ------------------------------------------------------------------------ */

$_SERVER['HTTP_HOST']      = $at8sa_host;
$_SERVER['SERVER_NAME']    = $at8sa_host;
$_SERVER['REQUEST_METHOD'] = 'post' === $at8sa_mode ? 'POST' : 'GET';
$_SERVER['REQUEST_URI']    = 'preview' === $at8sa_mode ? '/?p=1&preview=true' : $at8sa_uri;

if ( 'mobile' === $at8sa_mode ) {
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';
}

$_COOKIE = array();

/* ---------------------------------------------------------------------------
 * 5. 执行
 * ------------------------------------------------------------------------ */

require $at8sa_dropin;

// 走到这里说明 drop-in 判定为"未命中"，把控制权交还 WordPress。
echo 'AT8SA_DROPIN_FELL_THROUGH';

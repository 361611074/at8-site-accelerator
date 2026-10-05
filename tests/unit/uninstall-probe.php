<?php
/**
 * uninstall.php 的**真实**执行夹具（子进程脚本）。
 *
 * 为什么值得单独测：
 * 卸载清理里最容易被"看起来对"骗过去的是**目录删除**。
 * 文件删干净了不等于目录删干净了 —— 递归里如果边走边 rmdir，
 * 父目录那一轮一定失败（里面还有刚发现的子目录），
 * 表现就是"文件全没了，wp-content/cache/at8-site-accelerator/ 空壳还在"。
 * 这种残留静态断言看不出来（代码确实调用了 rmdir），只能真跑。
 *
 * 用法：
 *   php uninstall-probe.php <mode>                —— 命令行直接跑
 *   echo '<bootstrap>' | php                      —— 由 smoke.php 从 stdin 喂进来
 *
 * 场景：keep（默认保留设置）/ purge（显式要求彻底清理）
 *
 * stdout 契约：一行 JSON
 *   { "cache_dir_exists": bool, "leftover": [...相对路径...], "options_left": [...] }
 *
 * @package AT8SA\Tests
 */

// phpcs:disable

// 两种入口：$at8sa_mode 由 stdin bootstrap 预设，或取命令行参数。
if ( ! isset( $at8sa_mode ) ) {
	$at8sa_mode = isset( $argv[1] ) ? (string) $argv[1] : 'keep';
}

require __DIR__ . '/wp-stubs.php';

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'at8-site-accelerator/at8-site-accelerator.php' );
}

if ( 'purge' === $at8sa_mode ) {
	update_option( 'at8sa_settings', array( 'keep_data_on_uninstall' => 0 ) );
	update_option( 'at8sa_version', '3.0.5' );
	update_option( 'site_accelerator_settings', array( 'legacy' => 1 ) );
} else {
	// keep 模式：设置必须原样留下。少了这一项就测不出"保留"两个字。
	update_option( 'at8sa_settings', array( 'keep_data_on_uninstall' => 1, 'sample' => 'kept' ) );
}

/* ---------------------------------------------------------------------------
 * 1. 造一棵带嵌套的缓存目录
 *
 * 必须有子目录：只有平铺文件时，"边走边 rmdir" 和 "后进先出 rmdir"
 * 两种写法结果一样，测不出差别。
 * ------------------------------------------------------------------------ */

$at8sa_cache = WP_CONTENT_DIR . '/cache/at8-site-accelerator';

$at8sa_tree = array(
	'index.html',
	'config/index.json',
	'config/redis.txt',
	'example.test/index.html',
	'example.test/deep/deeper/leaf.html',
);

foreach ( $at8sa_tree as $at8sa_rel ) {
	$at8sa_file = $at8sa_cache . '/' . $at8sa_rel;
	$at8sa_dir  = dirname( $at8sa_file );

	if ( ! is_dir( $at8sa_dir ) ) {
		mkdir( $at8sa_dir, 0777, true );
	}

	file_put_contents( $at8sa_file, 'x' );
}

// 放一个 drop-in 进去：验证它会被删（且只删属于自己的那一个）。
$at8sa_dropin = WP_CONTENT_DIR . '/advanced-cache.php';
file_put_contents( $at8sa_dropin, "<?php\n// AT8 Site Accelerator —— advanced-cache.php drop-in\n" );

/* ---------------------------------------------------------------------------
 * 2. 跑真正的 uninstall.php
 * ------------------------------------------------------------------------ */

require AT8SA_PATH . 'uninstall.php';

/* ---------------------------------------------------------------------------
 * 3. 报告残留
 * ------------------------------------------------------------------------ */

$at8sa_leftover = array();

foreach ( $at8sa_tree as $at8sa_rel ) {
	if ( file_exists( $at8sa_cache . '/' . $at8sa_rel ) ) {
		$at8sa_leftover[] = $at8sa_rel;
	}
}

global $wpdb;

echo wp_json_encode_probe(
	array(
		'cache_dir_exists' => is_dir( $at8sa_cache ),
		'leftover'         => $at8sa_leftover,
		'dropin_exists'    => file_exists( $at8sa_dropin ),
		'settings'         => get_option( 'at8sa_settings', null ),
	)
);

/**
 * 极简 JSON 编码：这里只需要数组/字符串/布尔/nul，不值得为夹具引 WP 依赖。
 *
 * @param array $data 数据。
 * @return string
 */
function wp_json_encode_probe( $data ) {
	return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

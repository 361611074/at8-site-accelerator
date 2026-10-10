<?php
/**
 * 卸载清理。
 *
 * 默认**保留数据**（`keep_data_on_uninstall = 1`）：用户卸载插件常常是为了重装排障，
 * 顺手删掉所有设置会让人白折腾一遍。想彻底清干净的用户可以在设置里显式关掉这个开关。
 *
 * @package AT8SA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$at8sa_settings = get_option( 'at8sa_settings', array() );
$at8sa_keep     = true;

if ( is_array( $at8sa_settings ) && isset( $at8sa_settings['keep_data_on_uninstall'] ) ) {
	$at8sa_keep = ! empty( $at8sa_settings['keep_data_on_uninstall'] );
}

// 无论是否保留数据，都要先停止运行：移除 drop-in 与定时任务。
$at8sa_dropin = WP_CONTENT_DIR . '/advanced-cache.php';

if ( is_readable( $at8sa_dropin ) ) {
	// 只读文件头 2KB：drop-in 里的归属标记在开头，没必要把整个文件读进来。
	// 用 is_readable() 先判断，就不用 @ 抑制错误了。
	$at8sa_head = (string) file_get_contents( $at8sa_dropin, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// 归属判定必须与 AdvancedCache::head_is_ours() 保持一致（3.0.6.4 收紧）：
	// 主标记 `Owner: at8-site-accelerator`；旧版（≤3.0.6.3）安装的 drop-in
	// 没有 Owner 行，用"插件名全称标记 + 版本戳"双标记组合兼容。
	// 只提插件名的宽松匹配会误删在注释里讨论本插件的第三方 drop-in，禁止回退。
	$at8sa_dropin_ours = false !== strpos( $at8sa_head, 'Owner: at8-site-accelerator' )
		|| (
			false !== strpos( $at8sa_head, 'AT8 Site Accelerator —— advanced-cache.php drop-in' )
			&& false !== strpos( $at8sa_head, '@at8sa-dropin-version' )
		);

	if ( $at8sa_dropin_ours ) {
		wp_delete_file( $at8sa_dropin );
	}
}

wp_clear_scheduled_hook( 'at8sa_db_cleanup_event' );

// 清掉插件自己的缓存目录（这不是"用户数据"，留着只会占盘）。
$at8sa_cache = WP_CONTENT_DIR . '/cache/at8-site-accelerator';

if ( is_dir( $at8sa_cache ) ) {
	// 只读文件头 2KB：drop-in 里的归属标记在开头，没必要把整个文件读进来。
	// 用 is_readable() 先判断，就不用 @ 抑制错误了。
	$at8sa_items = @scandir( $at8sa_cache ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( is_array( $at8sa_items ) ) {
		$at8sa_stack = array( $at8sa_cache );
		$at8sa_rmdir = array();

		while ( ! empty( $at8sa_stack ) ) {
			$at8sa_dir = array_pop( $at8sa_stack );

			// 先记账、**后**删：这一轮遍历时子目录才刚被 push 进栈，
			// 目录里还有内容，此刻 rmdir 必然失败。踩过这个坑——
			// 表现是"文件全清干净了，wp-content/cache/at8-site-accelerator/ 空壳还在"。
			$at8sa_rmdir[] = $at8sa_dir;

			foreach ( array_diff( (array) @scandir( $at8sa_dir ), array( '.', '..' ) ) as $at8sa_item ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$at8sa_path = $at8sa_dir . DIRECTORY_SEPARATOR . $at8sa_item;

				if ( is_dir( $at8sa_path ) ) {
					$at8sa_stack[] = $at8sa_path;
				} else {
					wp_delete_file( $at8sa_path );
				}
			}
		}

		// 后进先出：子目录先删，父目录最后删。
		foreach ( array_reverse( $at8sa_rmdir ) as $at8sa_dir ) {
			// rmdir 没有 WordPress 等价 API；这里只删插件自己的缓存目录，
			// 且上面的循环已经把它清空了。
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $at8sa_dir );
		}
	}
}

if ( $at8sa_keep ) {
	return;
}

// 用户显式要求彻底清理。
$at8sa_options = array(
	'at8sa_settings',
	'at8sa_cache_version',
	'at8sa_version',
	'at8sa_migrated_from_legacy',
	'at8sa_legacy_checked',
	'at8sa_activation_result',
	'site_accelerator_settings',
);

foreach ( $at8sa_options as $at8sa_option ) {
	delete_option( $at8sa_option );
}

delete_transient( 'at8sa_redis_probe' );
delete_transient( 'at8sa_conflict_scan' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_at8sa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_at8sa_' ) . '%'
	)
);

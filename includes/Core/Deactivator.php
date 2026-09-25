<?php
/**
 * 停用流程。
 *
 * 停用时**只做"停止运行"该做的事**，不做数据清理：
 * - 移除 drop-in（避免残留代码继续跑）；
 * - 取消定时任务；
 * - **不删**设置、**不删**缓存目录、**不删** wp-config.php 里的 WP_CACHE。
 *
 * 原因很直接：用户停用插件往往只是想排查问题，随时会再启用。
 * 停用就清数据是流氓行为，而且 WP_CACHE 常量留着完全无害
 * （没有 advanced-cache.php 时 WordPress 什么都不会做）。
 *
 * @package AT8\SiteAccelerator\Core
 */

namespace AT8\SiteAccelerator\Core;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Optimization\DatabaseCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
final class Deactivator {

	/**
	 * 停用。
	 *
	 * @return void
	 */
	public static function deactivate() {
		$plugin = Plugin::instance();
		$plugin->boot();

		$container = $plugin->container();

		// 移除 drop-in。
		$dropin = $container->get( AdvancedCache::class );
		$dropin->uninstall();

		// 取消定时任务。
		$timestamp = wp_next_scheduled( DatabaseCleanup::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, DatabaseCleanup::CRON_HOOK );
		}

		wp_clear_scheduled_hook( DatabaseCleanup::CRON_HOOK );

		$logger = $container->get( \AT8\SiteAccelerator\Support\Logger::class );
		$logger->info( '插件已停用（设置与缓存目录保留）' );

		/**
		 * 停用后触发。
		 */
		do_action( 'at8sa_deactivated' );
	}
}

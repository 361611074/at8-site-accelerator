<?php
/**
 * 激活流程。
 *
 * 顺序刻意如此：
 * 1. 建目录并放守卫文件；
 * 2. 迁移旧设置（保证后续步骤读到的是新结构）；
 * 3. 写 drop-in 运行时配置；
 * 4. 安装 drop-in；
 * 5. 尝试启用 WP_CACHE（失败不阻断激活，只记下结果给后台提示）。
 *
 * 第 5 步放在最后、且失败不阻断——因为 wp-config.php 不可写是很常见的情况，
 * 不该因此让用户激活失败。
 *
 * @package AT8SA\Core
 */

namespace AT8SA\Core;

use AT8SA\Cache\AdvancedCache;
use AT8SA\Cache\Config;
use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Support\Filesystem;
use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
final class Activator {

	/**
	 * 激活结果选项名（供后台提示读取）。
	 */
	const RESULT_OPTION = 'at8sa_activation_result';

	/**
	 * 激活。
	 *
	 * @return void
	 */
	public static function activate() {
		$plugin = Plugin::instance();
		$plugin->boot();

		$container = $plugin->container();
		$result    = array();

		// 1. 目录。
		$result['directories'] = self::create_directories();

		// 2. 迁移。
		$settings           = $container->get( Settings::class );
		$result['migrated'] = $settings->migrate_from_legacy();
		$settings->flush_cache();

		// 3. 运行时配置。
		$config           = $container->get( Config::class );
		$result['config'] = $config->write( $config->runtime() );

		// 4. drop-in。
		//
		// 安装前先确认槽位归属：`wp-content/advanced-cache.php` 若已被别的缓存
		// 插件（或主机环境）占用，本插件必须让路而不是把它顶掉。被拒绝时把原因
		// 记进激活结果，供后台明确提示管理员——静默跳过会让人以为装好了。
		$dropin = $container->get( AdvancedCache::class );

		if ( $dropin->has_foreign_dropin() ) {
			$result['dropin']              = false;
			$result['dropin_blocked']      = true;
			$result['dropin_blocked_note'] = $dropin->blocked_reason();
		} elseif ( $settings->is_on( 'advanced_cache' ) ) {
			$result['dropin'] = $dropin->install();
		} else {
			$result['dropin'] = false;
		}

		// 5. WP_CACHE。
		$wp_cache                = $dropin->enable_wp_cache();
		$result['wp_cache']      = $wp_cache['ok'];
		$result['wp_cache_note'] = $wp_cache['message'];

		// 清理 2.x 遗留缓存，避免新旧两套目录并存。
		foreach ( array( WP_CONTENT_DIR . '/cache/site-accelerator' ) as $legacy ) {
			if ( is_dir( $legacy ) ) {
				Filesystem::rrmdir( $legacy );
			}
		}

		$result['version']      = AT8SA_VERSION;
		$result['activated_at'] = gmdate( 'c' );

		update_option( self::RESULT_OPTION, $result, false );
		update_option( 'at8sa_version', AT8SA_VERSION, false );

		// 清探测缓存，让新配置立即生效。
		$container->get( BackendFactory::class )->reset_probe();

		$logger = $container->get( Logger::class );
		$logger->info( '插件已激活', array( 'version' => AT8SA_VERSION ) );

		/**
		 * 激活完成后触发。
		 *
		 * @param array $result 激活结果。
		 */
		do_action( 'at8sa_activated', $result );
	}

	/**
	 * 创建缓存目录并放置守卫文件（计划书 §125）。
	 *
	 * @return bool
	 */
	private static function create_directories() {
		$ok = true;

		foreach ( array( AT8SA_CACHE_ROOT, AT8SA_CACHE_ROOT . '/config' ) as $dir ) {
			if ( ! Filesystem::mkdir_guarded( $dir ) ) {
				$ok = false;
			}
		}

		return $ok;
	}
}

<?php
/**
 * 激活流程。
 *
 * 顺序刻意如此：
 * 1. 建目录并放守卫文件；
 * 2. 迁移旧设置（保证后续步骤读到的是新结构）；
 * 3. 写 drop-in 运行时配置；
 * 4. 安装 drop-in（仅当「高级缓存」开启）；
 * 5. 尝试启用 WP_CACHE（同样仅当「高级缓存」开启，且失败不阻断激活，
 *    只记下结果）。
 *
 * 第 5 步放在最后、且失败不阻断——因为 wp-config.php 不可写是很常见的情况，
 * 不该因此让用户激活失败。
 *
 * 第 4、5 步共用「高级缓存」这道闸门：关着的时候既不装 drop-in，也不碰
 * wp-config.php。写用户文件必须有用户明确的选择作为前提。
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
		//
		// 与第 4 步共用同一道闸门：`advanced_cache` 关着时，装 drop-in 与写
		// wp-config.php **都不该发生**。
		//
		// 早前这一步是无条件的：只要插件被激活，就会往 wp-config.php 里插入
		// `define( 'WP_CACHE', true )`。写的内容本身无害（没有 drop-in 时
		// WordPress 什么都不会做），但"用户没要的东西被写进用户自己的文件"
		// 本身就是问题 —— 管理员在设置里明确关掉高级缓存后，停用再启用插件
		// 又会被写回去，这与他刚刚表达的选择相反。
		//
		// 跳过时仍然留下可读的说明，而不是静默消失：管理员随时可以在设置页
		// 「工具」里一键启用，那条手动入口与本处的自动入口并存。
		if ( $settings->is_on( 'advanced_cache' ) ) {
			$wp_cache                = $dropin->enable_wp_cache();
			$result['wp_cache']      = $wp_cache['ok'];
			$result['wp_cache_note'] = $wp_cache['message'];
		} else {
			$result['wp_cache']      = false;
			$result['wp_cache_note'] = __( '未启用「高级缓存」，跳过写入 wp-config.php。需要时可在设置页「工具」中一键启用。', 'at8-site-accelerator' );
		}

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

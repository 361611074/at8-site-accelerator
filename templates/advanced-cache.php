<?php
/**
 * AT8 Site Accelerator —— advanced-cache.php drop-in 模板。
 *
 * 本文件由插件在启用"高级缓存"时**复制**到 `wp-content/advanced-cache.php`，
 * 并把 `{{AT8SA_PATH}}` 替换为插件绝对路径。请勿直接编辑 wp-content 下的副本——
 * 插件会在设置保存 / 升级时用本模板覆盖它。
 *
 * 运行时机：WordPress 的 wp-settings.php 极早期，**插件与主题都还没加载**。
 * 因此这里只能使用超全局变量，不能调用 `is_*()` / `wp_*()` 系列函数。
 *
 * 命中时直接输出缓存并 exit，完全跳过 WordPress 的数据库查询与模板渲染——
 * 这是整页缓存最大的性能收益来源（计划书 §62）。
 *
 * @package AT8\SiteAccelerator
 */

defined( 'ABSPATH' ) || exit;

$at8sa_path = '{{AT8SA_PATH}}';

if ( ! is_readable( $at8sa_path . 'includes/Cache/CachePath.php' ) ) {
	return; // 插件目录被挪走 / 被删：静默退化为无缓存，绝不报错。
}

require_once $at8sa_path . 'includes/Cache/CachePath.php';
require_once $at8sa_path . 'includes/Cache/RequestGuard.php';

// 主机名归一化**必须**复用 CachePath::normalize_host()：
// 它同时决定配置文件叫什么（这里）和缓存目录叫什么（DiskBackend 侧）。
// 两边各写一份正则，早晚会因为改了其中一处而"配置读得到、缓存找不到"。
$at8sa_host = \AT8\SiteAccelerator\Cache\CachePath::normalize_host(
	\AT8\SiteAccelerator\Cache\RequestGuard::server( 'HTTP_HOST' )
);

$at8sa_config_dir  = WP_CONTENT_DIR . '/cache/at8-site-accelerator/config/';
$at8sa_config_file = $at8sa_config_dir . $at8sa_host . '.php';

if ( ! is_readable( $at8sa_config_file ) ) {
	$at8sa_config_file = $at8sa_config_dir . 'default.php';
}

if ( ! is_readable( $at8sa_config_file ) ) {
	return; // 插件尚未生成配置：静默退化为无缓存，绝不报错。
}

$at8sa_config = include $at8sa_config_file;

if ( ! is_array( $at8sa_config ) || empty( $at8sa_config['enabled'] ) || ! empty( $at8sa_config['safe_mode'] ) ) {
	return;
}

if ( \AT8\SiteAccelerator\Cache\RequestGuard::should_bypass( $at8sa_config ) ) {
	return;
}

$at8sa_request_host = \AT8\SiteAccelerator\Cache\RequestGuard::host();
$at8sa_uri          = \AT8\SiteAccelerator\Cache\RequestGuard::uri( $at8sa_config );
$at8sa_mobile       = ! empty( $at8sa_config['cache_mobile'] ) && \AT8\SiteAccelerator\Cache\RequestGuard::is_mobile();
$at8sa_ttl          = isset( $at8sa_config['ttl'] ) ? (int) $at8sa_config['ttl'] : 3600;
$at8sa_html         = false;
$at8sa_backend      = isset( $at8sa_config['backend'] ) ? (string) $at8sa_config['backend'] : 'disk';

if ( 'redis' === $at8sa_backend && ! empty( $at8sa_config['redis'] ) && is_readable( $at8sa_path . 'includes/Support/RedisClient.php' ) ) {
	require_once $at8sa_path . 'includes/Support/RedisClient.php';

	$at8sa_redis = new \AT8\SiteAccelerator\Support\RedisClient(
		isset( $at8sa_config['redis']['host'] ) ? $at8sa_config['redis']['host'] : '127.0.0.1',
		isset( $at8sa_config['redis']['port'] ) ? (int) $at8sa_config['redis']['port'] : 6379,
		1.0,
		isset( $at8sa_config['redis']['db'] ) ? (int) $at8sa_config['redis']['db'] : 2
	);

	if ( $at8sa_redis->connect() ) {
		$at8sa_key  = \AT8\SiteAccelerator\Cache\CachePath::redis_key( $at8sa_config['salt'], $at8sa_request_host, $at8sa_uri, $at8sa_mobile );
		$at8sa_html = $at8sa_redis->get( $at8sa_key );

		if ( ! is_string( $at8sa_html ) || '' === $at8sa_html ) {
			$at8sa_html = false;
		}
	}
} else {
	$at8sa_file = \AT8\SiteAccelerator\Cache\CachePath::disk_file(
		$at8sa_config['cache_root'],
		$at8sa_request_host,
		$at8sa_uri,
		$at8sa_mobile
	);

	if ( is_file( $at8sa_file ) && is_readable( $at8sa_file ) ) {
		$at8sa_age = time() - (int) filemtime( $at8sa_file );

		if ( $at8sa_ttl <= 0 || $at8sa_age <= $at8sa_ttl ) {
			$at8sa_html = file_get_contents( $at8sa_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		}
	}
}

if ( false === $at8sa_html || '' === $at8sa_html ) {
	return; // MISS：交给 WordPress 正常渲染，插件侧再落盘。
}

if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=' . ( isset( $at8sa_config['charset'] ) ? $at8sa_config['charset'] : 'UTF-8' ) );
	header( 'X-AT8-Cache: HIT' );
	// 显示名与 BackendInterface::name() 对齐：同一个响应头不该因为
	// "这次命中由 drop-in 还是插件侧处理"而给出不同大小写的值。
	header( 'X-AT8-Cache-Backend: ' . ( 'redis' === $at8sa_backend ? 'Redis' : 'Disk' ) );
	header( 'Cache-Control: public, max-age=' . max( 0, $at8sa_ttl ) );

	if ( ! empty( $at8sa_config['cache_mobile'] ) ) {
		header( 'Vary: User-Agent', false );
	}
}

echo $at8sa_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 缓存内容在写入时已消毒。

exit;

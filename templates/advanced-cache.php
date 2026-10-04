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
 * **本文件是"复制出去"的，插件升级不会更新它**。所以它必须满足三条约束：
 * 1. 引用的类名/配置键一旦对不上，只能**静默退化**，绝不能 Fatal（见下面的
 *    `class_exists()` 护栏）——drop-in 跑在 WordPress 之前，一个 Fatal 就是整站白屏；
 * 2. 必须带版本戳，供 `AdvancedCache::needs_reinstall()` 在 WordPress 起来之后
 *    发现"这份 drop-in 是旧版的"并自动重装；
 * 3. 必须**自己发现自己是旧版**并主动让出（见下面的"版本自检"）——因为一旦命中缓存
 *    这里就 `exit` 了，第 2 条那套事后修复根本没机会跑。
 *
 * @package AT8SA
 * @at8sa-dropin-version {{AT8SA_VERSION}}
 */

defined( 'ABSPATH' ) || exit;

$at8sa_path = '{{AT8SA_PATH}}';

if ( ! is_readable( $at8sa_path . 'includes/Cache/CachePath.php' ) ) {
	return; // 插件目录被挪走 / 被删：静默退化为无缓存，绝不报错。
}

// ── 版本自检：本文件是"复制出去"的，插件升级**不会**更新它 ──
//
// 为什么非要有这一步：这份 drop-in 一旦命中缓存就直接 `exit`，WordPress 根本不会启动，
// 于是 `AdvancedCache::needs_reinstall()` / `Plugin::ensure_dropin()` 这两道"事后修复"
// **永远没有机会执行**——旧文件就一直挂在 `wp-content/` 下，永远不更新。
// 实测确认过这条路径：3 次请求全部 HIT，drop-in 一个字节都没变。
//
// 插件主文件 `at8-site-accelerator.php` 是升级时**必然被替换**的唯一凭据，
// 所以直接读它的 `AT8SA_VERSION` 常量来比对：不一致就主动放弃本次命中，
// 让请求落回 WordPress，由 `ensure_dropin()` 把本文件重写成新版。
//
// 这一步同时兜住了"将来再改命名空间"：哪怕类名对不上，也要先走到这里退化，
// 而不是等到调用处变成 PHP Fatal（前台 + wp-admin 一起白屏）。
//
// 读不到文件、正则不匹配一律按"一致"处理（不阻断）——
// 绝不能因为读不到版本号就把整站缓存关掉。
$at8sa_dropin_version = '{{AT8SA_VERSION}}';

if ( is_readable( $at8sa_path . 'at8-site-accelerator.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	$at8sa_main_head = (string) @file_get_contents( $at8sa_path . 'at8-site-accelerator.php', false, null, 0, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( preg_match( '/AT8SA_VERSION[\'"\s,]+([0-9A-Za-z.\-]+)/', $at8sa_main_head, $at8sa_version_match ) ) {
		if ( $at8sa_version_match[1] !== $at8sa_dropin_version ) {
			return; // 本文件已过期：交给 ensure_dropin() 重写。
		}
	}
}

unset( $at8sa_main_head, $at8sa_version_match );

require_once $at8sa_path . 'includes/Cache/CachePath.php';
require_once $at8sa_path . 'includes/Cache/RequestGuard.php';

// 类名对不上就静默退化，**绝不**让这里变成 PHP Fatal。
//
// 为什么这条护栏是必须的：drop-in 是**复制**到 `wp-content/` 的独立文件，
// 插件升级不会更新它（只有"激活插件 / 保存设置"才会重写）。而它跑在 WordPress
// 之前 —— 一旦文件里硬编码的类名/命名空间与新版插件对不上，就是一个 Fatal：
// 前台和 wp-admin 一起白屏，而且因为 WordPress 根本没机会加载，drop-in 也永远
// 得不到修复，用户只能手工删掉这个文件。
// 3.0.2 把命名空间从 `AT8\SiteAccelerator` 改成 `AT8SA`（PCP 前缀要求），
// 就正好踩中这条：老 drop-in + 新插件 = 整站白屏。
// 有这条护栏，"整站白屏"降级为"暂时没有页面缓存"，站点照常可用。
if ( ! class_exists( '\AT8SA\Cache\CachePath' ) || ! class_exists( '\AT8SA\Cache\RequestGuard' ) ) {
	return;
}

// 主机名归一化**必须**复用 CachePath::normalize_host()：
// 它同时决定配置文件叫什么（这里）和缓存目录叫什么（DiskBackend 侧）。
// 两边各写一份正则，早晚会因为改了其中一处而"配置读得到、缓存找不到"。
$at8sa_host = \AT8SA\Cache\CachePath::normalize_host(
	\AT8SA\Cache\RequestGuard::server( 'HTTP_HOST' )
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

if ( \AT8SA\Cache\RequestGuard::should_bypass( $at8sa_config ) ) {
	return;
}

$at8sa_request_host = \AT8SA\Cache\RequestGuard::host();
$at8sa_uri          = \AT8SA\Cache\RequestGuard::uri( $at8sa_config );
$at8sa_mobile       = ! empty( $at8sa_config['cache_mobile'] ) && \AT8SA\Cache\RequestGuard::is_mobile();
$at8sa_ttl          = isset( $at8sa_config['ttl'] ) ? (int) $at8sa_config['ttl'] : 3600;
$at8sa_html         = false;
$at8sa_backend      = isset( $at8sa_config['backend'] ) ? (string) $at8sa_config['backend'] : 'disk';

if ( 'redis' === $at8sa_backend && ! empty( $at8sa_config['redis'] ) && is_readable( $at8sa_path . 'includes/Support/RedisClient.php' ) ) {
	require_once $at8sa_path . 'includes/Support/RedisClient.php';

	// 同上面的护栏：类名对不上时静默退化，不 Fatal。
	if ( class_exists( '\AT8SA\Support\RedisClient' ) ) {
		$at8sa_redis = new \AT8SA\Support\RedisClient(
			isset( $at8sa_config['redis']['host'] ) ? $at8sa_config['redis']['host'] : '127.0.0.1',
			isset( $at8sa_config['redis']['port'] ) ? (int) $at8sa_config['redis']['port'] : 6379,
			1.0,
			isset( $at8sa_config['redis']['db'] ) ? (int) $at8sa_config['redis']['db'] : 2
		);

		if ( $at8sa_redis->connect() ) {
			$at8sa_key  = \AT8SA\Cache\CachePath::redis_key( $at8sa_config['salt'], $at8sa_request_host, $at8sa_uri, $at8sa_mobile );
			$at8sa_html = $at8sa_redis->get( $at8sa_key );

			if ( ! is_string( $at8sa_html ) || '' === $at8sa_html ) {
				$at8sa_html = false;
			}
		}
	}
} else {
	$at8sa_file = \AT8SA\Cache\CachePath::disk_file(
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

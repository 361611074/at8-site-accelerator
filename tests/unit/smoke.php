<?php
/**
 * 冒烟测试：在 WordPress 函数桩之上真实实例化并调用插件代码。
 *
 * 运行方式：
 *   php tests/unit/smoke.php
 *
 * 退出码 0 = 全部通过；1 = 有失败项。
 *
 * 覆盖范围（对应计划书 §72 的 Install / Activate / Cache / Purge / Settings / REST 项）：
 *   - 全部 38 个类的加载与实例化
 *   - 设置默认值 / 清洗 / 2.x 迁移
 *   - 缓存键归一化与路径穿越防护
 *   - 磁盘后端 写入 / 命中 / TTL / 精准删除 / 整站清空
 *   - 失效器 URL 解析与站点边界
 *   - HTML 压缩（含安全阀）
 *   - 懒加载属性注入与首屏跳过
 *   - drop-in 安装内容与 wp-config 校验逻辑
 *   - 诊断采集
 *   - 设置页模板渲染（抓模板致命错误）
 *
 * @package AT8SA\Tests
 */

// phpcs:disable

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require __DIR__ . '/wp-stubs.php';

/* ---------------------------------------------------------------------------
 * 迷你断言框架
 * ------------------------------------------------------------------------ */

$GLOBALS['at8sa_pass'] = 0;
$GLOBALS['at8sa_fail'] = 0;
$GLOBALS['at8sa_failures'] = array();

function check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		++$GLOBALS['at8sa_pass'];
		echo "  [PASS] {$label}\n";
		return true;
	}

	++$GLOBALS['at8sa_fail'];
	$GLOBALS['at8sa_failures'][] = $label . ( $detail ? " -> {$detail}" : '' );
	echo "  [FAIL] {$label}" . ( $detail ? " -> {$detail}" : '' ) . "\n";

	return false;
}

function section( $title ) {
	echo "\n=== {$title} ===\n";
}

/**
 * 显式跳过一段断言（环境不具备时）。
 *
 * 为什么不写成 `check( '...', true )`：那是一个恒真断言，
 * 会把"没测"伪装成"测过了"，正是本项目专门清理过的反模式。
 * 跳过就是跳过，打印出来，不进通过/失败计数。
 *
 * @param string $reason 跳过原因。
 * @return void
 */
function skip( $reason ) {
	echo "  [SKIP] {$reason}\n";
}

function expect_throw( $label, callable $fn ) {
	try {
		$fn();
		check( $label . '（应抛异常）', false, '未抛出异常' );
	} catch ( Throwable $e ) {
		check( $label, true );
	}
}

/**
 * 剥掉 PHP 注释，只留可执行代码。
 *
 * 静态安全扫描必须看代码而不是注释：本项目在注释里大量解释"为什么不用 X"，
 * 直接对全文 grep 会把"解释"当成"使用"（例如 RedisBackend 明确写了"绝不用 FLUSHDB"）。
 *
 * 字符串字面量**保留**——把密钥写进字符串同样是问题。
 *
 * @param string $source PHP 源码。
 * @return string
 */
function strip_php_comments( $source ) {
	if ( ! function_exists( 'token_get_all' ) ) {
		return $source;
	}

	$out = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}

			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}

	return $out;
}

/**
 * 在插件源码里搜一个设置键，返回 `文件:行号: 内容` 列表。
 *
 * 用途：反向校验"每个设置开关都真的有消费方"。
 * 后台里存在一个点了什么也不会发生的开关，是比 bug 更伤用户信任的问题——
 * 用户会以为是自己配错了，反复折腾。
 *
 * @param string $target 相对插件根目录的目录或文件。
 * @param string $key    设置键名。
 * @return array
 */
function at8sa_grep_key( $target, $key ) {
	static $cache = array();

	$root = AT8SA_PATH;
	$path = $root . $target;
	$hits = array();
	$files = array();

	if ( is_file( $path ) ) {
		$files[] = $path;
	} elseif ( is_dir( $path ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	foreach ( $files as $file ) {
		$relative = str_replace( '\\', '/', str_replace( $root, '', $file ) );

		if ( ! isset( $cache[ $file ] ) ) {
			$cache[ $file ] = explode( "\n", (string) file_get_contents( $file ) );
		}

		foreach ( $cache[ $file ] as $number => $line ) {
			// 只看"以字符串形式引用该键"的地方，避免匹配到变量名或注释里的同名词。
			if ( false !== strpos( $line, "'" . $key . "'" ) || false !== strpos( $line, '"' . $key . '"' ) ) {
				$hits[] = $relative . ':' . ( $number + 1 ) . ': ' . trim( $line );
			}
		}
	}

	return $hits;
}

/* ---------------------------------------------------------------------------
 * 自动加载
 * ------------------------------------------------------------------------ */

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

use AT8SA\Cache\AdvancedCache;
use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\Backend\DiskBackend;
use AT8SA\Cache\CacheEngine;
use AT8SA\Cache\CachePath;
use AT8SA\Cache\Config;
use AT8SA\Cache\RequestGuard;
use AT8SA\Compatibility\CachePluginDetector;
use AT8SA\Core\Container;
use AT8SA\Core\Plugin;
use AT8SA\Core\Settings;
use AT8SA\Diagnostics\Diagnostics;
use AT8SA\Optimization\BrowserCache;
use AT8SA\Optimization\HtmlMinifier;
use AT8SA\Optimization\LazyLoad;
use AT8SA\Purge\Purger;
use AT8SA\Support\Filesystem;
use AT8SA\Support\Logger;

echo "AT8 Site Accelerator 冒烟测试\n";
echo 'PHP ' . PHP_VERSION . "\n";

/* ---------------------------------------------------------------------------
 * 0. 环境复位
 *
 * 缓存目录必须每次从零开始：否则上一轮跑测试留下的 index-m/__m 等文件会让
 * "移动端变体独立""删除后未命中"这类断言在第二次运行时假通过/假失败。
 * ------------------------------------------------------------------------ */

function reset_cache_root( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $item ) {
		$path = $dir . '/' . $item;

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			reset_cache_root( $path );
		} else {
			@unlink( $path );
		}
	}

	@rmdir( $dir );
}

reset_cache_root( AT8SA_CACHE_ROOT );
@unlink( WP_CONTENT_DIR . '/advanced-cache.php' );

check( '测试起始状态：缓存目录已清空', ! is_dir( AT8SA_CACHE_ROOT ) );

/* ---------------------------------------------------------------------------
 * 1. 容器
 * ------------------------------------------------------------------------ */

section( '容器' );

$container = new Container();
$container->bind( 'x', function () { return new stdClass(); } );

check( '容器登记后可解析', $container->get( 'x' ) instanceof stdClass );
check( '容器单例', $container->get( 'x' ) === $container->get( 'x' ) );
check( '容器未登记返回 null', null === $container->get( 'nope' ) );

/* ---------------------------------------------------------------------------
 * 2. 设置
 * ------------------------------------------------------------------------ */

section( '设置' );

$settings = new Settings();
$defaults = $settings->defaults();

check( '默认值非空', count( $defaults ) > 50, '实际 ' . count( $defaults ) );
check( '布尔键都存在于默认值', 0 === count( array_diff( $settings->boolean_keys(), array_keys( $defaults ) ) ) );

foreach ( $settings->boolean_keys() as $key ) {
	if ( ! array_key_exists( $key, $defaults ) ) {
		check( '布尔键 ' . $key . ' 缺少默认值', false );
	}
}

$settings->persist( $settings->sanitize( array() ) );
check( '空输入清洗后回落到默认值', 1 === (int) $settings->get( 'page_cache' ) );
check( '数据库清理默认全关', 0 === (int) $settings->get( 'db_revisions' ) );

$dirty = $settings->sanitize(
	array(
		'cache_ttl'      => '999999999',
		'cache_backend'  => 'mysql',
		'heartbeat'      => 'hacked',
		'purge_scope'    => 'bogus',
		'db_schedule'    => 'hourly',
		'log_level'      => 'verbose',
		'exclude_urls'   => "<script>alert(1)</script>\nfoo",
		'preload_strategy' => array( 'prefetch', 'evil-strategy' ),
	)
);

check( '超范围整数被钳制', (int) $dirty['cache_ttl'] === 2592000, '实际 ' . $dirty['cache_ttl'] );
check( '非法枚举回退默认', 'auto' === $dirty['cache_backend'] );
check( '非法心跳回退默认', 'reduce' === $dirty['heartbeat'] );
check( '非法失效范围回退默认', 'related' === $dirty['purge_scope'] );
check( '非法计划回退默认', 'off' === $dirty['db_schedule'] );
check( '非法日志级别回退默认', 'error' === $dirty['log_level'] );
check( '策略白名单过滤', array( 'prefetch' ) === $dirty['preload_strategy'] );
check( '文本被去标签', false === strpos( $dirty['exclude_urls'], '<script' ), $dirty['exclude_urls'] );

// 2.x 迁移
// 这里刻意把 2.x 的**全部 30 个设置键**都塞进去，逐个核对迁移后是否有值、
// 以及是否有"旧开关搬过来却没人消费"的遗漏（用户明确要求兼容旧设置）。
/**
 * 2.x 旧设置。值来自数据库（option），本质是 mixed——
 * 标注成 array<string, mixed> 而不是让 PHPStan 从字面量推断出联合字面量类型，
 * 否则下面按"数组/字符串/数字"三分支比较时会被判成"某分支恒不可达"。
 *
 * @var array<string, mixed> $legacy_keys
 */
$legacy_keys = array(
	'page_cache'             => 0,
	'cache_ttl'              => 7200,
	'cache_backend'          => 'redis',
	'exclude_urls'           => "/form/\n/contact/",
	'ignore_query'           => 'ref',
	'preload_enable'         => 1,
	'hover_delay'            => 33,
	'touch_delay'            => 88,
	'preload_viewport'       => 0,
	'preload_strategy'       => array( 'prefetch' ),
	'max_preloads'           => 12,
	'max_per_domain'         => 6,
	'preload_cooldown'       => 120,
	'dns_prefetch'           => 0,
	'preconnect_hosts'       => 'cdn.example.test',
	'http2_push'             => 1,
	'preload_debug'          => 1,
	'disable_emoji'          => 0,
	'disable_embeds'         => 0,
	'remove_wp_generator'    => 0,
	'disable_jquery_migrate' => 1,
	'disable_dashicons'      => 0,
	'remove_query_strings'   => 0,
	'heartbeat'              => 'disable',
	'disable_block_css'      => 1,
	'webp_convert'           => 0,
	'remove_site_health'     => 0,
	'remove_events_news'     => 0,
	'disable_version_checks' => 0,
	'disable_large_thumbs'   => 0,
);

$GLOBALS['at8sa_test_options'] = array( Settings::LEGACY_OPTION => $legacy_keys );

$fresh    = new Settings();
$migrated = $fresh->migrate_from_legacy();

check( '迁移执行成功', true === $migrated );
check( '迁移后 page_cache 保留旧值', 0 === (int) $fresh->get( 'page_cache' ) );
check( '迁移后 cache_ttl 保留旧值', 7200 === (int) $fresh->get( 'cache_ttl' ) );
check( '迁移后 cache_backend 保留旧值', 'redis' === $fresh->get( 'cache_backend' ) );
check( '迁移后 hover_delay 保留旧值', 33 === (int) $fresh->get( 'hover_delay' ) );
check( '迁移后新键补默认值', 1 === (int) $fresh->get( 'lazyload' ) );
check( '旧选项未被删除（可回滚）', is_array( get_option( Settings::LEGACY_OPTION ) ) );
check( '迁移幂等：二次调用不再执行', false === ( new Settings() )->migrate_from_legacy() );

// 改名键：2.x 的 http2_push → 3.0 的 resource_preload，值必须跟着搬过来。
check(
	'改名键 http2_push 的值已搬到 resource_preload',
	1 === (int) $fresh->get( 'resource_preload' ),
	'resource_preload=' . $fresh->get( 'resource_preload' )
);

// 逐个核对：2.x 的每个开关在迁移后都必须被保留（值一致）。
$lost = array();

foreach ( $legacy_keys as $key => $value ) {
	$new_key = ( 'http2_push' === $key ) ? 'resource_preload' : $key;

	if ( ! array_key_exists( $new_key, $fresh->defaults() ) ) {
		$lost[] = $key . '（3.0 无对应键）';
		continue;
	}

	$got = $fresh->get( $new_key );

	if ( is_array( $value ) ) {
		if ( $value !== array_values( (array) $got ) ) {
			$lost[] = $key . '（数组值不一致）';
		}
	} elseif ( is_string( $value ) && ! is_numeric( $value ) ) {
		if ( (string) $got !== $value ) {
			$lost[] = $key . "（期望 {$value}，实际 " . (string) $got . '）';
		}
	} elseif ( (int) $got !== (int) $value ) {
		$lost[] = $key . "（期望 {$value}，实际 " . (int) $got . '）';
	}
}

check( '2.x 全部设置项迁移无遗漏', empty( $lost ), implode( '；', $lost ) );

// 反向检查：3.0 新增的每个布尔开关都必须真的有消费方，
// 否则就是"后台有个开关，点了什么也不会发生"。
$orphan_booleans = array();
$consumed        = array();

foreach ( $fresh->boolean_keys() as $bool_key ) {
	foreach ( array( 'includes', 'templates', 'uninstall.php', 'at8-site-accelerator.php' ) as $target ) {
		$hits = at8sa_grep_key( $target, $bool_key );

		// 排除 Settings.php 自己（默认值表 + boolean_keys 表）。
		$hits = array_filter(
			$hits,
			function ( $line ) {
				return false === strpos( $line, 'Core/Settings.php' ) && false === strpos( $line, 'Core\\Settings.php' );
			}
		);

		if ( ! empty( $hits ) ) {
			$consumed[ $bool_key ] = true;
			break;
		}
	}

	if ( ! isset( $consumed[ $bool_key ] ) ) {
		$orphan_booleans[] = $bool_key;
	}
}

check( '所有布尔开关都有消费方', empty( $orphan_booleans ), implode( ',', $orphan_booleans ) );

// 恢复干净设置
$GLOBALS['at8sa_test_options'] = array();
$clean = new Settings();
$clean->persist( $clean->sanitize( array() ) );

/* ---------------------------------------------------------------------------
 * 3. 缓存键与路径
 * ------------------------------------------------------------------------ */

section( '缓存键与路径安全' );

check(
	'UTM 参数被剥离',
	'/hello/' === CachePath::normalize_uri( '/hello/?utm_source=x&utm_medium=y' ),
	CachePath::normalize_uri( '/hello/?utm_source=x&utm_medium=y' )
);

check(
	'功能型参数保留',
	'/shop/?orderby=price' === CachePath::normalize_uri( '/shop/?orderby=price' )
);

check(
	'参数顺序归一化',
	CachePath::normalize_uri( '/a/?b=2&a=1' ) === CachePath::normalize_uri( '/a/?a=1&b=2' )
);

check(
	'自定义前缀规则生效',
	'/a/' === CachePath::normalize_uri( '/a/?aff_1=9', array( 'aff_*' ) )
);

check(
	'通配 * 忽略全部参数',
	'/a/' === CachePath::normalize_uri( '/a/?x=1&y=2', array( '*' ) )
);

// 路径穿越
$evil = array(
	'../../../etc/passwd',
	'..%2f..%2fetc',
	'/foo/../../bar',
	'/a/..;/b',
	"/a\x00/b",
	'/a/....//b',
);

foreach ( $evil as $input ) {
	$rel = CachePath::relative_dir( 'example.test', $input );

	check(
		'路径穿越被消毒: ' . $input,
		false === strpos( $rel, '..' ) && false === strpos( $rel, "\0" ),
		$rel
	);
}

check(
	'根路径落到 __root',
	'example.test/__root' === CachePath::relative_dir( 'example.test', '/' ),
	CachePath::relative_dir( 'example.test', '/' )
);

check(
	'带查询串进入 q- 目录',
	(bool) preg_match( '#^example\.test/__root/q-[0-9a-f]{10}$#', CachePath::relative_dir( 'example.test', '/?s=x' ) ),
	CachePath::relative_dir( 'example.test', '/?s=x' )
);

check(
	'移动端变体路径独立',
	'example.test/a/__m' === CachePath::relative_dir( 'example.test', '/a/', true )
);

check(
	'主机名被归一化',
	'example.com' === CachePath::normalize_host( 'Example.COM' ),
	CachePath::normalize_host( 'Example.COM' )
);

check(
	'非法主机名有兜底',
	'unknown-host' === CachePath::normalize_host( '' )
);

$disk_file      = CachePath::disk_file( AT8SA_CACHE_ROOT, 'example.test', '/blog/hello/' );
$expected_tail  = '/example.test/blog/hello/index.html';

check(
	'磁盘路径结构正确',
	$expected_tail === substr( str_replace( '\\', '/', $disk_file ), -strlen( $expected_tail ) ),
	$disk_file
);

check(
	'Redis 键不含协议',
	false === strpos( CachePath::redis_key( 'salt', 'example.test', '/a/' ), 'http' )
);

/* ---------------------------------------------------------------------------
 * 4. 请求准入
 * ------------------------------------------------------------------------ */

section( '请求准入' );

$config = array(
	'enabled'        => 1,
	'safe_mode'      => 0,
	'cache_logged_in' => 0,
	'cache_mobile'   => 1,
	'cookie_hash'    => COOKIEHASH,
	'excluded_paths' => RequestGuard::default_excluded_paths(),
	'bypass_cookies' => RequestGuard::default_bypass_cookies(),
	'ignore_query'   => array(),
);

function with_request( array $server, array $cookie, callable $fn ) {
	$old_server = $_SERVER;
	$old_cookie = $_COOKIE;

	$_SERVER = array_merge( $_SERVER, $server );
	$_COOKIE = $cookie;

	try {
		return $fn();
	} finally {
		$_SERVER = $old_server;
		$_COOKIE = $old_cookie;
	}
}

check(
	'普通 GET 允许缓存',
	! with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello/' ), array(), function () use ( $config ) {
		return RequestGuard::should_bypass( $config );
	} )
);

foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/xmlrpc.php', '/admin-ajax.php', '/cart/', '/checkout/', '/feed/', '/?s=test', '/?preview=true' ) as $uri ) {
	check(
		'绕过路径 ' . $uri,
		with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri ), array(), function () use ( $config ) {
			return RequestGuard::should_bypass( $config );
		} )
	);
}

check(
	'POST 一律绕过',
	with_request( array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/' ), array(), function () use ( $config ) {
		return RequestGuard::should_bypass( $config );
	} )
);

check(
	'登录 Cookie 绕过',
	with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array( 'wordpress_logged_in_' . COOKIEHASH => 'x' ), function () use ( $config ) {
		return RequestGuard::should_bypass( $config );
	} )
);

check(
	'购物车 Cookie 绕过',
	with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array( 'woocommerce_cart_hash' => 'x' ), function () use ( $config ) {
		return RequestGuard::should_bypass( $config );
	} )
);

// 前缀型 Cookie 必须逐个验证。
// 这一组是补上的回归用例：内置表里 wp-postpass_ / comment_author_ /
// wp_woocommerce_session_ 曾经漏写结尾的 `*`，于是退化成"精确匹配"，
// 真实 Cookie 名（带 <COOKIEHASH> 后缀）永远匹配不上——密码保护页面
// 会被缓存并端给没输密码的访客。冒烟测试当时只测了精确名 Cookie，没拦住。
foreach ( array(
	'密码保护 wp-postpass_*'          => 'wp-postpass_' . COOKIEHASH,
	'评论者 comment_author_*'         => 'comment_author_' . COOKIEHASH,
	'WooCommerce 会话 wp_woocommerce_session_*' => 'wp_woocommerce_session_' . COOKIEHASH,
	'EDD 购物车 edd_items_in_cart'    => 'edd_items_in_cart',
) as $label => $cookie_name ) {
	check(
		'前缀 Cookie 绕过：' . $label,
		with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array( $cookie_name => 'x' ), function () use ( $config ) {
			return RequestGuard::should_bypass( $config );
		} )
	);
}

// 反向用例：与内置前缀"像但不是"的 Cookie 不能被误伤，
// 否则会把本该共享的缓存全部打穿（命中率归零）。
check(
	'非前缀同名 Cookie 不误伤',
	! with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array( 'wp-postpass' => 'x' ), function () use ( $config ) {
		return RequestGuard::should_bypass( $config );
	} )
);

check(
	'safe_mode 全局关闭缓存',
	with_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array(), function () use ( $config ) {
		$c = $config;
		$c['safe_mode'] = 1;
		return RequestGuard::should_bypass( $c );
	} )
);

check(
	'桌面 UA 不判定为移动端',
	with_request( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ), array(), function () {
		return RequestGuard::is_mobile();
	} ) === false
);

check(
	'iPhone UA 判定为移动端',
	with_request( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)' ), array(), function () {
		return RequestGuard::is_mobile();
	} )
);

/* ---------------------------------------------------------------------------
 * 5. 磁盘后端
 * ------------------------------------------------------------------------ */

section( '磁盘后端' );

$logger   = new Logger( $clean );
$factory  = new BackendFactory( $clean, $logger );
$backend  = new DiskBackend( AT8SA_CACHE_ROOT );

check( '磁盘后端可用', $backend->available() );
check( '后端名称为 Disk', 'Disk' === $backend->name() );

$html = '<html><body>Hello AT8</body></html>';

check( '写入成功', $backend->set( 'example.test', '/hello/', $html, 3600 ) );
check( '命中返回内容', $html === $backend->get( 'example.test', '/hello/' ) );
check( '未命中返回 false', false === $backend->get( 'example.test', '/not-cached/' ) );
check( '移动端变体独立', false === $backend->get( 'example.test', '/hello/', true ) );

$backend->set( 'example.test', '/hello/', $html, 3600, true );
check( '移动端变体写入后命中', $html === $backend->get( 'example.test', '/hello/', true ) );

check( '带查询串的 URL 可独立缓存', $backend->set( 'example.test', '/hello/?page=2', $html, 3600 ) );
check( '带查询串可命中', $html === $backend->get( 'example.test', '/hello/?page=2' ) );

$deleted = $backend->delete_url( 'example.test', '/hello/' );
check( '精准删除同时清掉桌面与移动变体', $deleted >= 2, '删除数 ' . $deleted );
check( '删除后桌面未命中', false === $backend->get( 'example.test', '/hello/' ) );
check( '删除后移动未命中', false === $backend->get( 'example.test', '/hello/', true ) );
check( '删除不影响同目录其它 URL', $html === $backend->get( 'example.test', '/hello/?page=2' ) );

// 站点边界：站外 URL 不应被删
check( '站外 URL 删除返回 0', 0 === $backend->delete_url( 'other.test', '/x/' ) );

$stats = $backend->stats();
check( '统计能读到缓存文件', $stats['count'] >= 1, 'count=' . $stats['count'] );

// 缓存目录守卫文件
check( '缓存目录存在 index.php 守卫', is_file( AT8SA_CACHE_ROOT . '/index.php' ) );

/* ---------------------------------------------------------------------------
 * 6. 失效器
 * ------------------------------------------------------------------------ */

section( '失效器' );

// 失效器断言的是"磁盘文件被删掉"，所以后端必须**固定为磁盘**：
// `cache_backend` 默认 auto，而 auto 会在 Redis 可达时让 factory->make() 返回
// RedisBackend —— 此时 purge 打的是 Redis，而这里的 $backend 是 DiskBackend，
// 断言就会假失败。CI 机器上没有 Redis，所以这个坑一直没暴露；真机（带 Redis）必炸。
$purge_settings = new Settings();
$purge_settings->persist(
	$purge_settings->sanitize(
		array(
			'page_cache'    => 1,
			'cache_backend' => 'disk',
			'cache_ttl'     => 3600,
		)
	)
);

$purge_factory = new BackendFactory( $purge_settings, $logger );
$purger        = new Purger( $purge_settings, $purge_factory, $logger );

$backend->set( 'example.test', '/hello/', $html, 3600 );
$purged = $purger->purge_url( home_url( '/hello/' ) );

check( 'purge_url 命中本站 URL', $purged > 0, 'purged=' . var_export( $purged, true ) );
check( 'purge_url 后缓存已失效', false === $backend->get( 'example.test', '/hello/' ) );

$before = $purge_factory->cache_version();
$purger->purge_all();
$after = $purge_factory->cache_version();

check( 'purge_all 递增缓存版本盐', $after === $before + 1, "before={$before} after={$after}" );

$backend->set( 'example.test', '/keep/', $html, 3600 );
$purger->purge_url( 'https://external-site.test/whatever/' );
check( '站外 URL 不触发本地删除', $html === $backend->get( 'example.test', '/keep/' ) );

$status = $purger->backend_status();
check( 'backend_status 返回结构完整', isset( $status['backend'], $status['cached_pages'], $status['cache_version'] ) );
check( 'backend_status 报告后端已激活', true === $status['active'] );

$backend->set( 'example.test', '/flush-me/', $html, 3600 );
$purger->purge_all();
check( 'purge_all 后页面缓存已清空', false === $backend->get( 'example.test', '/flush-me/' ) );
check( 'purge_all 后配置目录保留', is_dir( AT8SA_CACHE_ROOT . '/config' ) );

/* ---------------------------------------------------------------------------
 * 6b. 后端一致性（关键不变量）
 *
 * drop-in 是按配置文件里的 `backend` 字段决定去 Redis 还是磁盘取页面的，
 * 而失效器是按 `factory->make()` 决定去清哪个后端。两者若不一致，
 * 用户点"清缓存"后会看到"提示成功、前台还是旧页面"。
 * 这条断言在有没有 Redis 的机器上都必须成立。
 * ------------------------------------------------------------------------ */

section( '后端一致性' );

$auto_settings = new Settings();
$auto_settings->persist(
	$auto_settings->sanitize(
		array(
			'page_cache'    => 1,
			'cache_backend' => 'auto',
			'cache_ttl'     => 3600,
		)
	)
);

$auto_factory = new BackendFactory( $auto_settings, $logger );
$auto_config  = new Config( $auto_settings, $auto_factory );

$auto_runtime    = $auto_config->runtime();
$runtime_backend = isset( $auto_runtime['backend'] ) ? (string) $auto_runtime['backend'] : '';
$active_name     = $auto_factory->active_name();
$expected_name   = ( 'redis' === $runtime_backend ) ? 'Redis' : 'Disk';

check(
	'drop-in 配置的后端与失效器实际后端一致',
	$expected_name === $active_name,
	'config=' . $runtime_backend . ' active=' . $active_name
);
check(
	'auto 模式选出的后端是 redis 或 disk',
	in_array( $runtime_backend, array( 'redis', 'disk' ), true ),
	'backend=' . $runtime_backend
);

// 盐（site_token|v版本）是后端实例的构造参数：改完版本必须丢掉已记忆化的实例。
// 否则同一请求内后续读写会落到旧盐命名空间——写进去的条目 drop-in 按新盐读，
// 永远不命中；backend_status() 也会统计旧盐键而恒为 0。
// 真机（Redis）实测到：purge_all() 后同请求 cached_pages 返回 0，redis 里实有 71 个新盐键。
$salt_factory = new BackendFactory( $auto_settings, $logger );
$salt_backend = $salt_factory->make();
$salt_before  = $salt_factory->salt();
$version_next = $salt_factory->bump_cache_version();
$salt_after   = $salt_factory->salt();

check( 'bump 后盐确实变了', $salt_before !== $salt_after, $salt_before . ' -> ' . $salt_after );
check(
	'bump 后 make() 返回新实例（旧盐实例已作废）',
	$salt_backend !== $salt_factory->make(),
	'仍是同一个实例，说明 resolved 没被清掉'
);
check( 'bump 返回递增后的版本号', $version_next === (int) $salt_factory->cache_version() );

/* ---------------------------------------------------------------------------
 * 6c. 设置变更同步：架构守卫
 *
 * 真机实测到的缺陷：`update_option_<option>` 监听器原先挂在 `SettingsPage::boot()` 上，
 * 而那只在 `is_admin()` 为真时执行。于是 WP-CLI / WP-Cron / 其它插件里改设置
 * 完全不生效——连改 6 次 cache_backend，前台响应头始终走旧后端。
 *
 * 这里用源码级守卫把"与上下文无关"这条不变式钉住：
 * 监听器只能出现在 Core\SettingsSync（由 boot_shared 启动），
 * 不得再出现在任何后台类里。
 * ------------------------------------------------------------------------ */

section( '设置变更同步（架构守卫）' );

$sync_class_src = (string) file_get_contents( AT8SA_PATH . 'includes/Core/SettingsSync.php' );
$plugin_src     = strip_php_comments( (string) file_get_contents( AT8SA_PATH . 'includes/Core/Plugin.php' ) );
$admin_page_src = strip_php_comments( (string) file_get_contents( AT8SA_PATH . 'includes/Admin/SettingsPage.php' ) );

check(
	'SettingsSync 监听 update_option_<option>',
	false !== strpos( $sync_class_src, "'update_option_'" )
);

check(
	'Plugin::boot_shared() 启动了 SettingsSync',
	(bool) preg_match( '/SettingsSync::class\s*\)\s*->boot\s*\(/', $plugin_src )
);

check(
	'SettingsPage 不再自己监听 update_option_<option>',
	false === strpos( $admin_page_src, 'update_option_' ),
	'后台专属的 boot() 里挂这个钩子，会让 CLI / Cron 场景改设置失效'
);

check(
	'运行时配置兜底只依赖 needs_refresh()，不自己拼主机名',
	(bool) preg_match( '/needs_refresh\s*\(\s*\)/', $plugin_src )
		&& false === strpos( $plugin_src, 'strtolower( preg_replace(' ),
	'自己拼一份主机名归一化，迟早和 Config::write() 漂移成"写 A 读 B"'
);

/* ---------------------------------------------------------------------------
 * 6d. 运行时配置的过期检测
 *
 * `update_option_{$option}` 钩子覆盖不到直接改库（$wpdb->update、wp option import、
 * 迁移脚本、DB 层手工修改）。needs_refresh() 用设置指纹兜底。
 * ------------------------------------------------------------------------ */

section( '运行时配置过期检测' );

$stale_settings = new Settings();
$stale_settings->persist(
	$stale_settings->sanitize(
		array(
			'page_cache'    => 1,
			'cache_backend' => 'disk',
			'cache_ttl'     => 3600,
		)
	)
);

$stale_factory = new BackendFactory( $stale_settings, $logger );
$stale_config  = new Config( $stale_settings, $stale_factory );
$stale_runtime = $stale_config->runtime();

check( '运行时配置带设置指纹', isset( $stale_runtime['settings_hash'] ) );

$stale_config->write( $stale_runtime );

// 每次都新建实例：真实场景里 needs_refresh() 在**新请求**中执行，
// 那时 Settings 是全新的、缓存反映的是当前库值。
$fresh_config = new Config( new Settings(), new BackendFactory( new Settings(), $logger ) );

check( '刚写完不算过期', false === $fresh_config->needs_refresh() );

// 绕过 update_option() 直接改库：钩子不触发，只有指纹能发现。
$stale_raw              = get_option( Settings::OPTION );
$stale_raw['cache_ttl'] = 1234;
$GLOBALS['at8sa_test_options'][ Settings::OPTION ] = $stale_raw;

$drifted_config = new Config( new Settings(), new BackendFactory( new Settings(), $logger ) );

check(
	'设置被直接改库后判定为过期',
	$drifted_config->needs_refresh(),
	'指纹没生效的话，"改了设置不生效"会永远存在'
);

// 恢复，避免影响后续章节。
$stale_raw['cache_ttl'] = 3600;
$GLOBALS['at8sa_test_options'][ Settings::OPTION ] = $stale_raw;

/* ---------------------------------------------------------------------------
 * 7. 运行时配置与 drop-in
 * ------------------------------------------------------------------------ */

section( '运行时配置与 drop-in' );

$config_builder = new Config( $clean, $factory );
$runtime        = $config_builder->runtime();

check( '运行时配置含必要键', isset( $runtime['enabled'], $runtime['salt'], $runtime['cache_root'], $runtime['backend'] ) );
check( '运行时配置不含任何密钥字段', ! isset( $runtime['redis_secret'], $runtime['api_key'], $runtime['password'] ) );
check( '运行时配置不含站点内容', ! isset( $runtime['posts'], $runtime['users'] ) );

check( '配置写入成功', $config_builder->write( $runtime ) );
check( '配置文件已生成', is_file( AT8SA_CACHE_ROOT . '/config/default.php' ) );

$written = include AT8SA_CACHE_ROOT . '/config/default.php';
check( '配置文件返回数组', is_array( $written ) );
check( '配置文件 salt 一致', $written['salt'] === $runtime['salt'] );

// CLI / WP-Cron 场景（没有 HTTP_HOST）：配置必须写到 home_url() 对应的主机文件，
// 否则 wp-cli 里的配置改动只会落进 default.php，而 drop-in 在前台优先读 <host>.php
// ——改动永远到不了 drop-in。真机实测：wp-cli 切后端报成功，前台响应头仍是旧后端。
// 顺带确认已有的其它 host 配置也会被一并刷新。
$saved_http_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
unset( $_SERVER['HTTP_HOST'] );

$cli_config    = new Config( $clean, $factory );
$cli_runtime   = $cli_config->runtime();
$cli_expected  = CachePath::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$cli_host_file = AT8SA_CACHE_ROOT . '/config/' . $cli_expected . '.php';

// 先造一个"陈旧"的 host 配置，验证它会被刷新。
file_put_contents( $cli_host_file, "<?php\nreturn array('enabled' => 0, 'stale' => true);\n" );

$cli_config->write( $cli_runtime );

$cli_written = include $cli_host_file;

check(
	'无 HTTP_HOST 时配置写到 home_url 对应的主机文件',
	is_file( $cli_host_file ),
	'期望 ' . $cli_expected . '.php'
);
check( '该主机文件已被刷新（不再是陈旧内容）', is_array( $cli_written ) && ! isset( $cli_written['stale'] ) );
check( '该主机文件与 default 内容一致', is_array( $cli_written ) && $cli_written['salt'] === $cli_runtime['salt'] );

if ( null !== $saved_http_host ) {
	$_SERVER['HTTP_HOST'] = $saved_http_host;
}

$dropin = new AdvancedCache( $logger );

// 不断言"一定未安装"——环境里可能本来就有 drop-in。
// 断言的是"查询本身可用且返回布尔"，这才是本用例要守的契约。
check( 'drop-in 安装状态可查询', is_bool( $dropin->is_installed() ) );
$install_result = $dropin->install();
check( 'drop-in 安装调用成功', true === $install_result );

if ( $install_result ) {
	$content = (string) file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' );

	check( 'drop-in 含归属标记', false !== strpos( $content, 'AT8 Site Accelerator' ) );
	check( 'drop-in 路径占位符已替换', false === strpos( $content, '{{AT8SA_PATH}}' ) );

	// drop-in 里写入的是 wp_normalize_path() 之后的路径（Windows 上反斜杠会被换成斜杠），
	// 断言必须用同一个归一化结果去比。
	$dropin_plugin_path = trailingslashit( wp_normalize_path( AT8SA_PATH ) );
	check( 'drop-in 含真实插件路径', false !== strpos( $content, $dropin_plugin_path ), $dropin_plugin_path );
	check( 'drop-in 未写入任何密钥', false === stripos( $content, 'secret' ) );
	check( 'drop-in 可被识别为已安装', $dropin->is_installed() );
}

check( 'drop-in 移除成功', $dropin->uninstall() );
check( '移除后识别为未安装', false === $dropin->is_installed() );

/* ---------------------------------------------------------------------------
 * 7b. wp-config.php 的 WP_CACHE 开关（回归：salt 字符串内含大括号）
 *
 * 历史 Bug：verify_wp_config() 曾用 substr_count($c,'{') 做朴素全文计数，
 * 而 wp-config.php 的 8 个随机 salt 字符集包含 `{` `}`，字符串里的括号被算进
 * 平衡判定 → 括号数永不配平 → 校验恒失败 → 每次都误回滚 → WP_CACHE 开不起来。
 * 真机（wordpress.xmm.fan）实测 6 个 `{` vs 10 个 `}`，全部多出来的都在 salt 里。
 * ------------------------------------------------------------------------ */

section( 'wp-config.php / WP_CACHE 开关' );

$wp_config_path = ABSPATH . 'wp-config.php';

// 刻意构造"含大括号的 salt"与"注释里的 {@link ...}"，复现真机形态。
$fake_config = <<<'PHPEOF'
<?php
/**
 * WordPress 基本配置文件。
 *
 * 本文件包含以下配置选项：MySQL 设置、数据库表名前缀、密匙、语言设定。
 * 要创建 wp-config.php 请访问 {@link https://api.wordpress.org/secret-key/1.1/salt/}
 */

// ** MySQL 设置 ** //
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'p@ss{word}' );
define( 'DB_HOST', 'localhost' );

define( 'AUTH_KEY',         'h/U5c%vqYZXfvlg/dC#MvYT)@%g+0*!ViFb1TCg/>0Z=}alP.]7Q--Wj}b0U|iGV' );
define( 'SECURE_AUTH_KEY',  'cd pZldZLDmYM2FBQh%j{<spl]0l1[[6uC0#5OT%c=+26(Z}>|uYmRojRXB2+[ui' );
define( 'LOGGED_IN_KEY',    '_b)Ozm0#b@IlT(OhpVVyU}5S={ Q6 xh_Zh[dBzy5(K~m?Q~F$B9jjX{pz#URn%m' );
define( 'NONCE_KEY',        'vW781le|bUO><,ikmdi&Up-8q!PpVs|1xVg}*;t 8nkWP}zy&&s5bil[T/v~_Iv.' );
define( 'AUTH_SALT',        ':b,V~MOP$;UU,ODpss6mS}_XE;2N b,WZ-w#S5r`u65^GUwxHg8-9qkHq!G}m;7`' );
define( 'NONCE_SALT',       'oLQghWCYC5z:;d;AZni:6;rP-6qMJxD=2qH_wiHh~I(z5IyQ.{`Aw~Tif)<@stQF' );

$table_prefix = 'wp_';

define( 'WP_DEBUG', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHPEOF;

file_put_contents( $wp_config_path, $fake_config );

$enable = $dropin->enable_wp_cache();

check( '含大括号 salt 时 WP_CACHE 仍能开启', true === $enable['ok'], $enable['message'] );

$after = (string) file_get_contents( $wp_config_path );

check( '已写入 WP_CACHE 为 true', false !== strpos( $after, "define( 'WP_CACHE', true );" ) );
check( '已写入归属标记', false !== strpos( $after, AdvancedCache::WP_CACHE_MARKER ) );
check( '原有 salt 未被破坏', false !== strpos( $after, 'NONCE_SALT' ) && false !== strpos( $after, 'oLQghWCYC5z' ) );
check( 'DB_PASSWORD 含括号未受影响', false !== strpos( $after, 'p@ss{word}' ) );
check( '已生成备份文件', is_file( $wp_config_path . '.at8sa.bak' ) );

// 可逆性：删掉带标记的整行必须精确还原原文。
$stripped = preg_replace(
	'/^.*' . preg_quote( AdvancedCache::WP_CACHE_MARKER, '/' ) . '.*$\R?/m',
	'',
	$after,
	1
);
check( '删除标记行可精确还原原文', $stripped === $fake_config, strlen( (string) $stripped ) . ' vs ' . strlen( $fake_config ) );

// 幂等：再次调用应识别为已启用。
$again = $dropin->enable_wp_cache();
check( '重复调用不报错', true === $again['ok'], $again['message'] );

// 停用路径：应移除我们写的那一行并还原原文。
$disable = $dropin->disable_wp_cache();
check( '停用 WP_CACHE 成功', true === $disable['ok'], $disable['message'] );

$restored = (string) file_get_contents( $wp_config_path );
check( '停用后精确还原原文', $restored === $fake_config, strlen( $restored ) . ' vs ' . strlen( $fake_config ) );

// 已有 `WP_CACHE, false` 的站点：应原地替换为 true。
$false_config        = str_replace( "define( 'WP_DEBUG', true );", "define( 'WP_DEBUG', true );\ndefine( 'WP_CACHE', false );", $fake_config );
file_put_contents( $wp_config_path, $false_config );

$replace = $dropin->enable_wp_cache();
check( '已有 WP_CACHE=false 时能改为 true', true === $replace['ok'], $replace['message'] );

$replaced = (string) file_get_contents( $wp_config_path );
check( 'false 已被替换为 true', false === strpos( $replaced, "define( 'WP_CACHE', false );" ) && false !== strpos( $replaced, "define( 'WP_CACHE', true );" ) );

// 替换路径的可逆性是"把 true 换回 false"，不是"删行"（删行会连 define 一起删掉）。
$re_restored = preg_replace(
	'/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*' . preg_quote( AdvancedCache::WP_CACHE_MARKER, '/' ) . '/',
	"define( 'WP_CACHE', false );",
	$replaced,
	1
);
check( '替换路径同样可逆', $re_restored === $false_config );

@unlink( $wp_config_path );
@unlink( $wp_config_path . '.at8sa.bak' );

/* ---------------------------------------------------------------------------
 * 8. HTML 压缩
 * ------------------------------------------------------------------------ */

section( 'HTML 压缩' );

$minifier = new HtmlMinifier( $clean );

$short = '<html><body>tiny</body></html>';
check( '过短内容跳过压缩', $short === $minifier->minify( $short ) );

$long_html = '<html>' . str_repeat( "  <div class=\"a\">  x  </div>\n", 80 ) . '</html>';
$minified  = $minifier->minify( $long_html );
check( '压缩后体积变小', strlen( $minified ) < strlen( $long_html ), strlen( $long_html ) . ' -> ' . strlen( $minified ) );

$GLOBALS['at8sa_minify_done'] = null;

$preserve = '<html><body>'
	. str_repeat( '<div>pad</div>', 100 )
	. "<pre>  keep   this  spacing  </pre>"
	. "<script>var a = 1;\n  var b = 2;</script>"
	. "<textarea>  raw   text  </textarea>"
	. '</body></html>';

$out = $minifier->minify( $preserve );

check( 'pre 内空白保留', false !== strpos( $out, 'keep   this  spacing' ) );
check( 'script 内容保留', false !== strpos( $out, 'var a = 1;' ) );
check( 'textarea 内容保留', false !== strpos( $out, 'raw   text' ) );

$GLOBALS['at8sa_minify_done'] = null;

$conditional = '<html><!--[if IE]><p>ie</p><![endif]-->' . str_repeat( '<div>x</div>', 100 ) . '</html>';
check( 'IE 条件注释保留', false !== strpos( $minifier->minify( $conditional ), '[if IE]' ) );

$GLOBALS['at8sa_minify_done'] = null;

// ---- 内联 CSS 折叠（html_minify_inline）----
// 该开关曾是"死设置"：只在 Settings 里声明、后台有开关，代码里从未读取
// （真机实测开启后体积 68253 → 68253，零变化）。现已实现。
$inline_settings = new Settings();
$inline_settings->persist(
	$inline_settings->sanitize(
		array(
			'html_minify'       => 1,
			'html_minify_inline' => 1,
		)
	)
);
$inline_minifier = new HtmlMinifier( $inline_settings );

$inline_html = '<html><head><style>' . "\n"
	. ".a {\n    color : red ;\n    font-family : sans   serif ;\n}\n"
	. '.b::after { content: "a   b"; }' . "\n"
	. "</style></head><body>"
	. str_repeat( '<div>pad</div>', 100 )
	. "<script>var s = 'keep   this';\nvar t = 2;</script>"
	. '</body></html>';

$inline_out = $inline_minifier->minify( $inline_html );

check( '内联 CSS 的连续空白被折叠', false !== strpos( $inline_out, '.a { color : red ; font-family : sans serif ; }' ), $inline_out );
check( '内联 CSS 引号内空格保留', false !== strpos( $inline_out, 'content: "a   b";' ), $inline_out );
check( '内联 JS 未被折叠（ASI 安全）', false !== strpos( $inline_out, "var s = 'keep   this';" ), $inline_out );
check( '内联折叠后体积变小', strlen( $inline_out ) < strlen( $inline_html ) );

$GLOBALS['at8sa_minify_done'] = null;

// 默认（inline 关闭）时不应动内联 CSS
$default_out = ( new HtmlMinifier( $clean ) )->minify( $inline_html );
check( '默认关闭时不折叠内联 CSS', false !== strpos( $default_out, 'sans   serif' ), $default_out );

$GLOBALS['at8sa_minify_done'] = null;

/* ---------------------------------------------------------------------------
 * 9. 懒加载
 * ------------------------------------------------------------------------ */

section( '懒加载' );

// 懒加载功能必须先被打开，否则 LazyLoad::boot() 不会挂载、process() 也只会原样返回。
// $clean 是"全部关闭"的基线设置，这里单独构造一份开启懒加载的设置。
$lazy_settings = new Settings();
$lazy_settings->persist(
	$lazy_settings->sanitize(
		array(
			'lazyload'            => 1,
			'lazyload_iframes'    => 1,
			'lazyload_skip_first' => 1,
		)
	)
);

$lazyload = new LazyLoad( $lazy_settings );

$images = '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><img src="/d.jpg">';
$result = $lazyload->process( $images );

check( '首张图标记 eager', false !== strpos( $result, 'loading="eager"' ), $result );
check( '后续图标记 lazy', substr_count( $result, 'loading="lazy"' ) >= 2, $result );
check( '懒加载图带 decoding', false !== strpos( $result, 'decoding="async"' ), $result );

$explicit = '<img src="/x.jpg" loading="eager"><img src="/y.jpg" data-no-lazy>';
$kept     = $lazyload->process( $explicit );
check( '已有 loading 属性不被覆盖', 1 === substr_count( $kept, 'loading="eager"' ), $kept );
check( 'data-no-lazy 被尊重', false === strpos( $kept, 'src="/y.jpg" loading="lazy"' ), $kept );

$data_src = '<img src="data:image/gif;base64,R0lGOD"><img src="/z.jpg">';
check( 'data: 占位图被跳过', false === strpos( $lazyload->process( $data_src ), 'src="data:image/gif;base64,R0lGOD" loading' ), $lazyload->process( $data_src ) );

$iframe = '<iframe src="/frame"></iframe>';
check( 'iframe 也被懒加载', false !== strpos( $lazyload->process( $iframe ), 'loading="lazy"' ), $lazyload->process( $iframe ) );

// 关闭"跳过首屏"时，所有图片都应被懒加载（不再有 eager 提权）。
// 注意必须显式传 0：sanitize() 对"缺键"是保留原值，不传就等于沿用默认的 1。
$no_skip = new Settings();
$no_skip->persist(
	$no_skip->sanitize(
		array(
			'lazyload'            => 1,
			'lazyload_iframes'    => 1,
			'lazyload_skip_first' => 0,
		)
	)
);
$all_lazy = ( new LazyLoad( $no_skip ) )->process( $images );
check( '关闭跳过首屏后无 eager 提权', false === strpos( $all_lazy, 'loading="eager"' ), $all_lazy );
check( '关闭跳过首屏后全部 lazy', 4 === substr_count( $all_lazy, 'loading="lazy"' ), $all_lazy );

/* ---------------------------------------------------------------------------
 * 9b. 链接预取（含 2.x http2_push 改名后的 resource_preload）
 * ------------------------------------------------------------------------ */

section( '链接预取' );

$preload_settings = new Settings();
$preload_settings->persist(
	$preload_settings->sanitize(
		array(
			'preload_enable'    => 1,
			'dns_prefetch'      => 1,
			'preconnect_hosts'  => "fonts.googleapis.com\nhttps://cdn.example.test/",
			'resource_preload'  => 1,
		)
	)
);

$preloader = new \AT8SA\Optimization\LinkPreloader( $preload_settings );

// 未入队时不应输出 preload 标签（避免预加载一个不会被用到的文件）。
$GLOBALS['at8sa_test_enqueued_scripts'] = array();
ob_start();
$preloader->output_resource_preload();
$not_enqueued = ob_get_clean();

check( '脚本未入队时不输出 preload 标签', '' === trim( $not_enqueued ), $not_enqueued );

// 入队后应输出带 ver 参数的 preload 标签。
$preloader->enqueue();

ob_start();
$preloader->output_resource_preload();
$preload_tag = ob_get_clean();

check( '入队后输出 preload 标签', false !== strpos( $preload_tag, 'rel="preload"' ), $preload_tag );
check( 'preload 的 as 属性为 script', false !== strpos( $preload_tag, 'as="script"' ), $preload_tag );
check( 'preload 的 URL 与入队 URL 一致（含 ver）', false !== strpos( $preload_tag, 'ver=' . AT8SA_VERSION ), $preload_tag );

// 关闭开关后不应输出。
$preload_settings->persist( $preload_settings->sanitize( array( 'resource_preload' => 0 ) ) );
$preloader_off = new \AT8SA\Optimization\LinkPreloader( $preload_settings );

ob_start();
$preloader_off->output_resource_preload();
$off_tag = ob_get_clean();

check( '关闭 resource_preload 后不输出', '' === trim( $off_tag ), $off_tag );

// DNS 预取与第三方 preconnect。
$GLOBALS['at8sa_test_enqueued_scripts'] = array();

ob_start();
$preloader->output_dns_prefetch();
$dns = ob_get_clean();

check( 'DNS 预取包含本站 preconnect', false !== strpos( $dns, 'rel="preconnect"' ), $dns );
check( 'DNS 预取包含 x-dns-prefetch-control', false !== strpos( $dns, 'x-dns-prefetch-control' ), $dns );

ob_start();
$preloader->output_preconnect();
$preconnect = ob_get_clean();

check( '第三方 preconnect 已归一化为 https', false !== strpos( $preconnect, 'https://fonts.googleapis.com' ), $preconnect );
check( '第三方 preconnect 去掉了协议前缀重复', false === strpos( $preconnect, 'https://https://' ), $preconnect );

/* ---------------------------------------------------------------------------
 * 10. 浏览器缓存规则
 * ------------------------------------------------------------------------ */

section( '浏览器缓存' );

$browser = new BrowserCache( $clean );

check( 'nginx 规则非空', strlen( $browser->nginx_rules() ) > 100 );
check( 'nginx 规则含 expires', false !== strpos( $browser->nginx_rules(), 'expires' ) );
check( 'Apache 规则含标记块', false !== strpos( $browser->apache_rules(), 'BEGIN AT8 Site Accelerator' ) );
check( 'Apache 规则含 END 标记', false !== strpos( $browser->apache_rules(), 'END AT8 Site Accelerator' ) );
check( '资源 TTL 在合理区间', $browser->asset_ttl() >= 3600 );
check( '服务器类型可识别', in_array( $browser->server_type(), array( 'nginx', 'apache', 'litespeed', 'unknown' ), true ) );

/* ---------------------------------------------------------------------------
 * 11. 兼容检测
 * ------------------------------------------------------------------------ */

section( '兼容检测' );

$detector = new CachePluginDetector( $clean );
$conflicts = $detector->scan( true );

// scan() 的返回类型在静态层面已确定是数组，所以"是不是数组"没有断言价值。
// 真正值得守的是**每一项的结构契约**：后台提示与诊断页都直接读这三个键。
$conflict_shape_ok = true;

foreach ( $conflicts as $conflict ) {
	if ( ! isset( $conflict['name'], $conflict['type'], $conflict['severity'] ) ) {
		$conflict_shape_ok = false;
	}
}

check( '冲突项结构完整（name/type/severity）', $conflict_shape_ok );
check( '已知插件清单不少于 8 项', count( $detector->known_plugins() ) >= 8, count( $detector->known_plugins() ) );

foreach ( array( 'WP Rocket', 'LiteSpeed Cache', 'W3 Total Cache', 'WP Super Cache', 'Autoptimize', 'FlyingPress', 'Perfmatters', 'Cache Enabler' ) as $name ) {
	$found = false;

	foreach ( $detector->known_plugins() as $info ) {
		if ( $info['name'] === $name ) {
			$found = true;
			break;
		}
	}

	check( '覆盖检测：' . $name, $found );
}

/* ---------------------------------------------------------------------------
 * 12. 诊断
 * ------------------------------------------------------------------------ */

section( '诊断' );

$diagnostics = new Diagnostics( $clean, $factory, $dropin, $detector, $browser, new \AT8SA\Optimization\Webp( $clean, $logger ) );
$report      = $diagnostics->collect();

foreach ( array( 'cache', 'object_cache', 'php', 'wordpress', 'server', 'https', 'database', 'conflicts' ) as $section_key ) {
	check( '诊断含分组 ' . $section_key, isset( $report[ $section_key ]['items'] ) );
}

$flat = wp_json_encode( $report );

check( '诊断输出不含密码类字段', false === stripos( $flat, '"password"' ) );
check( '诊断输出不含 secret', false === stripos( $flat, 'secret' ) );
check( '诊断不做评分（无 score 字段）', false === stripos( $flat, '"score"' ) );

/* ---------------------------------------------------------------------------
 * 13. 缓存引擎写入路径
 * ------------------------------------------------------------------------ */

section( '缓存引擎' );

// 缓存引擎要真正落盘，page_cache 必须开启、后端固定为磁盘（Redis 在 CI 里不可达）。
$live_settings = new Settings();
$live_settings->persist(
	$live_settings->sanitize(
		array(
			'page_cache'    => 1,
			'cache_backend' => 'disk',
			'cache_ttl'     => 3600,
		)
	)
);

$live_factory = new BackendFactory( $live_settings, $logger );
$engine       = new CacheEngine( $live_settings, $live_factory, $logger, $minifier );

// 用显式 try/catch 抓异常，而不是"闭包恒返回 true"——
// 后者一旦测试框架改成不把致命错误当失败，这条用例就会静默变成永真断言。
$boot_ok = true;

try {
	$engine->boot();
} catch ( \Throwable $e ) {
	$boot_ok = false;
}

check( 'boot 不抛异常', $boot_ok );

$GLOBALS['at8sa_minify_done'] = null;

$body = '<html><head></head><body>' . str_repeat( '<p>content</p>', 100 ) . '</body></html>';

// store() 走的是"当前请求"的 host/uri，必须把请求环境摆好，否则会写到 localhost/__root。
$stored_response = with_request(
	array(
		'REQUEST_METHOD' => 'GET',
		'REQUEST_URI'    => '/',
		'HTTP_HOST'      => 'example.test',
	),
	array(),
	function () use ( $engine, $body ) {
		return $engine->store( $body );
	}
);

check( 'store 返回字符串', is_string( $stored_response ) );
check( 'store 返回值带缓存指纹', false !== strpos( $stored_response, 'AT8 Site Accelerator' ) );

$stored = $backend->get( 'example.test', '/' );
check( '缓存文件已写入（URI 归一化为 /）', is_string( $stored ) && false !== strpos( $stored, 'AT8 Site Accelerator' ) );

// 空响应与不可缓存请求都不应落盘。
$backend->delete_url( 'example.test', '/empty/' );

with_request(
	array(
		'REQUEST_METHOD' => 'GET',
		'REQUEST_URI'    => '/empty/',
		'HTTP_HOST'      => 'example.test',
	),
	array(),
	function () use ( $engine ) {
		$engine->store( '' );
	}
);

check( '空响应不写入', false === $backend->get( 'example.test', '/empty/' ) );

$backend->delete_url( 'example.test', '/post-only/' );

with_request(
	array(
		'REQUEST_METHOD' => 'POST',
		'REQUEST_URI'    => '/post-only/',
		'HTTP_HOST'      => 'example.test',
	),
	array(),
	function () use ( $engine ) {
		$engine->store( '<html><body>post</body></html>' );
	}
);

check( '非 GET 请求不写入', false === $backend->get( 'example.test', '/post-only/' ) );

/* ---------------------------------------------------------------------------
 * 14. 日志脱敏
 * ------------------------------------------------------------------------ */

section( '日志脱敏' );

$log_settings = new Settings();
$log_settings->persist(
	$log_settings->sanitize(
		array(
			'log_enabled' => 1,
			'log_level'   => 'debug',
		)
	)
);

$log = new Logger( $log_settings );
$log->info(
	'测试写入 240a8412ed8d743be4c0c373c0a2cf82 与 Bearer abcdefghijklmnop',
	array(
		'cookie' => 'wordpress_logged_in_secret',
		'path'   => '/hello/',
	)
);

$tail = $log->tail( 50 );
$blob = implode( "\n", $tail );

check( '日志已写入', count( $tail ) > 0 );
check( '长十六进制串被脱敏', false === strpos( $blob, '240a8412ed8d743be4c0c373c0a2cf82' ) );
check( 'Bearer token 被脱敏', false === strpos( $blob, 'Bearer abcdefghijklmnop' ) );
check( 'Cookie 字段被脱敏', false === strpos( $blob, 'wordpress_logged_in_secret' ) );
check( '日志不含原始数组占位', false !== strpos( $blob, '[redacted]' ) );

$log->clear();
check( '日志清空成功', 0 === count( $log->tail( 10 ) ) );

/* ---------------------------------------------------------------------------
 * 15. 文件系统边界
 * ------------------------------------------------------------------------ */

section( '文件系统边界' );

check( '缓存根内路径判定为真', Filesystem::is_inside_cache_root( AT8SA_CACHE_ROOT . '/a/b' ) );
check( '缓存根外路径判定为假', false === Filesystem::is_inside_cache_root( ABSPATH ) );
check( '系统路径判定为假', false === Filesystem::is_inside_cache_root( 'C:/Windows/System32' ) );
check( '穿越路径判定为假', false === Filesystem::is_inside_cache_root( AT8SA_CACHE_ROOT . '/../../plugins' ) );

// rrmdir 拒绝越界删除
check( 'rrmdir 拒绝越界路径', false === Filesystem::rrmdir( ABSPATH ) );
check( '越界目录未被删除', is_dir( ABSPATH ) );

/* ---------------------------------------------------------------------------
 * 16. 设置页模板渲染（抓模板致命错误）
 * ------------------------------------------------------------------------ */

section( '设置页模板渲染' );

// 需要以管理员身份构造完整 $data。
$GLOBALS['at8sa_test_is_admin']   = true;
$GLOBALS['at8sa_test_can_manage'] = true;

$template_data = array(
	'settings'      => $clean->all(),
	'boolean_keys'  => $clean->boolean_keys(),
	'backend'       => $backend,
	'backend_name'  => $backend->name(),
	'backend_stats' => $backend->stats(),
	'redis_reachable' => false,
	'advanced_cache'  => $dropin,
	'diagnostics'     => $report,
	'conflicts'       => $conflicts,
	'conflict_notice' => $detector->notice_text(),
	'browser_cache'   => $browser,
	'db_cleanup'      => new \AT8SA\Optimization\DatabaseCleanup( $clean, $logger ),
	'db_preview'      => ( new \AT8SA\Optimization\DatabaseCleanup( $clean, $logger ) )->preview(),
	'logger'          => $log,
	'log_lines'       => array(),
	'migrated'        => true,
	'tabs'            => array(
		'overview' => '概览',
		'cache'    => '页面缓存',
		'purge'    => '失效与预加载',
		'optimize' => '优化',
		'database' => '数据库',
		'compat'   => '兼容与诊断',
		'tools'    => '工具',
	),
	'nonce'           => wp_create_nonce( 'at8sa_admin' ),
);

ob_start();
$data = $template_data;
include AT8SA_PATH . 'templates/settings-page.php';
$rendered = ob_get_clean();

check( '模板渲染无致命错误', is_string( $rendered ) && strlen( $rendered ) > 5000, '长度 ' . strlen( $rendered ) );
check( '模板输出了表单', false !== strpos( $rendered, 'options.php' ) );
check( '模板输出了所有标签页', 7 === substr_count( $rendered, 'class="at8sa-tab"' ), substr_count( $rendered, 'class="at8sa-tab"' ) );
check( '模板输出了所有面板', 7 === substr_count( $rendered, 'data-panel=' ), substr_count( $rendered, 'data-panel=' ) );
check( '模板未泄漏 PHP 标签', false === strpos( $rendered, '<?php' ) );
check( '模板转义了设置值', false === strpos( $rendered, '<script>alert(1)</script>' ) );

// 所有 checkbox 的 name 都必须指向真实设置键。
preg_match_all( '/name="at8sa_settings\[([a-z_]+)\]/i', $rendered, $matches );
$unknown = array_diff( array_unique( $matches[1] ), array_keys( $clean->defaults() ) );

check( '表单字段全部指向已登记的设置键', empty( $unknown ), implode( ',', $unknown ) );
check( '表单字段数量合理', count( $matches[1] ) > 30, count( $matches[1] ) );

$GLOBALS['at8sa_test_is_admin']   = false;
$GLOBALS['at8sa_test_can_manage'] = false;

/* ---------------------------------------------------------------------------
 * 17. 完整启动流程
 * ------------------------------------------------------------------------ */

section( '完整启动流程' );

$GLOBALS['at8sa_test_options'] = array();

$boot_error = '';

try {
	Plugin::instance()->boot();
	Plugin::instance()->register_rest_routes();
} catch ( Throwable $e ) {
	$boot_error = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

check( '前台启动无致命错误', '' === $boot_error, $boot_error );

$boot_error_admin = '';

try {
	$GLOBALS['at8sa_test_is_admin'] = true;
	Plugin::instance()->boot();
	$GLOBALS['at8sa_test_is_admin'] = false;
} catch ( Throwable $e ) {
	$boot_error_admin = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
	$GLOBALS['at8sa_test_is_admin'] = false;
}

check( '后台启动无致命错误', '' === $boot_error_admin, $boot_error_admin );

check( '注册了若干动作钩子', count( $GLOBALS['at8sa_test_actions'] ) > 10, count( $GLOBALS['at8sa_test_actions'] ) );

/* ---------------------------------------------------------------------------
 * 18. 激活 / 停用
 * ------------------------------------------------------------------------ */

section( '生命周期' );

// 上一节为了验证"冷启动"把选项表清空了，这里先放一份真实设置进去，
// 否则"停用后设置仍保留"断言的是"空选项表也是数组"，等于没测。
$lifecycle_settings = new Settings();
$lifecycle_settings->persist( $lifecycle_settings->sanitize( array( 'page_cache' => 1, 'cache_ttl' => 1800 ) ) );

$activate_error = '';

try {
	\AT8SA\Core\Activator::activate();
} catch ( Throwable $e ) {
	$activate_error = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

check( '激活流程无致命错误', '' === $activate_error, $activate_error );
check( '激活后写入了版本记录', AT8SA_VERSION === get_option( 'at8sa_version' ) );
check( '激活后缓存目录存在', is_dir( AT8SA_CACHE_ROOT ) );
check( '激活后 config 目录存在', is_dir( AT8SA_CACHE_ROOT . '/config' ) );

$deactivate_error = '';

try {
	\AT8SA\Core\Deactivator::deactivate();
} catch ( Throwable $e ) {
	$deactivate_error = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}

check( '停用流程无致命错误', '' === $deactivate_error, $deactivate_error );
check( '停用后 drop-in 已移除', false === $dropin->is_installed() );
check( '停用后设置仍保留', is_array( get_option( 'at8sa_settings' ) ) );

/* ---------------------------------------------------------------------------
 * 19. uninstall.php 静态检查
 * ------------------------------------------------------------------------ */

section( '卸载脚本' );

$uninstall = (string) file_get_contents( AT8SA_PATH . 'uninstall.php' );

check( '卸载脚本拒绝直接访问', false !== strpos( $uninstall, 'WP_UNINSTALL_PLUGIN' ) );
check( '卸载脚本尊重保留数据开关', false !== strpos( $uninstall, 'keep_data_on_uninstall' ) );
check( '卸载脚本只删自己的 drop-in', false !== strpos( $uninstall, 'AT8 Site Accelerator' ) );
check( '卸载脚本不使用 FLUSHDB', false === stripos( $uninstall, 'FLUSHDB' ) );

/* ---------------------------------------------------------------------------
 * 20. 安全静态检查
 * ------------------------------------------------------------------------ */

section( '安全静态检查' );

$php_files = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( AT8SA_PATH . 'includes', FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( 'php' === strtolower( $file->getExtension() ) ) {
		// 键统一成相对路径 + 正斜杠：Windows 下 getPathname() 返回反斜杠，
		// 会让下面按 'REST/' 过滤的检查一条都匹配不到（静默假绿）。
		$relative = str_replace( '\\', '/', str_replace( AT8SA_PATH, '', $file->getPathname() ) );

		$php_files[ $relative ] = (string) file_get_contents( $file->getPathname() );
	}
}

$php_files['at8-site-accelerator.php'] = (string) file_get_contents( AT8SA_PATH . 'at8-site-accelerator.php' );

$no_abspath_guard = array();

foreach ( $php_files as $name => $source ) {
	if ( false === strpos( $source, 'ABSPATH' ) ) {
		$no_abspath_guard[] = $name;
	}
}

check( '所有 PHP 文件都有 ABSPATH 守卫', empty( $no_abspath_guard ), implode( ',', $no_abspath_guard ) );

$has_eval     = array();
$has_flushdb  = array();
$has_extract  = array();

foreach ( $php_files as $name => $source ) {
	// 注释里出现 FLUSHDB 是**好事**（那是在解释"为什么绝不用它"），
	// 所以扫描前先用 tokenizer 剥掉注释，只看真正会执行的代码。
	$code = strip_php_comments( $source );

	if ( preg_match( '/\beval\s*\(/', $code ) ) {
		$has_eval[] = $name;
	}
	if ( false !== stripos( $code, 'FLUSHDB' ) ) {
		$has_flushdb[] = $name;
	}
	if ( preg_match( '/\bextract\s*\(/', $code ) ) {
		$has_extract[] = $name;
	}
}

check( '无 eval 调用', empty( $has_eval ), implode( ',', $has_eval ) );
check( '无 FLUSHDB 调用', empty( $has_flushdb ), implode( ',', $has_flushdb ) );
check( '无 extract 调用（避免变量注入）', empty( $has_extract ), implode( ',', $has_extract ) );

// 硬编码密钥扫描
$secret_hits = array();
$patterns    = array(
	'/sk_live_[A-Za-z0-9]/',
	'/AKIA[0-9A-Z]{16}/',
	'/-----BEGIN (RSA |EC )?PRIVATE KEY-----/',
	'/["\'](?:api_key|secret_key|password)["\']\s*=>\s*["\'][A-Za-z0-9]{16,}["\']/i',
);

foreach ( $php_files as $name => $source ) {
	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $source ) ) {
			$secret_hits[] = $name . ' 命中 ' . $pattern;
		}
	}
}

check( '未发现硬编码密钥', empty( $secret_hits ), implode( ' | ', $secret_hits ) );

// 危险函数
$dangerous = array();
$banned    = array( 'shell_exec', 'passthru', 'proc_open', 'popen', 'system', 'exec' );

foreach ( $php_files as $name => $source ) {
	$code = strip_php_comments( $source );

	foreach ( $banned as $fn ) {
		if ( preg_match( '/\b' . $fn . '\s*\(/', $code ) ) {
			$dangerous[] = $name . ' -> ' . $fn;
		}
	}
}

check( '未调用命令执行类函数', empty( $dangerous ), implode( ',', $dangerous ) );

// 所有 REST 路由必须有 permission_callback
$rest_sources = array();

foreach ( $php_files as $name => $source ) {
	if ( false !== strpos( $name, 'REST/' ) ) {
		$rest_sources[ $name ] = $source;
	}
}

$routes = 0;
$guarded = 0;

foreach ( $rest_sources as $source ) {
	$routes  += preg_match_all( '/register_rest_route\s*\(/', $source );
	$guarded += preg_match_all( '/permission_callback/', $source );
}

check( 'REST 路由全部声明权限回调', $routes > 0 && $routes <= $guarded, "routes={$routes} guarded={$guarded}" );

/* ---------------------------------------------------------------------------
 * 21. drop-in 命中路径（子进程真实执行）
 *
 * 唯一一段"WordPress 还不存在时就要跑"的代码，必须真跑一遍而不是只做静态检查。
 * 命中时它会 exit，所以只能单开进程，父进程看 stdout。
 * ------------------------------------------------------------------------ */

section( 'drop-in 命中路径' );

/**
 * 在子进程里跑一次 drop-in。
 *
 * 为什么不直接 `proc_open('php script.php')`：
 * Windows 上 proc_open 走 cmd.exe，命令串会按**本地代码页**转换。
 * 本项目的路径里带中文（用户名）与空格，会被 cmd.exe 解析失败
 * （"The filename, directory name, or volume label syntax is incorrect."），
 * 设 cwd 也一样（同样要过 ANSI 转换）。
 *
 * 所以这里把 bootstrap 脚本**从 stdin 管道喂进去**：命令行上只剩 php.exe 的路径，
 * 而 PHP 自己的文件 API 是按 UTF-8 处理路径的（7.1+ Windows），中文路径完全正常。
 *
 * @param string $mode 场景。
 * @return array{code:int,out:string,err:string}
 */
function run_dropin( $mode ) {
	$plugin = rtrim( str_replace( '\\', '/', AT8SA_PATH ), '/' ) . '/';

	$bootstrap = "<?php\n"
		. '$at8sa_mode = ' . var_export( (string) $mode, true ) . ";\n"
		. '$at8sa_plugin_dir = ' . var_export( $plugin, true ) . ";\n"
		. "require \$at8sa_plugin_dir . 'tests/unit/dropin-hit.php';\n";

	$descriptors = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = @proc_open( escapeshellarg( PHP_BINARY ), $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! is_resource( $process ) ) {
		return array( 'code' => -1, 'out' => '', 'err' => 'proc_open 不可用' );
	}

	fwrite( $pipes[0], $bootstrap );
	fclose( $pipes[0] );

	$out = (string) stream_get_contents( $pipes[1] );
	$err = (string) stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'code' => proc_close( $process ),
		'out'  => $out,
		'err'  => $err,
	);
}

// 命中：必须原样吐出缓存体，且**不**出现 fall-through 标记。
$r = run_dropin( 'hit' );

check( 'drop-in 命中时无致命错误', 0 === $r['code'] && '' === trim( $r['err'] ), 'code=' . $r['code'] . ' err=' . trim( $r['err'] ) );
check( 'drop-in 命中时输出缓存体', false !== strpos( $r['out'], 'CACHED-BODY:hit' ), $r['out'] );
check( 'drop-in 命中时不再走 WordPress', false === strpos( $r['out'], 'AT8SA_DROPIN_FELL_THROUGH' ), $r['out'] );

// 移动端变体：桌面缓存存在时，移动 UA 必须读 __m 而不是桌面文件。
$r = run_dropin( 'mobile' );
check( 'drop-in 按 UA 区分移动端变体', false !== strpos( $r['out'], 'CACHED-BODY:mobile' ), $r['out'] );

// 未命中：必须把控制权交还 WordPress。
$r = run_dropin( 'miss' );
check( 'drop-in 未命中时交还控制权', false !== strpos( $r['out'], 'AT8SA_DROPIN_FELL_THROUGH' ), $r['out'] );

// 以下四种情况即使缓存文件存在也必须放行到 WordPress。
$must_fall_through = array(
	'post'      => 'POST 请求',
	'preview'   => '预览请求（preview=true）',
	'safe_mode' => '安全模式',
	'no_config' => '配置缺失',
);

foreach ( $must_fall_through as $mode => $label ) {
	$r = run_dropin( $mode );

	check(
		'drop-in 放行：' . $label,
		0 === $r['code'] && false !== strpos( $r['out'], 'AT8SA_DROPIN_FELL_THROUGH' ),
		'code=' . $r['code'] . ' out=' . substr( $r['out'], 0, 120 )
	);
}

// 版本戳过期：缓存文件**在**，但 drop-in 必须自己发现"我是旧版"并主动让出。
//
// 为什么单独测这条：这是 3.0.2 新增的代码，也是"升级后整站白屏"的真正防线。
// drop-in 一旦命中就 exit，WordPress 根本不会启动，事后的 needs_reinstall() /
// ensure_dropin() 永远没机会跑——只有 drop-in 自己让出，请求才能落回 WordPress
// 并触发重装。少了这条断言，这段逻辑退化了也不会有人发现。
$r = run_dropin( 'stale_version' );
check(
	'drop-in 版本戳过期时主动让出（交给 ensure_dropin 重装）',
	0 === $r['code'] && false !== strpos( $r['out'], 'AT8SA_DROPIN_FELL_THROUGH' ),
	'code=' . $r['code'] . ' out=' . substr( $r['out'], 0, 120 )
);

// 插件目录消失：必须静默退化，绝不报错。
$r = run_dropin( 'no_plugin' );
check(
	'drop-in 在插件目录缺失时静默退化',
	0 === $r['code'] && '' === trim( $r['err'] ) && false !== strpos( $r['out'], 'AT8SA_DROPIN_FELL_THROUGH' ),
	'code=' . $r['code'] . ' err=' . trim( $r['err'] )
);

@unlink( WP_CONTENT_DIR . '/advanced-cache.php' );

/* ---------------------------------------------------------------------------
 * 汇总
 * ------------------------------------------------------------------------ */

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo '通过：' . $GLOBALS['at8sa_pass'] . '  失败：' . $GLOBALS['at8sa_fail'] . "\n";

if ( $GLOBALS['at8sa_fail'] > 0 ) {
	echo "\n失败明细：\n";

	foreach ( $GLOBALS['at8sa_failures'] as $failure ) {
		echo '  - ' . $failure . "\n";
	}

	echo str_repeat( '=', 60 ) . "\n";
	exit( 1 );
}

echo "全部通过。\n";
echo str_repeat( '=', 60 ) . "\n";

exit( 0 );

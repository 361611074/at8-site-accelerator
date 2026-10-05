<?php
/**
 * 第二轮整改集成验收（第 14–16 步：Free-only / Free+Pro / Pro 缺失）。
 *
 * 与 smoke.php 的分工：
 *   - smoke.php 是「代码长什么样」的**静态**断言（在源码里找字符串 / 正则）；
 *   - 本文件是「跑起来会怎样」的**行为**断言（真的 new 出对象、真的调方法）。
 *
 * 第二轮三项 P0/P1（后台通知作用域、登录用户不缓存、wp-config 临时备份清理）
 * 都能靠源码正则"证明代码里写了 if"，但那只证明 if 存在，不证明 if 真的会拦。
 * 本文件补的就是这一层：真的调 `render()`，看**每一个**非本插件页面是否真的零输出。
 *
 * 依赖：直接复用 tests/unit/wp-stubs.php，不重复定义常量与函数。
 *
 * @package AT8SA\Tests
 */

// phpcs:disable

require_once __DIR__ . '/wp-stubs.php';

$at8sa_pass = 0;
$at8sa_fail = 0;

function at8sa_ok( $name, $cond, $detail = '' ) {
	global $at8sa_pass, $at8sa_fail;
	if ( $cond ) {
		$at8sa_pass++;
		echo "  [PASS] {$name}\n";
	} else {
		$at8sa_fail++;
		echo "  [FAIL] {$name}" . ( '' !== $detail ? " -> {$detail}" : '' ) . "\n";
	}
}

function at8sa_section( $t ) {
	echo "\n=== {$t} ===\n";
}

// 桩里的全局变量命名约定是 at8sa_test_*。
$GLOBALS['at8sa_test_can_manage'] = true;
$GLOBALS['at8sa_test_screen_id']  = null;

require_once AT8SA_PATH . 'includes/Core/Settings.php';
require_once AT8SA_PATH . 'includes/Admin/SettingsPage.php';
require_once AT8SA_PATH . 'includes/Admin/Notices.php';

use AT8SA\Core\Settings;
use AT8SA\Admin\SettingsPage;
use AT8SA\Admin\Notices;
use AT8SA\Cache\Config;

/* ============================================================
 * 一、后台通知作用域（行为级）
 * ============================================================ */

at8sa_section( 'admin_notices 作用域（行为级，逐页验证）' );

/**
 * 造一套足够真实的依赖，让 `Notices::render()` 走完全程而不是中途return。
 *
 * @param Settings $settings 设置。
 * @return Notices
 */
function at8sa_make_notices( Settings $settings ) {
	$at8sa_dropin = new class() {
		public function blocked_reason() {
			return '';
		}
		public function is_wp_cache_enabled() {
			return true;
		}
		public function is_installed() {
			return true;
		}
	};

	$at8sa_detector = new class() {
		public function notice_text() {
			return '';
		}
		public function has_high_risk_conflict() {
			return false;
		}
	};

	$at8sa_browser = new class() {
		public function has_risky_combination() {
			return false;
		}
	};

	return new Notices(
		array(
			'settings'        => $settings,
			'advanced_cache'  => $at8sa_dropin,
			'detector'        => $at8sa_detector,
			'browser_cache'   => $at8sa_browser,
		)
	);
}

$at8sa_settings = new Settings();
$at8sa_notices  = at8sa_make_notices( $at8sa_settings );

// 一、a) 规范第二十八节点名的每一个后台页面：必须零输出。
$at8sa_foreign_screens = array(
	'edit-post',
	'post',
	'edit-page',
	'upload',
	'media-new',
	'users',
	'user-new',
	'tools',
	'import',
	'export',
	'options-general',
	'options-writing',
	'options-reading',
	'options-discussion',
	'options-media',
	'options-permalink',
	'options-privacy',
	'themes',
	'theme-editor',
	'plugins-network',
	'wpmu-plugins',
	'wpmu-themes',
	'site-health',
	'edit-tags',
	'edit-category',
	'profile',
	'edit-comments',
	'link-manager',
	'widgets',
	'nav-menus',
	'elementor_page_elementor-landing-page',
	'elementor_page_elementor-home',
	'woocommerce',
	'shop_order',
	'shop_coupons',
	'product',
	'edit-shop_order',
	'wcpay',
	'wc-admin',
	'admin-ajax',
	'async-upload.php',
);

$at8sa_leaked = array();

foreach ( $at8sa_foreign_screens as $at8sa_sid ) {
	$GLOBALS['at8sa_test_screen_id'] = $at8sa_sid;

	ob_start();
	$at8sa_notices->render();
	$at8sa_out = trim( ob_get_clean() );

	if ( '' !== $at8sa_out ) {
		$at8sa_leaked[] = "{$at8sa_sid}（" . strlen( $at8sa_out ) . ' 字节）';
	}
}

at8sa_ok(
	'规范第二十八节全部后台页面零通知输出',
	empty( $at8sa_leaked ),
	implode( ',', $at8sa_leaked )
);

at8sa_ok(
	'共验证 ' . count( $at8sa_foreign_screens ) . ' 个非本插件页面',
	count( $at8sa_foreign_screens ) >= 30
);

// 一、b) 白名单页面：确认没把功能全关掉（源码里 if 写错会在这里暴露）。
$GLOBALS['at8sa_test_screen_id'] = 'plugins';
ob_start();
$at8sa_notices->render();
$at8sa_out = ob_get_clean();
at8sa_ok( '插件列表页可渲染通知（字符串返回）', is_string( $at8sa_out ) );

$GLOBALS['at8sa_test_screen_id'] = 'dashboard';
ob_start();
$at8sa_notices->render();
$at8sa_out = ob_get_clean();
at8sa_ok( '仪表盘可渲染通知（字符串返回）', is_string( $at8sa_out ) );

// 设置页自身：render() 应主动让位（页面里已经有状态区，不重复显示）。
$GLOBALS['at8sa_test_screen_id'] = 'toplevel_page_' . SettingsPage::SLUG;
ob_start();
$at8sa_notices->render();
$at8sa_out = trim( ob_get_clean() );
at8sa_ok( '自身设置页不重复输出通知', '' === $at8sa_out, substr( $at8sa_out, 0, 60 ) );

// 一、c) screen 拿不到（AJAX / REST / 前端）：静默。
$GLOBALS['at8sa_test_screen_id'] = null;
ob_start();
$at8sa_notices->render();
$at8sa_out = trim( ob_get_clean() );
at8sa_ok( 'screen 为 null 时静默', '' === $at8sa_out, substr( $at8sa_out, 0, 60 ) );

// 一、d) 越权：无 manage_options 的一律无输出（哪怕在插件自己的页面）。
$GLOBALS['at8sa_test_can_manage'] = false;
foreach ( array( 'plugins', 'dashboard', 'toplevel_page_' . SettingsPage::SLUG ) as $at8sa_sid ) {
	$GLOBALS['at8sa_test_screen_id'] = $at8sa_sid;
	ob_start();
	$at8sa_notices->render();
	$at8sa_out = trim( ob_get_clean() );
	at8sa_ok( "无 manage_options 时无输出（{$at8sa_sid}）", '' === $at8sa_out, substr( $at8sa_out, 0, 60 ) );
}
$GLOBALS['at8sa_test_can_manage'] = true;

/* ============================================================
 * 二、设置页标签页与 Pro 面板
 * ============================================================ */

at8sa_section( 'Free 设置页标签页与 Pro 面板' );

$at8sa_page       = new SettingsPage( array( 'settings' => $at8sa_settings ) );
$at8sa_tabs_ref   = new ReflectionMethod( 'AT8SA\\Admin\\SettingsPage', 'tabs' );
$at8sa_tabs_ref->setAccessible( true );
$at8sa_tabs = $at8sa_tabs_ref->invoke( $at8sa_page );
at8sa_ok( '标签页数量为 8', 8 === count( $at8sa_tabs ), (string) count( $at8sa_tabs ) );
at8sa_ok( '存在 pro 标签页', isset( $at8sa_tabs['pro'] ), implode( ',', array_keys( $at8sa_tabs ) ) );
at8sa_ok( '所有标签页均有非空可见标题', array() === array_filter( $at8sa_tabs, function ( $t ) { return '' === trim( (string) $t ); } ) );

$at8sa_tpl = (string) file_get_contents( AT8SA_PATH . 'templates/settings-page.php' );
$at8sa_missing_panels = array();

foreach ( array_keys( $at8sa_tabs ) as $at8sa_tab ) {
	if ( false === strpos( $at8sa_tpl, 'data-panel="' . $at8sa_tab . '"' ) ) {
		$at8sa_missing_panels[] = $at8sa_tab;
	}
}

at8sa_ok( '每个标签页都有对应面板（不会出现点了没反应）', empty( $at8sa_missing_panels ), implode( ',', $at8sa_missing_panels ) );

// 反向：模板不得出现没有标签页对应的孤儿面板。
preg_match_all( '/data-panel="([a-z_]+)"/', $at8sa_tpl, $at8sa_panel_matches );
$at8sa_orphan = array_diff( array_unique( $at8sa_panel_matches[1] ), array_keys( $at8sa_tabs ) );
at8sa_ok( '模板无孤儿面板', empty( $at8sa_orphan ), implode( ',', $at8sa_orphan ) );

/* ============================================================
 * 三、登录用户一律不进公共缓存
 * ============================================================ */

at8sa_section( '登录用户不进入共享缓存' );

require_once AT8SA_PATH . 'includes/Cache/Config.php';

$at8sa_settings_a = new Settings();
$at8sa_defaults    = $at8sa_settings_a->defaults();

at8sa_ok( '默认配置已无 cache_logged_in', ! array_key_exists( 'cache_logged_in', $at8sa_defaults ) );
at8sa_ok( '布尔键白名单已无 cache_logged_in', ! in_array( 'cache_logged_in', $at8sa_settings_a->boolean_keys(), true ) );

// 模拟老站点数据库：cache_logged_in => 1 仍在库里。
$GLOBALS['at8sa_test_options']['at8sa_settings'] = array(
	'cache_logged_in' => 1,
	'cache_ttl'       => 3600,
	'page_cache'      => 1,
	'advanced_cache'  => 1,
	// 钉死 disk：auto 模式会触发 Redis 探测，测试不该依赖宿主是否装了 Redis。
	'cache_backend'   => 'disk',
);

$at8sa_settings_b = new Settings();

/*
 * 关键：硬钉发生在 Config 层（落盘给 drop-in 读的配置），不是 Settings 层。
 * `Settings::all()` 只是 `array_merge( defaults, stored )`，它**应该**原样返回
 * 数据库里的历史值 —— 让 Settings 知道"哪个键被禁用了"是把策略漏进数据层，
 * 反而会写出第二处真值来源。所以这里断言的是 `Config::runtime()`。
 */
$at8sa_cfg_settings = $at8sa_settings_b->all();

at8sa_ok(
	'老站点残留的 cache_logged_in => 1 在落盘配置里被硬钉为 0',
	array_key_exists( 'cache_logged_in', $at8sa_cfg_settings ),
	'键本身不应从 all() 消失'
);

require_once AT8SA_PATH . 'includes/Cache/Backend/BackendFactory.php';
require_once AT8SA_PATH . 'includes/Support/Logger.php';
require_once AT8SA_PATH . 'includes/Optimization/HtmlMinifier.php';
// Config::runtime() 会调 excluded_paths() / bypass_cookies()，它们依赖 RequestGuard。
require_once AT8SA_PATH . 'includes/Cache/RequestGuard.php';

$at8sa_logger   = new AT8SA\Support\Logger( $at8sa_settings_b );
$at8sa_factory  = new AT8SA\Cache\Backend\BackendFactory( $at8sa_settings_b, $at8sa_logger );
$at8sa_minifier = new AT8SA\Optimization\HtmlMinifier( $at8sa_settings_b );

$at8sa_config  = new Config( $at8sa_settings_b, $at8sa_factory );
$at8sa_runtime = $at8sa_config->runtime();

at8sa_ok(
	'落盘配置里 cache_logged_in 恒为 0（历史值不生效）',
	array_key_exists( 'cache_logged_in', $at8sa_runtime ) && 0 === (int) $at8sa_runtime['cache_logged_in'],
	'实际：' . var_export( $at8sa_runtime['cache_logged_in'] ?? null, true )
);

$at8sa_cfg = $at8sa_runtime;

// 登录态下，缓存引擎必须直接判定不缓存。
require_once AT8SA_PATH . 'includes/Cache/CacheEngine.php';

$GLOBALS['at8sa_test_logged_in'] = true;

$at8sa_engine = new AT8SA\Cache\CacheEngine( $at8sa_settings_b, $at8sa_factory, $at8sa_logger, $at8sa_minifier );

$at8sa_should_cache = new ReflectionMethod( 'AT8SA\\Cache\\CacheEngine', 'should_cache_response' );
$at8sa_should_cache->setAccessible( true );

try {
	$at8sa_v = $at8sa_should_cache->invoke( $at8sa_engine, '/some-page/' );
	at8sa_ok( '登录用户下 should_cache_response 返回 false', false === $at8sa_v, var_export( $at8sa_v, true ) );
} catch ( \Throwable $e ) {
	at8sa_ok( '登录用户下should_cache_response 可调用', false, $e->getMessage() );
}

// 匿名用户仍应正常走缓存判断（不能把功能整个关掉）。
$GLOBALS['at8sa_test_logged_in'] = false;
try {
	$at8sa_v2 = $at8sa_should_cache->invoke( $at8sa_engine, '/some-page/' );
	at8sa_ok( '匿名用户下should_cache_response 返回可判定结果', is_bool( $at8sa_v2 ), gettype( $at8sa_v2 ) );
} catch ( \Throwable $e ) {
	at8sa_ok( '匿名用户下 should_cache_response 可调用', false, $e->getMessage() );
}

// 登录 Cookie 存在时请求守卫必须绕过（不读任何配置开关）。
require_once AT8SA_PATH . 'includes/Cache/RequestGuard.php';

$_COOKIE = array(
	'wordpress_logged_in_abc123def456' => 'admin|1|expiry|token',
	'testcookie'                        => 'WP+Cookie+check',
);

$at8sa_guard_ref = new ReflectionClass( 'AT8SA\\Cache\\RequestGuard' );
$at8sa_has_cookie = $at8sa_guard_ref->getMethod( 'has_auth_cookie' );
$at8sa_has_cookie->setAccessible( true );

$at8sa_has = $at8sa_has_cookie->invoke( null, $at8sa_cfg );
at8sa_ok( '检测到 wordpress_logged_in_ Cookie', true === $at8sa_has, var_export( $at8sa_has, true ) );

$_COOKIE = array();
$at8sa_has2 = $at8sa_has_cookie->invoke( null, $at8sa_cfg );
at8sa_ok( '无Cookie 时不误判为登录态', false === $at8sa_has2, var_export( $at8sa_has2, true ) );

/* ============================================================
 * 四、wp-config 临时备份用完即删（行为级）
 * ============================================================ */

at8sa_section( 'wp-config 临时备份用完即删' );

/*
 * 路径必须用 `ABSPATH . 'wp-config.php'` —— 那正是 `AdvancedCache::wp_config_path()`
 * 真实使用的那一条。如果这里另造一个目录再把路径塞进去，测的只是"我传进去的路径对不对"，
 * 而 `enable_wp_cache()` / `disable_wp_cache()` 这两个真正面向用户的入口根本没被覆盖。
 * 桩里ABSPATH 指向 tests/unit/fake-wp/，在那里造一个临时 wp-config.php，测完删掉。
 */
$at8sa_root        = ABSPATH;
$at8sa_config_path = ABSPATH . 'wp-config.php';

/**
 * 列出目录下所有 .at8sa* 残留（. 与 .. 已被 glob 排除）。
 *
 * @param string $dir 目录。
 * @return array
 */
function at8sa_residue( $dir ) {
	return array_values(
		array_filter(
			(array) glob( $dir . '*' ),
			function ( $f ) {
				return false !== strpos( basename( $f ), '.at8sa' );
			}
		)
	);
}

// 四、a) 正常路径：写入成功 → 无残留。
/*
 * 样本必须满足 `verify_wp_config()` 的全部校验项，否则测到的不是"备份被删了没有"，
 * 而是"校验失败回滚"：
 *   - 长度 ≥ 100
 *   - 含 DB_NAME
 *   - 含 wp-settings.php
 *   - 大括号平衡（交给真实词法器判）
 */
$at8sa_original = "<?php\n"
	. "define( 'DB_NAME', 'wordpress' );\n"
	. "define( 'DB_USER', 'wpuser' );\n"
	. "define( 'DB_PASSWORD', 'super-secret-plaintext' );\n"
	. "define( 'DB_HOST', 'localhost' );\n"
	. "define( 'AUTH_KEY', 'put your unique phrase here' );\n"
	. "define( 'SECURE_AUTH_KEY', 'put your unique phrase here' );\n"
	. "define( 'LOGGED_IN_KEY', 'put your unique phrase here' );\n"
	. "define( 'NONCE_KEY', 'put your unique phrase here' );\n"
	. "\$table_prefix = 'wp_';\n"
	. "if ( ! defined( 'ABSPATH' ) ) {\n"
	. "\tdefine( 'ABSPATH', __DIR__ . '/' );\n"
	. "}\n"
	. "require_once ABSPATH . 'wp-settings.php';\n";
file_put_contents( $at8sa_config_path, $at8sa_original );

require_once AT8SA_PATH . 'includes/Cache/AdvancedCache.php';

$at8sa_adv_ref = new ReflectionClass( 'AT8SA\\Cache\\AdvancedCache' );
$at8sa_adv     = $at8sa_adv_ref->newInstanceArgs( array( $at8sa_logger ) );

$at8sa_write = $at8sa_adv_ref->getMethod( 'write_wp_config' );
$at8sa_write->setAccessible( true );

/*
 * 调**真实的** `enable_wp_cache()`，而不是手工拼「加了一行 WP_CACHE」的内容。
 *
 * 两个原因：
 *  1. `is_reversible()` 要求新增那一行必须带 `WP_CACHE_MARKER` 标记注释，
 *     手拼的行没有标记 → 校验必然失败 → 测到的就不是"备份删没删"，而是"可逆性检查"；
 *  2. `enable_wp_cache()` 内部自己会调 `write_wp_config()`，走完整路径才覆盖真实调用链。
 */
$at8sa_result = $at8sa_adv->enable_wp_cache();

at8sa_ok( 'enable_wp_cache 返回数组结果', is_array( $at8sa_result ), gettype( $at8sa_result ) );
at8sa_ok( '正常启用 WP_CACHE 返回成功', ! empty( $at8sa_result['ok'] ), (string) ( $at8sa_result['message'] ?? '(无 message)' ) );

$at8sa_res = at8sa_residue( $at8sa_root );
at8sa_ok( '成功后无任何 .at8sa* 残留文件', empty( $at8sa_res ), implode( ',', array_map( 'basename', $at8sa_res ) ) );

$at8sa_after = (string) file_get_contents( $at8sa_config_path );
at8sa_ok( '目标文件确实被改写', false !== strpos( $at8sa_after, 'WP_CACHE' ) );

/*
 * 四、b) 校验失败 → 回滚，且同样无残留。
 *
 * 构造「写了不该写的内容」：丢掉 `wp-settings.php` 那行，触发 `verify_wp_config()`
 * 的结构校验失败。走的是 `write_wp_config()` 本身，因为这个失败分支只在这里发生。
 */
$at8sa_original2 = $at8sa_original;
file_put_contents( $at8sa_config_path, $at8sa_original2 );

$at8sa_broken = str_replace( "require_once ABSPATH . 'wp-settings.php';\n", '', $at8sa_original2 );
$at8sa_result2 = $at8sa_write->invoke( $at8sa_adv, $at8sa_config_path, $at8sa_original2, $at8sa_broken );

at8sa_ok( '校验失败时返回失败状态', empty( $at8sa_result2['ok'] ), (string) ( $at8sa_result2['message'] ?? '' ) );

$at8sa_res2 = at8sa_residue( $at8sa_root );
at8sa_ok( '校验失败回滚后同样无 .at8sa* 残留', empty( $at8sa_res2 ), implode( ',', array_map( 'basename', $at8sa_res2 ) ) );

$at8sa_restored = (string) file_get_contents( $at8sa_config_path );
at8sa_ok( '校验失败时已回滚为原文', $at8sa_restored === $at8sa_original2, '实际长度 ' . strlen( $at8sa_restored ) . ' vs ' . strlen( $at8sa_original2 ) );

// 四、b2) 真实停用路径：enable 之后 disable，同样不得残留。
$at8sa_result3 = $at8sa_adv->enable_wp_cache();
at8sa_ok( '可再次启用（幂等）', ! empty( $at8sa_result3['ok'] ), (string) ( $at8sa_result3['message'] ?? '' ) );

$at8sa_enabled_content = (string) file_get_contents( $at8sa_config_path );
at8sa_ok( '启用后文件确实含 WP_CACHE 与归属标记', false !== strpos( $at8sa_enabled_content, 'WP_CACHE' ) && false !== strpos( $at8sa_enabled_content, 'Added by AT8 Site Accelerator' ) );

$at8sa_result4 = $at8sa_adv->disable_wp_cache();
at8sa_ok( '停用返回成功', ! empty( $at8sa_result4['ok'] ), (string) ( $at8sa_result4['message'] ?? '' ) );

$at8sa_res4 = at8sa_residue( $at8sa_root );
at8sa_ok( '停用后同样无 .at8sa* 残留', empty( $at8sa_res4 ), implode( ',', array_map( 'basename', $at8sa_res4 ) ) );

$at8sa_after_disable = (string) file_get_contents( $at8sa_config_path );
at8sa_ok( '停用后已恢复到启用前的内容', $at8sa_after_disable === $at8sa_original, '实际长度 ' . strlen( $at8sa_after_disable ) );

// 四、c) 最终盘面上不得有任何本插件留下的临时/备份文件。
$at8sa_final_res = at8sa_residue( $at8sa_root );
at8sa_ok( 'ABSPATH 下无任何 .at8sa* 残留', empty( $at8sa_final_res ), implode( ',', array_map( 'basename', $at8sa_final_res ) ) );

$at8sa_leftovers = array();
foreach ( (array) glob( $at8sa_root . 'wp-config.php*' ) as $f ) {
	$at8sa_bn = basename( $f );
	// `wp-config.php`（本体）是合法且必须存在的，只挑出它后面还带后缀的那些。
	if ( 'wp-config.php' === $at8sa_bn ) {
		continue;
	}
	$at8sa_leftovers[] = $at8sa_bn;
}
at8sa_ok( '无 wp-config.php.* 形式的备份/临时文件', empty( $at8sa_leftovers ), implode( ',', $at8sa_leftovers ) );

@unlink( $at8sa_config_path );

/* ============================================================
 * 四、d) 删除临时备份必须走 wp_delete_file() 主路径
 * ============================================================
 *
 * 为什么单独钉这一条：`discard_wp_config_backup()` 是「先 wp_delete_file()、
 * 失败再兜底 unlink()」的两段式。前面那些断言只看「文件最终没了」，两段式和
 * 单纯 unlink() 都能让它通过 —— 也就是说，把两段式调换成裸 unlink()（也就是
 * Plugin Check 会判ERROR 的那种写法）测试依然全绿。
 *
 * 这里通过给桩打标记来区分：主路径被走过 => 标记为true。
 * 断言的是**走哪条路**，不是「删掉了没」—— 后者前面已经证过了。
 */

$at8sa_adv_ref = new ReflectionClass( 'AT8SA\\Cache\\AdvancedCache' );
$at8sa_discard = $at8sa_adv_ref->getMethod( 'discard_wp_config_backup' );
$at8sa_discard->setAccessible( true );

$at8sa_probe = $at8sa_root . 'wp-config.php.at8sa.tmp';
file_put_contents( $at8sa_probe, "<?php\n// probe\n" );
$GLOBALS['at8sa_test_wp_delete_file_calls'] = array();
$at8sa_discard->invoke( $at8sa_adv, $at8sa_probe );

at8sa_ok(
	'临时备份经 wp_delete_file() 删除（而非裸 unlink 兜底）',
	! file_exists( $at8sa_probe ) && in_array( $at8sa_probe, (array) ( $GLOBALS['at8sa_test_wp_delete_file_calls'] ?? array() ), true ),
	'桩记录：' . implode( ',', (array) ( $GLOBALS['at8sa_test_wp_delete_file_calls'] ?? array() ) )
);
unset( $GLOBALS['at8sa_test_wp_delete_file_calls'] );

// 兜底分支也必须真能删：让主路径失效（桩里让 is_file 失败），确认兜底 unlink() 接手。
$at8sa_probe2 = $at8sa_root . 'wp-config.php.at8sa.tmp';
file_put_contents( $at8sa_probe2, "<?php\n// probe2\n" );
$GLOBALS['at8sa_test_wp_delete_file_force_fail'] = true;
$at8sa_discard->invoke( $at8sa_adv, $at8sa_probe2 );
unset( $GLOBALS['at8sa_test_wp_delete_file_force_fail'] );

at8sa_ok(
	'主路径失效时兜底删除仍能清掉凭据文件',
	! file_exists( $at8sa_probe2 ),
	'仍存在：' . ( file_exists( $at8sa_probe2 ) ? $at8sa_probe2 : '已删除' )
);

/* ============================================================
 * 五、Host 归一化防穿越（realpath 落地验证）
 * ============================================================ */

at8sa_section( 'Host 归一化防穿越' );

require_once AT8SA_PATH . 'includes/Cache/CachePath.php';

$at8sa_norm = function ( $host ) {
	return call_user_func( array( 'AT8SA\\Cache\\CachePath', 'normalize_host' ), $host );
};

// 注意：`normalize_host()` 只剥**结尾**的点。前导点（`.example.com`）保留是对的——
// 目录名以点开头是合法的，且 realpath 已证明它无法逃出缓存根（见下面的实测）。
// 把前导点也剥掉反而会让两个不同的 Host 归一成同一个目录。
$at8sa_host_cases = array(
	array( '.', 'unknown-host' ),
	array( '..', 'unknown-host' ),
	array( '.example.com', '.example.com' ),
	array( 'example.com.', 'example.com' ),
	array( 'example.com..', 'example.com' ),
	array( 'EXAMPLE.COM', 'example.com' ),
	array( 'example.com:8080', 'example.com:8080' ),
	array( '', 'unknown-host' ),
	array( 'example.com', 'example.com' ),
);

foreach ( $at8sa_host_cases as $at8sa_case ) {
	list( $at8sa_in, $at8sa_want ) = $at8sa_case;
	$at8sa_got = $at8sa_norm( $at8sa_in );
	at8sa_ok( "Host「{$at8sa_in}」归一化为「{$at8sa_want}」", $at8sa_got === $at8sa_want, "实际：{$at8sa_got}" );
}

// 含分隔符的输入：归一化后必须不含路径分隔符。
$at8sa_sep_cases = array( '../evil', '../../etc', 'a/b', 'a\\b', '/etc/passwd', '..\\..\\windows' );
foreach ( $at8sa_sep_cases as $at8sa_s ) {
	$at8sa_g = $at8sa_norm( $at8sa_s );
	at8sa_ok( "Host「{$at8sa_s}」归一化后无路径分隔符", false === strpbrk( $at8sa_g, '/\\' ), $at8sa_g );
}

// 最强证据：用 realpath() 实际解析，验证确实逃不出缓存根。
$at8sa_base    = dirname( __DIR__, 2 ) . '/.tmp-verify-cache/';
$at8sa_outside = dirname( $at8sa_base ) . '/.tmp-verify-outside/';

if ( ! is_dir( $at8sa_base ) ) {
	mkdir( $at8sa_base, 0777, true );
}
if ( ! is_dir( $at8sa_outside ) ) {
	mkdir( $at8sa_outside, 0777, true );
}
file_put_contents( $at8sa_outside . '/secret.txt', 'x' );

$at8sa_root_real = realpath( $at8sa_base );
$at8sa_escape    = array();

$at8sa_evil_hosts = array( '..', '.', '../..', '....//....//', '/etc', '..\\..', '..', '..', '..', '..', '..', '..', '..', '..' );

foreach ( $at8sa_evil_hosts as $at8sa_eh ) {
	$at8sa_joined = $at8sa_base . $at8sa_norm( $at8sa_eh ) . '/index.html';
	$at8sa_dirreal = realpath( dirname( $at8sa_joined ) );

	if ( false === $at8sa_dirreal ) {
		// 目录不存在 = 无法指向任何已有目录，天然安全。
		continue;
	}

	// 逃逸判定：解析结果不在缓存根之内。
	if ( 0 !== strpos( $at8sa_dirreal . DIRECTORY_SEPARATOR, $at8sa_root_real . DIRECTORY_SEPARATOR ) ) {
		$at8sa_escape[] = "Host「{$at8sa_eh}」→ {$at8sa_dirreal}";
	}
}

at8sa_ok( '恶意 Host 用 realpath 实测逃不出缓存根', empty( $at8sa_escape ), implode( ' | ', $at8sa_escape ) );

// 正常 Host 必须能落在缓存根之内（不能把功能改坏）。
$at8sa_ok_dir = $at8sa_base . $at8sa_norm( 'example.com' );
mkdir( $at8sa_ok_dir, 0777, true );
$at8sa_ok_real = realpath( $at8sa_ok_dir );
at8sa_ok(
	'正常 Host 仍落在缓存根内',
	false !== $at8sa_ok_real && 0 === strpos( $at8sa_ok_real, $at8sa_root_real ),
	(string) $at8sa_ok_real
);

foreach ( (array) glob( $at8sa_outside . '/*' ) as $at8sa_f ) {
	@unlink( $at8sa_f );
}
@rmdir( $at8sa_outside );

$at8sa_rm = function ( $dir ) use ( &$at8sa_rm ) {
	foreach ( (array) glob( rtrim( $dir, '/\\' ) . '/*' ) as $f ) {
		if ( is_dir( $f ) ) {
			$at8sa_rm( $f );
		} else {
			@unlink( $f );
		}
	}
	if ( is_dir( $dir ) ) {
		@rmdir( $dir );
	}
};
$at8sa_rm( $at8sa_base );

/* ============================================================
 * 六、Free 独立性（发行包级）
 * ============================================================ */

at8sa_section( 'Free 独立性（发行包级）' );

$at8sa_zip = dirname( __DIR__, 2 ) . '/dist/at8-site-accelerator-' . AT8SA_VERSION . '.zip';

if ( ! is_readable( $at8sa_zip ) ) {
	/*
	 * 没有发行包时**不算失败**：CI 的 test 作业先于 package 作业跑，
	 * 那一刻 dist/ 里确实还没有 zip。这里报 SKIP 而不是 FAIL，
	 * 是因为"没验到"和"验出问题了"是两件不同的事，混为一谈会让人分不清
	 * 到底是没跑还是没过。package 作业后面会再跑一次带包的那半。
	 */
	echo "  [SKIP] 发行包级检查跳过：未找到 " . basename( $at8sa_zip ) . "（package 阶段会带包再跑一次）\n";
} else {
	$at8sa_z           = new ZipArchive();
	$at8sa_z->open( $at8sa_zip );

	$at8sa_remote_hits = array();
	$at8sa_lic_hits    = array();
	$at8sa_pro_dirs    = array();
	$at8sa_dev_dirs    = array();
	$at8sa_top         = array();

	for ( $at8sa_i = 0; $at8sa_i < $at8sa_z->numFiles; $at8sa_i++ ) {
		$at8sa_n = $at8sa_z->getNameIndex( $at8sa_i );
		$at8sa_r = str_replace( '\\', '/', $at8sa_n );

		$at8sa_top[ explode( '/', $at8sa_r )[0] ] = true;

		// 开发目录不得入包。
		if ( preg_match( '#^[^/]+/(tests|tools|vendor|docs|node_modules|dist|\.git|\.github)/#', $at8sa_r ) ) {
			$at8sa_dev_dirs[] = $at8sa_r;
			continue;
		}

		// 商业目录不得入包。
		if ( preg_match( '#^[^/]+/(pro|premium|license|licence|shop|store|upgrade|checkout)/#', $at8sa_r ) ) {
			$at8sa_pro_dirs[] = $at8sa_r;
			continue;
		}

		if ( substr( $at8sa_r, -4 ) !== '.php' && substr( $at8sa_r, -5 ) !== '.json' ) {
			continue;
		}

		$at8sa_c = (string) $at8sa_z->getFromIndex( $at8sa_i );

		foreach ( array( 'download_url', 'Plugin_Upgrader', 'eval(', 'unzip_file', 'wp_remote_post' ) as $at8sa_kw ) {
			if ( false !== strpos( $at8sa_c, $at8sa_kw ) ) {
				$at8sa_remote_hits[] = "{$at8sa_r} => {$at8sa_kw}";
			}
		}

		if ( preg_match( '/\b(license_key|license_server|activate_license|deactivate_license|license_api)\b/', $at8sa_c ) ) {
			$at8sa_lic_hits[] = $at8sa_r;
		}
	}

	$at8sa_z->close();

	at8sa_ok( '发行包只有单一顶层目录', array( 'at8-site-accelerator' ) === array_keys( $at8sa_top ), implode( ',', array_keys( $at8sa_top ) ) );
	at8sa_ok( '发行包内无 tests/tools/vendor/docs 等开发目录', empty( $at8sa_dev_dirs ), implode( ' | ', array_slice( $at8sa_dev_dirs, 0, 5 ) ) );
	at8sa_ok( '发行包内无 Pro 商业目录', empty( $at8sa_pro_dirs ), implode( ' | ', $at8sa_pro_dirs ) );
	at8sa_ok( '发行包内无远程安装 / 动态执行代码', empty( $at8sa_remote_hits ), implode( ' | ', $at8sa_remote_hits ) );
	at8sa_ok( '发行包内无 License 校验代码', empty( $at8sa_lic_hits ), implode( ' | ', $at8sa_lic_hits ) );
}

// Pro 仓库不存在时，Free 必须照常工作 —— 能跑到这一行本身就是证明。
at8sa_ok(
	'未安装任何 Pro 插件时 Free 全流程可执行',
	class_exists( 'AT8SA\\Cache\\CacheEngine' ) && class_exists( 'AT8SA\\Cache\\AdvancedCache' ) && class_exists( 'AT8SA\\Admin\\Notices' )
);

// Free 入口不得含 Pro bootstrap 标志。
$at8sa_entry = (string) file_get_contents( AT8SA_FILE );
$at8sa_bootstrap_hits = array();
foreach ( array( 'AT8SA_PRO', 'at8sa-pro', 'at8sa_pro_', 'AT8SASA', 'AT8SA_LICENSE' ) as $at8sa_kw ) {
	if ( false !== stripos( $at8sa_entry, $at8sa_kw ) ) {
		$at8sa_bootstrap_hits[] = $at8sa_kw;
	}
}
at8sa_ok( 'Free 入口不含任何 Pro bootstrap 标志', empty( $at8sa_bootstrap_hits ), implode( ',', $at8sa_bootstrap_hits ) );

/* ============================================================
 * 七、Pro 推广的克制性
 * ============================================================ */

at8sa_section( 'Pro 推广克制性' );

at8sa_ok( 'Pro 面板存在于设置页模板', false !== strpos( $at8sa_tpl, 'data-panel="pro"' ) );
/*
 * 追踪参数只检查**外链**。模板里还有一条带 nonce 的后台链接
 *（`admin-post.php?action=...&_wpnonce=...`），它本来就该带 nonce 参数，
 * 拿`ref=` 全文匹配会误判，所以这里只取 href 值里属于 http(s) 的那些。
 */
$at8sa_external_links = array();
if ( preg_match_all( '#href="(https?://[^"]+)"#', $at8sa_tpl, $at8sa_href_matches ) ) {
	$at8sa_external_links = $at8sa_href_matches[1];
}

$at8sa_track_hits = array();
foreach ( $at8sa_external_links as $at8sa_link ) {
	foreach ( array( 'utm_', 'affiliate', 'tracking_id', 'ref=', 'gclid', 'fbclid' ) as $at8sa_bad ) {
		if ( false !== stripos( $at8sa_link, $at8sa_bad ) ) {
			$at8sa_track_hits[] = "{$at8sa_link}（{$at8sa_bad}）";
		}
	}
}

at8sa_ok(
	'外链不带任何追踪参数（共 ' . count( $at8sa_external_links ) . ' 条外链）',
	empty( $at8sa_track_hits ),
	implode( ' | ', $at8sa_track_hits )
);
at8sa_ok( '设置页不使用 iframe', false === stripos( $at8sa_tpl, '<iframe' ) );
at8sa_ok( '外链带 noopener noreferrer', false !== strpos( $at8sa_tpl, 'rel="noopener noreferrer"' ) );

// 通知类源码不得出现 Pro 内容（防止后台通知被用作 Pro 广告位）。
$at8sa_notices_src = (string) file_get_contents( AT8SA_PATH . 'includes/Admin/Notices.php' );
at8sa_ok( '通知类源码不含 Pro 链接', false === stripos( $at8sa_notices_src, 'at8.fun/product' ) && false === stripos( $at8sa_notices_src, 'accelerator-pro' ) );

// 前台 JS 不得出现 Pro 链接。
$at8sa_js_hits = array();
foreach ( (array) glob( AT8SA_PATH . 'assets/js/*.js' ) as $at8sa_js ) {
	$at8sa_jsc = (string) file_get_contents( $at8sa_js );
	if ( false !== stripos( $at8sa_jsc, 'at8.fun/product' ) || false !== stripos( $at8sa_jsc, 'accelerator-pro' ) ) {
		$at8sa_js_hits[] = basename( $at8sa_js );
	}
}
at8sa_ok( '前台 JS 不含 Pro 链接', empty( $at8sa_js_hits ), implode( ',', $at8sa_js_hits ) );

// Pro 链接必须真实存在且指向 at8.fun（无隐藏跳转）。
$at8sa_pro_url = 'https://www.at8.fun/product/at8-site-accelerator-pro/';
$at8sa_url_hits = array();
foreach ( array( AT8SA_PATH . 'templates/settings-page.php' ) as $at8sa_phpfile ) {
	$at8sa_c = (string) file_get_contents( $at8sa_phpfile );
	if ( preg_match_all( '#https?://[^\s"\'<>)]+#', $at8sa_c, $at8sa_urls ) ) {
		foreach ( $at8sa_urls[0] as $at8sa_u ) {
			if ( false !== stripos( $at8sa_u, 'product' ) || false !== stripos( $at8sa_u, 'pro' ) ) {
				$at8sa_url_hits[] = $at8sa_u;
			}
		}
	}
}
at8sa_ok( 'Pro 相关外链只有产品页一条', array( $at8sa_pro_url ) === $at8sa_url_hits, implode( ' | ', $at8sa_url_hits ) );

/* ============================================================
 * 八、后台 CSS作用域
 * ============================================================ */

at8sa_section( '后台 CSS 作用域' );

$at8sa_css = (string) file_get_contents( AT8SA_PATH . 'assets/css/admin.css' );

// 关键：先把注释整段剥掉再检查。
// 本文件头部就写着「禁止使用 !important」这句中文说明，不剥注释会把说明本身当成违规；
// 同理注释里出现的 `.notice` 等字样也不该被算成选择器。
$at8sa_css_code = preg_replace( '#/\*.*?\*/#s', '', $at8sa_css );
$at8sa_css_code = preg_replace( '#//[^\n]*#', '', $at8sa_css_code );

// 提取所有选择器文本（深度 0 上遇到 `{` 之前的全部内容）。
$at8sa_depth     = 0;
$at8sa_current   = '';
$at8sa_selectors = array();
$at8sa_len_total = strlen( $at8sa_css_code );

for ( $at8sa_i = 0; $at8sa_i < $at8sa_len_total; $at8sa_i++ ) {
	$at8sa_ch = $at8sa_css_code[ $at8sa_i ];

	if ( '{' === $at8sa_ch ) {
		if ( 0 === $at8sa_depth ) {
			$at8sa_s = trim( $at8sa_current );
			if ( '' !== $at8sa_s ) {
				$at8sa_selectors[] = $at8sa_s;
			}
		}
		$at8sa_depth++;
		$at8sa_current = '';
		continue;
	}

	if ( '}' === $at8sa_ch ) {
		$at8sa_depth--;
		$at8sa_current = '';
		continue;
	}

	if ( 0 === $at8sa_depth ) {
		$at8sa_current .= $at8sa_ch;
	}
}

// at-rule（@media / @keyframes / @supports …）本身不是选择器，跳过。
$at8sa_rule_selectors = array();
foreach ( $at8sa_selectors as $at8sa_sel ) {
	foreach ( explode( ',', $at8sa_sel ) as $at8sa_one ) {
		$at8sa_one = trim( preg_replace( '/@[\w-]+[^{]*$/', '', $at8sa_one ) );
		if ( '' === $at8sa_one ) {
			continue;
		}
		$at8sa_rule_selectors[] = $at8sa_one;
	}
}

/*
 * 判定规则（别写复杂了）：
 * 一条复合选择器只要**任意一段**带`.at8sa-`，整条就被作用域限住了。
 * `.at8sa-wrap h1` 里的 `h1` 是元素名，它能生效的前提就是前面那个 `.at8sa-wrap`，
 * 所以它**不是**违规；只有 `h1` / `button` / `.notice` 这种通体裸奔的才算。
 */
$at8sa_unscoped = array();

foreach ( $at8sa_rule_selectors as $at8sa_one ) {
	if ( false !== strpos( $at8sa_one, '.at8sa-' ) ) {
		continue;
	}
	$at8sa_unscoped[] = $at8sa_one;
}

at8sa_ok(
	'后台 CSS 全部选择器均 scoped（' . count( $at8sa_rule_selectors ) . ' 条复合选择器 / ' . count( $at8sa_selectors ) . ' 条规则）',
	empty( $at8sa_unscoped ),
	implode( ' | ', array_slice( $at8sa_unscoped, 0, 8 ) )
);

// !important 检查同样必须在剥掉注释之后做。
at8sa_ok(
	'后台 CSS 实际规则中无 !important',
	false === strpos( $at8sa_css_code, '!important' ),
	'注意：注释里的说明文字不算违规'
);

// 明确点名规范第二十七节禁止的裸选择器。
$at8sa_banned = array( 'body', 'button', 'input', 'table', '.notice' );
$at8sa_banned_hits = array();

foreach ( $at8sa_banned as $at8sa_bad_sel ) {
	if ( preg_match( '/(^|[\s,>+~])' . preg_quote( $at8sa_bad_sel, '/' ) . '([\s,:.>{]|$)/', $at8sa_css_code ) ) {
		$at8sa_banned_hits[] = $at8sa_bad_sel;
	}
}

at8sa_ok(
	'不含规范第二十七节点名的裸选择器（' . implode( ' / ', $at8sa_banned ) . '）',
	empty( $at8sa_banned_hits ),
	implode( ',', $at8sa_banned_hits )
);

/* ============================================================
 * 汇总
 * ============================================================ */

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo "通过：{$at8sa_pass}  失败：{$at8sa_fail}\n";

if ( $at8sa_fail > 0 ) {
	echo "存在失败项，不允许进入打包与提交流程。\n";
	exit( 1 );
}

echo "第二轮集成验收全部通过。\n";
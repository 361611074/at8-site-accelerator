<?php
/**
 * WordPress 函数桩（仅用于本仓库的冒烟测试）。
 *
 * 目的：在没有完整 WordPress 运行时的环境下，把插件的类真正实例化并调用关键方法，
 * 从而抓出「方法不存在」「参数个数不匹配」「命名空间写错」这类静态检查抓不到的
 * 致命错误（PHP 里这类错误是 E_ERROR，会让整站白屏，必须在发布前拦住）。
 *
 * 这不是单元测试框架的替代品——它是发布前的最后一道粗筛。
 *
 * @package AT8SA\Tests
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/fake-wp/wp-content' );
define( 'WP_CONTENT_URL', 'http://example.test/wp-content' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'COOKIEHASH', 'abc123def456' );
define( 'AT8SA_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'AT8SA_URL', 'http://example.test/wp-content/plugins/at8-site-accelerator/' );
define( 'AT8SA_FILE', AT8SA_PATH . 'at8-site-accelerator.php' );
define( 'AT8SA_BASENAME', 'at8-site-accelerator/at8-site-accelerator.php' );

/*
 * 插件版本 —— **从主插件文件真实解析，不要在这里写死**。
 *
 * 为什么必须动态取：drop-in 的版本自检拿"模板里的版本戳"与"主文件的版本戳"做比对，
 * 不一致就主动让出。桩里写死一个版本，只要源码升一次级就会漂移，后果是所有
 * "缓存命中"场景静默退化成 fall-through —— 测试报 3 条假失败，同时把命中路径的
 * 真实行为一起掩盖掉。实测踩过：桩写 3.0.1、主文件 3.0.2。
 *
 * 解析方式与 templates/advanced-cache.php 的自检**故意保持一致**（同一条正则、
 * 同样只读前 8KB），这样"桩认为的版本"和"drop-in 认为的版本"不可能各说各话。
 */
$at8sa_stub_version = '0.0.0';

if ( is_readable( AT8SA_FILE ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	$at8sa_stub_head = (string) @file_get_contents( AT8SA_FILE, false, null, 0, 8192 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( preg_match( '/AT8SA_VERSION[\'"\s,]+([0-9A-Za-z.\-]+)/', $at8sa_stub_head, $at8sa_stub_match ) ) {
		$at8sa_stub_version = $at8sa_stub_match[1];
	}

	unset( $at8sa_stub_head, $at8sa_stub_match );
}

define( 'AT8SA_VERSION', $at8sa_stub_version );

unset( $at8sa_stub_version );

define( 'AT8SA_CACHE_ROOT', WP_CONTENT_DIR . '/cache/at8-site-accelerator' );
define( 'AT8SA_CACHE_ROOT_URL', WP_CONTENT_URL . '/cache/at8-site-accelerator' );

/*
 * 测试专用的 Redis 逻辑库。
 *
 * 插件默认用 2 号库。测试若不覆盖这个常量，在**开发者本机**（Redis 上可能正跑着
 * 真实站点）执行冒烟/单元测试就会把线上缓存清掉——一次 `purge_all()` 就够。
 * 15 号库是测试保留库，并在每个用例前后清扫，绝不做 FLUSHDB。
 */
define( 'AT8SA_REDIS_DB', 15 );

if ( ! is_dir( WP_CONTENT_DIR . '/cache' ) ) {
	mkdir( WP_CONTENT_DIR . '/cache', 0777, true );
}
if ( ! is_dir( WP_CONTENT_DIR . '/uploads' ) ) {
	mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
}

/* ---------------------------------------------------------------------------
 * 选项 / 瞬态存储
 * ------------------------------------------------------------------------ */

$GLOBALS['at8sa_test_options']    = array();
$GLOBALS['at8sa_test_enqueued_scripts'] = array();
$GLOBALS['at8sa_test_transients'] = array();
$GLOBALS['at8sa_test_actions']    = array();
$GLOBALS['at8sa_test_filters']    = array();
$GLOBALS['at8sa_test_scheduled']  = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['at8sa_test_options'] ) ? $GLOBALS['at8sa_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );

	$exists = array_key_exists( $name, $GLOBALS['at8sa_test_options'] );
	$old    = $exists ? $GLOBALS['at8sa_test_options'][ $name ] : false;

	// 与 WP 一致：值没变就既不写库、也不触发钩子。
	// 这个细节必须复刻——依赖 update_option 副作用的代码（如 SettingsSync）
	// 若在桩里"永远触发"，就会得到比真实环境更乐观的结论。
	if ( $exists && $old === $value ) {
		return false;
	}

	$GLOBALS['at8sa_test_options'][ $name ] = $value;

	do_action( "update_option_{$name}", $old, $value );
	do_action( 'updated_option', $name, $old, $value );

	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	unset( $deprecated, $autoload );
	if ( ! array_key_exists( $name, $GLOBALS['at8sa_test_options'] ) ) {
		$GLOBALS['at8sa_test_options'][ $name ] = $value;
	}
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['at8sa_test_options'][ $name ] );
	return true;
}

function get_site_option( $name, $default = false ) {
	return get_option( $name, $default );
}

function get_transient( $name ) {
	if ( ! isset( $GLOBALS['at8sa_test_transients'][ $name ] ) ) {
		return false;
	}
	$entry = $GLOBALS['at8sa_test_transients'][ $name ];
	return ( $entry['expires'] > 0 && $entry['expires'] < time() ) ? false : $entry['value'];
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['at8sa_test_transients'][ $name ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + $expiration : 0,
	);
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['at8sa_test_transients'][ $name ] );
	return true;
}

/* ---------------------------------------------------------------------------
 * 钩子
 * ------------------------------------------------------------------------ */

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['at8sa_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['at8sa_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	unset( $GLOBALS['at8sa_test_actions'][ $hook ] );
	return true;
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	unset( $GLOBALS['at8sa_test_filters'][ $hook ] );
	return true;
}

/**
 * 触发动作钩子。
 *
 * 必须真实回调，不能空实现——否则"钩子挂没挂上"在测试里永远为真，
 * 依赖钩子的缺陷（真机上：设置变更后运行时配置没重建）就完全测不出来。
 * 实测教训：这个缺陷正是因为旧桩把 do_action() 写成 no-op 才漏过去的。
 *
 * @return null
 */
function do_action( $hook ) {
	$args = func_get_args();
	array_shift( $args );

	if ( empty( $GLOBALS['at8sa_test_actions'][ $hook ] ) ) {
		return null;
	}

	$entries = $GLOBALS['at8sa_test_actions'][ $hook ];
	$order   = array();

	foreach ( $entries as $index => $entry ) {
		$order[ $index ] = (int) $entry[1];
	}

	asort( $order );

	foreach ( array_keys( $order ) as $index ) {
		$entry = $entries[ $index ];

		if ( ! is_callable( $entry[0] ) ) {
			continue;
		}

		// 与 WP 一致：只传注册时声明的参数个数。
		call_user_func_array( $entry[0], array_slice( $args, 0, (int) $entry[2] ) );
	}

	return null;
}

function apply_filters( $hook, $value ) {
	if ( ! empty( $GLOBALS['at8sa_test_filters'][ $hook ] ) ) {
		foreach ( $GLOBALS['at8sa_test_filters'][ $hook ] as $entry ) {
			if ( is_callable( $entry[0] ) ) {
				$value = call_user_func( $entry[0], $value );
			}
		}
	}
	return $value;
}

function has_action( $hook, $callback = false ) {
	return ! empty( $GLOBALS['at8sa_test_actions'][ $hook ] );
}

function did_action( $hook ) {
	unset( $hook );
	return 0;
}

function register_activation_hook( $file, $callback ) {
	unset( $file, $callback );
	return true;
}

function register_deactivation_hook( $file, $callback ) {
	unset( $file, $callback );
	return true;
}

function register_uninstall_hook( $file, $callback ) {
	unset( $file, $callback );
	return true;
}

/* ---------------------------------------------------------------------------
 * 转义 / 清洗
 * ------------------------------------------------------------------------ */

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

function esc_url_raw( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}

function esc_js( $text ) {
	return addslashes( (string) $text );
}

function esc_sql( $text ) {
	return addslashes( (string) $text );
}

function wp_kses( $text, $allowed ) {
	unset( $allowed );
	return strip_tags( (string) $text, '<code><a><br>' );
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( (string) $value );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

function sanitize_title( $title ) {
	return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $title ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_parse_url( $url, $component = -1 ) {
	$parts = parse_url( (string) $url );
	if ( -1 === $component ) {
		return $parts;
	}
	$map = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);
	return isset( $map[ $component ], $parts[ $map[ $component ] ] ) ? $parts[ $map[ $component ] ] : null;
}

function is_wp_error( $thing ) {
	return false;
}

/* ---------------------------------------------------------------------------
 * URL / 路径
 * ------------------------------------------------------------------------ */

function home_url( $path = '' ) {
	return 'http://example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
}

function site_url( $path = '' ) {
	return home_url( $path );
}

function admin_url( $path = '' ) {
	return home_url( '/wp-admin/' . ltrim( (string) $path, '/' ) );
}

function content_url( $path = '' ) {
	return WP_CONTENT_URL . '/' . ltrim( (string) $path, '/' );
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( $file ) {
	unset( $file );
	return AT8SA_URL;
}

function plugin_basename( $file ) {
	unset( $file );
	return AT8SA_BASENAME;
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function wp_is_writable( $path ) {
	return is_writable( $path );
}

function wp_upload_dir() {
	return array(
		'basedir' => WP_CONTENT_DIR . '/uploads',
		'baseurl' => WP_CONTENT_URL . '/uploads',
	);
}

function wp_get_upload_dir() {
	return wp_upload_dir();
}

function get_bloginfo( $show = '' ) {
	$map = array(
		'version' => '6.6.1',
		'charset' => 'UTF-8',
		'name'    => 'Test Site',
	);
	return isset( $map[ $show ] ) ? $map[ $show ] : '';
}

function is_ssl() {
	return true;
}

function wp_get_theme() {
	return new class() {
		public function get( $key ) {
			return 'Version' === $key ? '1.0.0' : 'Test Theme';
		}
	};
}

function get_pagenum_link( $page = 1 ) {
	return home_url( '/page/' . (int) $page . '/' );
}

function get_permalink( $post = 0 ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	return home_url( '/post-' . $id . '/' );
}

function get_post_type_archive_link( $type ) {
	return 'post' === $type ? home_url( '/blog/' ) : false;
}

function get_author_posts_url( $id ) {
	return home_url( '/author/' . (int) $id . '/' );
}

function get_year_link( $year ) {
	return home_url( '/' . (int) $year . '/' );
}

function get_term_link( $term, $taxonomy = '' ) {
	unset( $taxonomy );
	return home_url( '/term-' . (int) $term . '/' );
}

function get_ancestors( $id, $type = '', $resource = '' ) {
	unset( $id, $type, $resource );
	return array();
}

function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	unset( $post_id, $taxonomy, $args );
	return array();
}

function get_object_taxonomies( $type ) {
	unset( $type );
	return array( 'category' );
}

function is_taxonomy_viewable( $taxonomy ) {
	unset( $taxonomy );
	return true;
}

function get_terms( $args ) {
	unset( $args );
	return array();
}

function get_term( $id ) {
	unset( $id );
	return null;
}

function wp_count_posts( $type = 'post' ) {
	unset( $type );
	return (object) array( 'publish' => 0 );
}

function get_post( $id ) {
	unset( $id );
	return null;
}

function get_attached_file( $id ) {
	unset( $id );
	return '';
}

function wp_get_attachment_metadata( $id ) {
	unset( $id );
	return array();
}

function get_post_meta( $id, $key, $single = false ) {
	unset( $id, $key, $single );
	return '';
}

function remove_query_arg( $key, $url = '' ) {
	$parts = explode( '?', (string) $url, 2 );
	if ( ! isset( $parts[1] ) ) {
		return $url;
	}
	parse_str( $parts[1], $query );
	unset( $query[ $key ] );
	$qs = http_build_query( $query );
	return $parts[0] . ( $qs ? '?' . $qs : '' );
}

function add_query_arg( $args, $url = '' ) {
	if ( is_array( $args ) ) {
		$query = $args;
	} else {
		$query = array( $args => $url );
		$url   = func_num_args() > 2 ? func_get_arg( 2 ) : '';
	}
	$separator = false === strpos( (string) $url, '?' ) ? '?' : '&';
	return $url . $separator . http_build_query( $query );
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return add_query_arg( $name, wp_create_nonce( $action ), $url );
}

function wp_create_nonce( $action = -1 ) {
	return substr( md5( 'nonce' . $action ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	unset( $nonce, $action );
	return 1;
}

function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
	unset( $action, $query_arg, $die );
	return 1;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	unset( $action, $query_arg );
	return 1;
}

function wp_safe_redirect( $location, $status = 302 ) {
	unset( $location, $status );
	return true;
}

function wp_get_referer() {
	return home_url( '/' );
}

function wp_die( $message = '' ) {
	throw new RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : 'error' ) );
}

function wp_send_json_success( $data = null, $status_code = 200 ) {
	unset( $status_code );
	throw new RuntimeException( 'json_success:' . wp_json_encode( $data ) );
}

function wp_send_json_error( $data = null, $status_code = 200 ) {
	unset( $status_code );
	throw new RuntimeException( 'json_error:' . wp_json_encode( $data ) );
}

/* ---------------------------------------------------------------------------
 * 条件判断
 * ------------------------------------------------------------------------ */

function is_admin() {
	return ! empty( $GLOBALS['at8sa_test_is_admin'] );
}

function wp_doing_ajax() {
	return ! empty( $GLOBALS['at8sa_test_doing_ajax'] );
}

function is_user_logged_in() {
	return ! empty( $GLOBALS['at8sa_test_logged_in'] );
}

function current_user_can( $cap ) {
	unset( $cap );
	return ! empty( $GLOBALS['at8sa_test_can_manage'] );
}

function is_multisite() {
	return false;
}

function is_front_page() {
	return true;
}

function is_home() {
	return true;
}

function is_404() {
	return false;
}

function is_search() {
	return false;
}

function is_feed() {
	return false;
}

function is_preview() {
	return false;
}

function is_trackback() {
	return false;
}

function is_singular() {
	return false;
}

function is_archive() {
	return false;
}

function post_password_required( $post = null ) {
	unset( $post );
	return false;
}

function is_admin_bar_showing() {
	return true;
}

/**
 * 子站 ID。
 *
 * 站点令牌（`BackendFactory::site_token()`）用它做多站点隔离，
 * 桩环境缺失它会让令牌丢掉 `blog` 段，进而掩盖真实行为。
 *
 * @return int
 */
function get_current_blog_id() {
	return isset( $GLOBALS['at8sa_test_blog_id'] ) ? (int) $GLOBALS['at8sa_test_blog_id'] : 1;
}

function get_current_screen() {
	/*
	 * 行为级测试需要控制「当前在哪个后台页面」，因此这里读全局而不是写死。
	 *
	 * 写死成 `toplevel_page_at8-site-accelerator` 的后果是：
	 * 任何「通知会不会跑到别的页面上去」的测试都测不到真实分支——
	 * 桩让所有页面都长成设置页，断言就恒真。第二轮整改恰好就是要治这个，
	 * 所以这里必须做成可注入的：`$GLOBALS['at8sa_test_screen_id']`
	 * 未设置时保持原默认（= 插件自己的设置页），已设置时返回对应 screen；
	 * 显式设为 null 时返回 null，模拟「拿不到 screen」的 AJAX / REST 上下文。
	 *
	 * @return object|null
	 */
	if ( array_key_exists( 'at8sa_test_screen_id', $GLOBALS ) ) {
		$id = $GLOBALS['at8sa_test_screen_id'];

		return null === $id ? null : (object) array( 'id' => $id );
	}

	return (object) array( 'id' => 'toplevel_page_at8-site-accelerator' );
}

function wp_is_post_revision( $post ) {
	unset( $post );
	return false;
}

function wp_is_post_autosave( $post ) {
	unset( $post );
	return false;
}

/* ---------------------------------------------------------------------------
 * 资源
 * ------------------------------------------------------------------------ */

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
	unset( $src, $deps, $ver, $args );

	// 真实记录入队状态：LinkPreloader::output_resource_preload() 会调 wp_script_is()
	// 判断"要不要预加载"，桩不记录的话这个分支永远走不到，等于没测。
	$GLOBALS['at8sa_test_enqueued_scripts'][] = $handle;

	return true;
}

function wp_script_is( $handle, $list = 'enqueued' ) {
	if ( 'registered' === $list ) {
		return in_array( $handle, (array) $GLOBALS['at8sa_test_registered_scripts'], true );
	}

	return in_array( $handle, (array) $GLOBALS['at8sa_test_enqueued_scripts'], true );
}

function wp_style_is( $handle, $list = 'enqueued' ) {
	unset( $handle, $list );

	return false;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	unset( $handle, $src, $deps, $ver, $media );
	return true;
}

function wp_localize_script( $handle, $name, $data ) {
	unset( $handle, $name, $data );
	return true;
}

/**
 * 模拟"WordPress 已注册 heartbeat 脚本"这个前置状态。
 *
 * `wp_deregister_script()` 的效果必须能被观测到，否则"前台被摘 / 后台不被摘"
 * 这类断言只能写成静态字符串匹配，测不出真实行为。
 *
 * @param string $handle 脚本句柄。
 * @return void
 */
function at8sa_test_register_scripts( array $handles ) {
	$GLOBALS['at8sa_test_registered_scripts'] = array_values( $handles );
}

/**
 * 模拟 WP 通过 `wp_default_scripts` 注册了 heartbeat。
 *
 * @return void
 */
function wp_scripts_maybe_registering() {
	$GLOBALS['at8sa_test_registered_scripts'] = array( 'heartbeat', 'jquery' );
	$GLOBALS['at8sa_test_enqueued_scripts']  = array( 'heartbeat', 'jquery' );
}

/**
 * 观测 `wp_deregister_script()` 的结果。
 *
 * @param string $handle 脚本句柄。
 * @return void
 */
function wp_deregister_script( $handle ) {
	$GLOBALS['at8sa_test_registered_scripts'] = array_values(
		array_diff( (array) $GLOBALS['at8sa_test_registered_scripts'], array( $handle ) )
	);

	$GLOBALS['at8sa_test_enqueued_scripts'] = array_values(
		array_diff( (array) $GLOBALS['at8sa_test_enqueued_scripts'], array( $handle ) )
	);

	return true;
}

function wp_deregister_style( $handle ) {
	unset( $handle );
	return true;
}

function wp_dequeue_style( $handle ) {
	unset( $handle );
	return true;
}

function wp_register_script() {
	return true;
}

/* ---------------------------------------------------------------------------
 * 后台 UI
 * ------------------------------------------------------------------------ */

function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback = '', $icon = '', $position = null ) {
	unset( $page_title, $menu_title, $capability, $slug, $callback, $icon, $position );
	return 'toplevel_page_at8-site-accelerator';
}

function add_options_page() {
	return 'settings_page_at8-site-accelerator';
}

function register_setting( $group, $name, $args = array() ) {
	unset( $group, $name, $args );
	return true;
}

function settings_fields( $group ) {
	unset( $group );
	echo '<input type="hidden" name="option_page" value="at8sa_settings_group" />';
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	unset( $type, $name, $other );
	$label = null === $text ? 'Save Changes' : $text;
	if ( $wrap ) {
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
		return;
	}
	echo '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button>';
}

function checked( $checked, $current = true, $echo = true ) {
	$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

function selected( $selected, $current = true, $echo = true ) {
	$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
	if ( $echo ) {
		echo $result;
	}
	return $result;
}

/**
 * 记录 `remove_meta_box()` 的调用。
 *
 * 原始桩只是丢弃参数，于是"只删新闻、不动 Site Health"这类断言无从下手——
 * 而这恰恰是审核指出的真实缺陷（一次回调顺手删了四个 meta box）。
 * 记录下来才能真断言"哪个被删了、哪个没被删"。
 *
 * @param string $id      meta box ID。
 * @param string $screen 所属屏幕。
 * @param string $context 上下文。
 * @return bool
 */
function remove_meta_box( $id, $screen, $context ) {
	unset( $screen, $context );

	if ( ! isset( $GLOBALS['at8sa_removed_meta_boxes'] ) ) {
		$GLOBALS['at8sa_removed_meta_boxes'] = array();
	}

	$GLOBALS['at8sa_removed_meta_boxes'][ $id ] = array(
		'id'      => $id,
		'removed' => true,
	);

	return true;
}

function remove_submenu_page( $menu, $submenu ) {
	unset( $menu, $submenu );
	return true;
}

function get_plugins() {
	return array();
}

function size_format( $bytes, $decimals = 0 ) {
	$bytes = (float) $bytes;
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, $decimals ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return round( $bytes / 1024, $decimals ) . ' KB';
	}
	return $bytes . ' B';
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function wp_next_scheduled( $hook, $args = array() ) {
	unset( $args );
	return isset( $GLOBALS['at8sa_test_scheduled'][ $hook ] ) ? $GLOBALS['at8sa_test_scheduled'][ $hook ] : false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	unset( $args );
	$GLOBALS['at8sa_test_scheduled'][ $hook ] = $timestamp;
	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	unset( $timestamp, $args );
	unset( $GLOBALS['at8sa_test_scheduled'][ $hook ] );
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	unset( $args );
	unset( $GLOBALS['at8sa_test_scheduled'][ $hook ] );
	return true;
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	unset( $namespace, $route, $args, $override );
	return true;
}

function rest_authorization_required_code() {
	return 401;
}

function wp_delete_post( $id, $force = false ) {
	unset( $id, $force );
	return true;
}

function wp_delete_comment( $id, $force = false ) {
	unset( $id, $force );
	return true;
}

/**
 * `wp_delete_file()` 桩。
 *
 * 插件在「精准删除缓存文件」「递归清空缓存目录」「卸载 drop-in」「删除 wp-config
 * 临时备份」四条路径上都调用它。桩**必须真的删文件**——否则磁盘后端的删除断言会
 * 假通过（文件明明还在，测试却以为删掉了），而这种假绿灯正是"缓存清了但页面没变"
 * 这类线上问题最爱的藏身处。
 *
 * 签名与 WP 核心严格一致（**返回 bool**，不是 void）：真实实现先 `is_file()` 再
 * `unlink()`，成功返回 true、文件不存在或删除失败返回 false。插件里
 * `AdvancedCache::discard_wp_config_backup()` 正是靠这个返回值决定"主路径成功"
 * 还是"需要兜底再删一次"。桩若返回 void，主路径永远判为失败，兜底分支就成了
 * 唯一被测到的路径 —— 而真实站点上恰好相反。
 *
 * @param string $file 待删文件路径。
 * @return bool 是否删除成功。
 */
function wp_delete_file( $file ) {
	// 测试可观测点：记录主路径被调用过，并允许测试强制让主路径失败，
	// 以便验证插件侧的兜底分支（两段式删除里的第二段）确实能接手。
	// 生产环境不存在这两个全局变量。
	if ( isset( $GLOBALS['at8sa_test_wp_delete_file_calls'] ) && is_array( $GLOBALS['at8sa_test_wp_delete_file_calls'] ) ) {
		$GLOBALS['at8sa_test_wp_delete_file_calls'][] = $file;
	}
	if ( ! empty( $GLOBALS['at8sa_test_wp_delete_file_force_fail'] ) ) {
		return false;
	}

	if ( ! $file || ! is_file( $file ) ) {
		return false;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 与 WP 核心一致：删除失败静默返回 false。
	return (bool) @unlink( $file );
}

function get_comment( $id ) {
	unset( $id );
	return null;
}

function current_time( $type, $gmt = 0 ) {
	unset( $gmt );
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

function __( $text, $domain = 'default' ) {
	unset( $domain );
	return $text;
}

function _e( $text, $domain = 'default' ) {
	echo __( $text, $domain );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text, $domain ) );
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html__( $text, $domain );
}

function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr__( $text, $domain );
}

function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( __( $text, $domain ) );
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function load_plugin_textdomain() {
	return true;
}

/* ---------------------------------------------------------------------------
 * $wpdb 桩
 * ------------------------------------------------------------------------ */

class AT8SA_Test_WPDB {

	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public $comments  = 'wp_comments';
	public $options   = 'wp_options';
	public $terms     = 'wp_terms';

	public function get_var( $query ) {
		unset( $query );
		return 0;
	}

	public function get_col( $query ) {
		unset( $query );
		return array();
	}

	public function get_results( $query, $output = OBJECT ) {
		unset( $query, $output );
		return array();
	}

	public function query( $query ) {
		unset( $query );
		return true;
	}

	public function prepare( $query, ...$args ) {
		unset( $args );
		return $query;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function db_version() {
		return '8.0.35';
	}

	public function tables( $scope = 'all', $prefix = true, $blog_id = 0 ) {
		unset( $scope, $prefix, $blog_id );
		return array( 'posts', 'postmeta', 'comments', 'options', 'terms' );
	}
}

$GLOBALS['wpdb'] = new AT8SA_Test_WPDB();

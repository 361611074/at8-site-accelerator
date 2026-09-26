<?php
/**
 * PHPStan 引导文件。
 *
 * 插件常量（AT8SA_PATH / AT8SA_VERSION / AT8SA_CACHE_ROOT 等）在
 * `at8-site-accelerator.php` 里用 define() 声明，而那个文件同时又做了
 * 环境校验、自动加载注册、生命周期钩子注册——直接让 PHPStan 执行它副作用太大。
 *
 * 这里只把"静态分析需要知道的常量"重新声明一遍，值取的是产品实际取值
 * （路径用占位字符串即可，PHPStan 只关心类型与是否已定义）。
 *
 * 注意：**不要**在这里 define ABSPATH。wordpress-stubs 已经声明了它，
 * 重复定义会让 PHPStan 报 "Constant ABSPATH already defined"。
 *
 * @package AT8\SiteAccelerator\Tests
 */

// phpcs:disable

// 引导文件位于 tests/phpstan/，往上两级就是插件根目录。
//
// 这里必须指向**真实存在的目录**，不能再用 /tmp 占位：smoke.php 里有
// `include AT8SA_PATH . 'templates/settings-page.php'`，路径拼出来若不存在，
// PHPStan 会报 include.fileNotFound——而那是引导文件的锅，不是产品代码的锅。
if ( ! defined( 'AT8SA_PATH' ) ) {
	define( 'AT8SA_PATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'AT8SA_URL' ) ) {
	define( 'AT8SA_URL', 'https://example.test/wp-content/plugins/at8-site-accelerator/' );
}
if ( ! defined( 'AT8SA_FILE' ) ) {
	define( 'AT8SA_FILE', AT8SA_PATH . 'at8-site-accelerator.php' );
}
if ( ! defined( 'AT8SA_BASENAME' ) ) {
	define( 'AT8SA_BASENAME', 'at8-site-accelerator/at8-site-accelerator.php' );
}
if ( ! defined( 'AT8SA_VERSION' ) ) {
	define( 'AT8SA_VERSION', '3.0.1' );
}
// 这里刻意用字面量而不是拼接 WP_CONTENT_DIR / WP_CONTENT_URL：
// 后者在 wordpress-stubs 里并不保证存在，会让引导文件自己抛
// "Undefined constant"，反而掩盖真正的分析结果。PHPStan 只关心
// 常量已定义且类型是 string，值本身无所谓。
if ( ! defined( 'AT8SA_CACHE_ROOT' ) ) {
	define( 'AT8SA_CACHE_ROOT', '/tmp/at8-wp-content/cache/at8-site-accelerator' );
}
if ( ! defined( 'AT8SA_CACHE_ROOT_URL' ) ) {
	define( 'AT8SA_CACHE_ROOT_URL', 'https://example.test/wp-content/cache/at8-site-accelerator' );
}

// 测试专用常量。
//
// COOKIEHASH 是 WP 核心常量，但它声明在 tests/unit/wp-stubs.php 里，
// 而那个文件在 phpstan.neon.dist 的 excludePaths 中（桩里大量刻意的不完整实现
// 会自己产生噪声）。排除之后 smoke.php 就看不到这个常量了，所以在分析引导里补一份。
if ( ! defined( 'COOKIEHASH' ) ) {
	define( 'COOKIEHASH', 'abc123def456' );
}

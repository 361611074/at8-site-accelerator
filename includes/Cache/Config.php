<?php
/**
 * 运行时缓存配置：把设置翻译成 drop-in 能直接吃的扁平数组，并落盘成 PHP 文件。
 *
 * 为什么落盘而不是每次 `get_option()`：
 * `advanced-cache.php` 运行在 WordPress 极早期，此时读 option 需要走一次数据库查询。
 * 把它固化成 `cache/at8-site-accelerator/config/<host>.php`（纯 PHP 数组，可被 opcache
 * 缓存），命中路径就完全零数据库开销。这正是 WP Rocket 的 config 文件思路。
 *
 * @package AT8SA\Cache
 */

namespace AT8SA\Cache;

use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Core\Settings;
use AT8SA\Support\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Class Config
 */
final class Config {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 后端工厂（提供站点盐）。
	 *
	 * @var BackendFactory
	 */
	private $factory;

	/**
	 * 构造。
	 *
	 * @param Settings       $settings 设置。
	 * @param BackendFactory $factory  后端工厂。
	 */
	public function __construct( Settings $settings, BackendFactory $factory ) {
		$this->settings = $settings;
		$this->factory  = $factory;
	}

	/**
	 * 生成 drop-in 运行时配置。
	 *
	 * 只放"命中路径需要的最小信息"，绝不放任何密钥。
	 *
	 * @return array
	 */
	public function runtime() {
		$settings = $this->settings;
		$backend  = $settings->get( 'cache_backend', 'auto' );

		// auto 模式在 drop-in 里无法做可达性探测（会引入 1 秒超时风险），
		// 因此由插件侧探测结果决定 drop-in 实际读哪个后端。
		if ( 'auto' === $backend ) {
			$backend = $this->factory->redis_probe() ? 'redis' : 'disk';
		}

		$config = array(
			'enabled'         => (int) $settings->is_on( 'page_cache' ),
			'safe_mode'       => (int) $settings->is_on( 'safe_mode' ),
			'backend'         => $backend,
			'salt'            => $this->factory->salt(),
			'cache_root'      => AT8SA_CACHE_ROOT,
			'cache_mobile'    => (int) $settings->is_on( 'cache_mobile' ),
			// 恒为 0：登录用户缓存已在 3.0.5 移除。
			//
			// 这里**不能**改回 `$settings->is_on( 'cache_logged_in' )`。老站点的
			// `at8sa_settings` 里可能已经存着 `cache_logged_in => 1`，若照读，
			// 升级后仍会走进登录态缓存；而缓存 key 只有「站点盐 + host + URI +
			// 移动标记」，**没有任何用户维度**，等于用户 A 写、用户 B 读。
			// 硬钉 0 是让历史配置失效的唯一可靠办法。
			'cache_logged_in' => 0,
			'ttl'             => (int) $settings->get( 'cache_ttl', 3600 ),
			'excluded_paths'  => $this->excluded_paths(),
			'bypass_cookies'  => $this->bypass_cookies(),
			'ignore_query'    => $this->ignore_query_rules(),
			'charset'         => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'charset' ) : 'UTF-8',
			'redis'           => 'redis' === $backend ? $this->redis_args() : null,
			'debug'           => (int) $settings->is_on( 'preload_debug' ),
			'version'         => AT8SA_VERSION,
			// 设置指纹：供 needs_refresh() 判断落盘配置是否已过期。
			'settings_hash'   => $this->settings_hash(),
		);

		/**
		 * 过滤 drop-in 运行时配置。
		 *
		 * @param array $config 配置。
		 */
		return apply_filters( 'at8sa_runtime_config', $config );
	}

	/**
	 * 把配置写入 `config/<host>.php`。
	 *
	 * @param array $config 运行时配置。
	 * @return bool
	 */
	public function write( array $config ) {
		$dir = AT8SA_CACHE_ROOT . '/config';

		if ( ! Filesystem::mkdir_guarded( $dir ) ) {
			return false;
		}

		$host = $this->config_host();
		$body = "<?php\n"
			. "// AT8 Site Accelerator 运行时配置 —— 由插件自动生成，请勿手工编辑。\n"
			. "// 修改设置后插件会自动重写本文件。\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$ok = Filesystem::put_contents( $dir . '/' . $host . '.php', $body );

		// 同时写一份 default 兜底，避免首次请求（HTTP_HOST 缺失）读不到配置。
		Filesystem::put_contents( $dir . '/default.php', $body );

		// 即便退化到 home_url()，也可能与实际访问域名不一致（多域名、反向代理）。
		// 因此把已有的其它 host 配置一并刷新，避免任何一个 drop-in 读到陈旧配置。
		// 单站点场景下它们本就该是同一份内容；index.php 是目录守卫，跳过。
		$others = glob( $dir . '/*.php' );

		if ( is_array( $others ) ) {
			foreach ( $others as $file ) {
				$base = basename( $file, '.php' );

				if ( 'default' === $base || 'index' === $base || $base === $host ) {
					continue;
				}

				Filesystem::put_contents( $file, $body );
			}
		}

		return $ok;
	}

	/**
	 * 落盘的运行时配置是否已过期（需要重写）。
	 *
	 * 为什么除了 `update_option_{$option}` 钩子之外还要这一层：
	 * 钩子只能覆盖"走 `update_option()`"的路径。现实里还存在绕过它的写入方式——
	 * 直接 `$wpdb->update()`、`wp option import`、站点迁移脚本、DB 层面的手工修改。
	 * 这些情况下钩子不触发，配置就永远停在旧值上。
	 *
	 * 这里用设置指纹做兜底：每个请求（插件已加载时）比一次哈希，
	 * 一旦对不上就重写。成本极低——`at8sa_settings` 是 autoload 选项，
	 * 读它不产生额外查询；命中的请求更是在 drop-in 阶段就 `exit` 了，压根到不了这里。
	 *
	 * @return bool
	 */
	public function needs_refresh() {
		$file = AT8SA_CACHE_ROOT . '/config/' . $this->config_host() . '.php';

		if ( ! is_readable( $file ) ) {
			return true;
		}

		$stored = include $file;

		if ( ! is_array( $stored ) || ! isset( $stored['settings_hash'] ) ) {
			// 旧版本写下的配置没有指纹字段，一律视为过期，借机补上。
			return true;
		}

		return (string) $stored['settings_hash'] !== $this->settings_hash();
	}

	/**
	 * 运行时配置对应的主机名（决定写哪个配置文件）。
	 *
	 * `write()` 与 `needs_refresh()` 必须用同一套归一化，否则会"写 A 读 B"。
	 *
	 * @return string
	 */
	private function config_host() {
		// 与 drop-in 复用同一个净化入口，保证两边算出的主机名一致。
		$http_host = RequestGuard::server( 'HTTP_HOST' );

		// WP-CLI / WP-Cron 这类非 HTTP 上下文里没有 HTTP_HOST，此时退化成从
		// home_url() 取主机，保证写出的文件名与前台请求的主机名一致。
		// 否则会写成 default.php，而 drop-in 在前台（有 HTTP_HOST）优先读
		// <host>.php —— 于是 CLI 里的配置改动永远到不了 drop-in。
		// 真机实测：wp-cli 把后端切成 redis，脚本报成功，前台响应头却仍是 disk。
		if ( '' === $http_host && function_exists( 'home_url' ) ) {
			$parsed = wp_parse_url( home_url() );

			if ( ! empty( $parsed['host'] ) ) {
				$http_host = (string) $parsed['host'];
			}
		}

		return CachePath::normalize_host( $http_host );
	}

	/**
	 * 设置指纹。
	 *
	 * 只取"会影响运行时行为"的那部分设置，避免改了无关开关也触发重写。
	 * `all()` 读的是 autoload 选项且带实例级缓存，因此调用是廉价的。
	 *
	 * @return string
	 */
	private function settings_hash() {
		$settings = $this->settings->all();

		// 排序保证同一组设置永远得到同一个指纹（数组顺序不应影响判定）。
		ksort( $settings );

		return md5( (string) wp_json_encode( $settings ) );
	}

	/**
	 * 删除全部运行时配置文件。
	 *
	 * @return void
	 */
	public function delete_all() {
		$dir = AT8SA_CACHE_ROOT . '/config';

		if ( is_dir( $dir ) ) {
			Filesystem::rrmdir( $dir );
		}
	}

	/**
	 * 排除路径（内置 + 用户自定义）。
	 *
	 * @return array
	 */
	private function excluded_paths() {
		// 复用 RequestGuard 的解析入口，保证与 HTML 浏览器缓存的 Gate 用同一份规则。
		return RequestGuard::merge_rules(
			RequestGuard::default_excluded_paths(),
			(string) $this->settings->get( 'exclude_urls', '' )
		);
	}

	/**
	 * 绕过 Cookie（内置 + 用户自定义）。
	 *
	 * @return array
	 */
	private function bypass_cookies() {
		return RequestGuard::merge_rules(
			RequestGuard::default_bypass_cookies(),
			(string) $this->settings->get( 'bypass_cookies', '' )
		);
	}

	/**
	 * 追加的 query 忽略规则。
	 *
	 * @return array
	 */
	private function ignore_query_rules() {
		return RequestGuard::merge_rules( array(), (string) $this->settings->get( 'ignore_query', '' ) );
	}

	/**
	 * Redis 连接参数（drop-in 用）。
	 *
	 * @return array
	 */
	private function redis_args() {
		/**
		 * 过滤 Redis 连接参数。
		 *
		 * @param array $args 参数。
		 */
		return apply_filters(
			'at8sa_redis_args',
			array(
				'host' => defined( 'AT8SA_REDIS_HOST' ) ? AT8SA_REDIS_HOST : '127.0.0.1',
				'port' => defined( 'AT8SA_REDIS_PORT' ) ? (int) AT8SA_REDIS_PORT : 6379,
				'db'   => defined( 'AT8SA_REDIS_DB' ) ? (int) AT8SA_REDIS_DB : 2,
			)
		);
	}
}

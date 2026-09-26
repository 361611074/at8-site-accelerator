<?php
/**
 * 运行时缓存配置：把设置翻译成 drop-in 能直接吃的扁平数组，并落盘成 PHP 文件。
 *
 * 为什么落盘而不是每次 `get_option()`：
 * `advanced-cache.php` 运行在 WordPress 极早期，此时读 option 需要走一次数据库查询。
 * 把它固化成 `cache/at8-site-accelerator/config/<host>.php`（纯 PHP 数组，可被 opcache
 * 缓存），命中路径就完全零数据库开销。这正是 WP Rocket 的 config 文件思路。
 *
 * @package AT8\SiteAccelerator\Cache
 */

namespace AT8\SiteAccelerator\Cache;

use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Support\Filesystem;

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
			'cache_logged_in' => (int) $settings->is_on( 'cache_logged_in' ),
			'cookie_hash'     => defined( 'COOKIEHASH' ) ? (string) COOKIEHASH : '',
			'ttl'             => (int) $settings->get( 'cache_ttl', 3600 ),
			'excluded_paths'  => $this->excluded_paths(),
			'bypass_cookies'  => $this->bypass_cookies(),
			'ignore_query'    => $this->ignore_query_rules(),
			'charset'         => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'charset' ) : 'UTF-8',
			'redis'           => 'redis' === $backend ? $this->redis_args() : null,
			'debug'           => (int) $settings->is_on( 'preload_debug' ),
			'version'         => AT8SA_VERSION,
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

		$host = CachePath::normalize_host( $http_host );
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
		$paths = RequestGuard::default_excluded_paths();

		$custom = (string) $this->settings->get( 'exclude_urls', '' );

		if ( '' !== trim( $custom ) ) {
			foreach ( preg_split( '/\r?\n|,/', $custom ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$paths[] = $line;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * 绕过 Cookie（内置 + 用户自定义）。
	 *
	 * @return array
	 */
	private function bypass_cookies() {
		$cookies = RequestGuard::default_bypass_cookies();

		$custom = (string) $this->settings->get( 'bypass_cookies', '' );

		if ( '' !== trim( $custom ) ) {
			foreach ( preg_split( '/\r?\n|,/', $custom ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$cookies[] = $line;
				}
			}
		}

		return array_values( array_unique( $cookies ) );
	}

	/**
	 * 追加的 query 忽略规则。
	 *
	 * @return array
	 */
	private function ignore_query_rules() {
		$rules  = array();
		$custom = (string) $this->settings->get( 'ignore_query', '' );

		if ( '' !== trim( $custom ) ) {
			foreach ( preg_split( '/\r?\n|,/', $custom ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$rules[] = $line;
				}
			}
		}

		return $rules;
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

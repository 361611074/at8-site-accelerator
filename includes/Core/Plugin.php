<?php
/**
 * 插件主类：组装容器、按上下文启动各功能域。
 *
 * 启动顺序刻意分三段：
 * 1. `register()` —— 纯登记，零副作用；
 * 2. `boot_shared()` —— 前后台都需要（缓存写入、失效、优化、兼容）；
 * 3. `boot_admin()` —— 仅后台（设置页、AJAX、提示）。
 *
 * 这样在访客请求里完全不会加载后台代码，是"命中路径零开销"的前提。
 *
 * @package AT8\SiteAccelerator\Core
 */

namespace AT8\SiteAccelerator\Core;

use AT8\SiteAccelerator\Admin\AdminBar;
use AT8\SiteAccelerator\Admin\Ajax;
use AT8\SiteAccelerator\Admin\Notices;
use AT8\SiteAccelerator\Admin\SettingsPage;
use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Cache\CacheEngine;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Compatibility\CachePluginDetector;
use AT8\SiteAccelerator\Compatibility\ElementorCompat;
use AT8\SiteAccelerator\Compatibility\WooCommerceCompat;
use AT8\SiteAccelerator\Diagnostics\Diagnostics;
use AT8\SiteAccelerator\Optimization\BrowserCache;
use AT8\SiteAccelerator\Optimization\DatabaseCleanup;
use AT8\SiteAccelerator\Optimization\FrontendCleanup;
use AT8\SiteAccelerator\Optimization\HtmlMinifier;
use AT8\SiteAccelerator\Optimization\LazyLoad;
use AT8\SiteAccelerator\Optimization\LinkPreloader;
use AT8\SiteAccelerator\Optimization\Webp;
use AT8\SiteAccelerator\Purge\PurgeActions;
use AT8\SiteAccelerator\Purge\Purger;
use AT8\SiteAccelerator\REST\CacheController;
use AT8\SiteAccelerator\REST\DiagnosticsController;
use AT8\SiteAccelerator\REST\SettingsController;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * 单例。
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * 服务容器。
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * 是否已启动。
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * 私有构造。
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * 取单例。
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 容器。
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * 启动。
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();
		$this->maybe_migrate();
		$this->boot_shared();

		if ( is_admin() ) {
			$this->boot_admin();
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * 登记服务。
	 *
	 * @return void
	 */
	private function register_services() {
		$c = $this->container;

		$c->bind(
			Settings::class,
			function () {
				return new Settings();
			}
		);

		$c->bind(
			Logger::class,
			function ( $c ) {
				return new Logger( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			BackendFactory::class,
			function ( $c ) {
				return new BackendFactory( $c->get( Settings::class ), $c->get( Logger::class ) );
			}
		);

		$c->bind(
			Config::class,
			function ( $c ) {
				return new Config( $c->get( Settings::class ), $c->get( BackendFactory::class ) );
			}
		);

		$c->bind(
			AdvancedCache::class,
			function ( $c ) {
				return new AdvancedCache( $c->get( Logger::class ) );
			}
		);

		$c->bind(
			HtmlMinifier::class,
			function ( $c ) {
				return new HtmlMinifier( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			CacheEngine::class,
			function ( $c ) {
				return new CacheEngine(
					$c->get( Settings::class ),
					$c->get( BackendFactory::class ),
					$c->get( Logger::class ),
					$c->get( HtmlMinifier::class )
				);
			}
		);

		$c->bind(
			Purger::class,
			function ( $c ) {
				return new Purger(
					$c->get( Settings::class ),
					$c->get( BackendFactory::class ),
					$c->get( Logger::class )
				);
			}
		);

		$c->bind(
			PurgeActions::class,
			function ( $c ) {
				return new PurgeActions(
					$c->get( Settings::class ),
					$c->get( Purger::class ),
					$c->get( Logger::class )
				);
			}
		);

		$c->bind(
			SettingsSync::class,
			function ( $c ) {
				return new SettingsSync(
					$c->get( Settings::class ),
					$c->get( BackendFactory::class ),
					$c->get( Config::class ),
					$c->get( AdvancedCache::class ),
					$c->get( Purger::class )
				);
			}
		);

		$c->bind(
			CachePluginDetector::class,
			function ( $c ) {
				return new CachePluginDetector( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			BrowserCache::class,
			function ( $c ) {
				return new BrowserCache( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			DatabaseCleanup::class,
			function ( $c ) {
				return new DatabaseCleanup( $c->get( Settings::class ), $c->get( Logger::class ) );
			}
		);

		$c->bind(
			Webp::class,
			function ( $c ) {
				return new Webp( $c->get( Settings::class ), $c->get( Logger::class ) );
			}
		);

		$c->bind(
			FrontendCleanup::class,
			function ( $c ) {
				return new FrontendCleanup( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			LinkPreloader::class,
			function ( $c ) {
				return new LinkPreloader( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			LazyLoad::class,
			function ( $c ) {
				return new LazyLoad( $c->get( Settings::class ) );
			}
		);

		$c->bind(
			ElementorCompat::class,
			function ( $c ) {
				return new ElementorCompat( $c->get( Logger::class ) );
			}
		);

		$c->bind(
			WooCommerceCompat::class,
			function ( $c ) {
				return new WooCommerceCompat( $c->get( Purger::class ), $c->get( Logger::class ) );
			}
		);

		$c->bind(
			Diagnostics::class,
			function ( $c ) {
				return new Diagnostics(
					$c->get( Settings::class ),
					$c->get( BackendFactory::class ),
					$c->get( AdvancedCache::class ),
					$c->get( CachePluginDetector::class ),
					$c->get( BrowserCache::class ),
					$c->get( Webp::class )
				);
			}
		);
	}

	/**
	 * 2.x → 3.0 设置迁移（幂等，只在首次升级时真正执行）。
	 *
	 * @return void
	 */
	private function maybe_migrate() {
		$settings = $this->container->get( Settings::class );
		$settings->migrate_from_legacy();
	}

	/**
	 * 前后台共用模块。
	 *
	 * @return void
	 */
	private function boot_shared() {
		$c = $this->container;

		// 缓存：先于一切（命中判断要最早）。
		$c->get( CacheEngine::class )->boot();

		// 失效：内容变更时精准失效。
		$c->get( PurgeActions::class )->boot();

		// 优化。
		$c->get( FrontendCleanup::class )->boot();
		$c->get( LinkPreloader::class )->boot();
		$c->get( LazyLoad::class )->boot();
		$c->get( BrowserCache::class )->boot();
		$c->get( Webp::class )->boot();
		$c->get( DatabaseCleanup::class )->boot();

		// 兼容。
		$c->get( ElementorCompat::class )->boot();
		$c->get( WooCommerceCompat::class )->boot();

		// 设置变更 → 运行时同步。必须放这里（而不是 boot_admin）：
		// 设置也可能在 WP-CLI / WP-Cron / 其它插件里被改，那些上下文没有后台。
		$c->get( SettingsSync::class )->boot();

		// 运行时配置的兜底：缺失或已过期就补写，保证 drop-in 读到的是最新配置。
		add_action( 'init', array( $this, 'ensure_runtime_config' ), 99 );
	}

	/**
	 * 后台模块。
	 *
	 * @return void
	 */
	private function boot_admin() {
		$c = $this->container;

		$deps = array(
			'settings'       => $c->get( Settings::class ),
			'factory'        => $c->get( BackendFactory::class ),
			'config'         => $c->get( Config::class ),
			'advanced_cache' => $c->get( AdvancedCache::class ),
			'purger'         => $c->get( Purger::class ),
			'db_cleanup'     => $c->get( DatabaseCleanup::class ),
			'detector'       => $c->get( CachePluginDetector::class ),
			'browser_cache'  => $c->get( BrowserCache::class ),
			'diagnostics'    => $c->get( Diagnostics::class ),
			'logger'         => $c->get( Logger::class ),
		);

		( new SettingsPage( $deps ) )->boot();
		( new Ajax( $deps ) )->boot();
		( new Notices( $deps ) )->boot();
		( new AdminBar() )->boot();
	}

	/**
	 * 注册 REST 路由。
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$c = $this->container;

		( new CacheController( $c->get( Purger::class ) ) )->register_routes();
		( new SettingsController( $c->get( Settings::class ), $c->get( BackendFactory::class ), $c->get( Config::class ) ) )->register_routes();
		( new DiagnosticsController( $c->get( Diagnostics::class ) ) )->register_routes();
	}

	/**
	 * 确保 drop-in 运行时配置文件存在且为最新。
	 *
	 * "为最新"的判定交给 `Config::needs_refresh()`：它比对设置指纹，
	 * 因此能覆盖 `update_option()` 之外的各种写入路径（直接改库、导入选项等）。
	 *
	 * @return void
	 */
	public function ensure_runtime_config() {
		$config = $this->container->get( Config::class );

		if ( ! $config->needs_refresh() ) {
			return;
		}

		$config->write( $config->runtime() );
	}
}

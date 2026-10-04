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
 * @package AT8SA\Core
 */

namespace AT8SA\Core;

use AT8SA\Admin\AdminBar;
use AT8SA\Admin\Ajax;
use AT8SA\Admin\Notices;
use AT8SA\Admin\SettingsPage;
use AT8SA\Cache\AdvancedCache;
use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\CacheEngine;
use AT8SA\Cache\Config;
use AT8SA\Compatibility\CachePluginDetector;
use AT8SA\Compatibility\ElementorCompat;
use AT8SA\Compatibility\WooCommerceCompat;
use AT8SA\Diagnostics\Diagnostics;
use AT8SA\Optimization\BrowserCache;
use AT8SA\Optimization\DatabaseCleanup;
use AT8SA\Optimization\FrontendCleanup;
use AT8SA\Optimization\HtmlMinifier;
use AT8SA\Optimization\LazyLoad;
use AT8SA\Optimization\LinkPreloader;
use AT8SA\Optimization\Webp;
use AT8SA\Purge\PurgeActions;
use AT8SA\Purge\Purger;
use AT8SA\REST\CacheController;
use AT8SA\REST\DiagnosticsController;
use AT8SA\REST\SettingsController;
use AT8SA\Support\Logger;

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
		//
		// 优先级必须是 0，不能是 99：`CacheEngine::maybe_serve_from_cache()` 挂在
		// `init` 优先级 1，命中插件侧缓存时它输出完就 `exit`，99 的兜底**永远跑不到**
		// （真机实测：首页 HIT 时 drop-in 与运行时配置都不会被刷新）。
		// 兜底要早于"任何可能提前退出的路径"，所以放在 1 之前。
		add_action( 'init', array( $this, 'ensure_runtime_config' ), 0 );

		// drop-in 的兜底：与当前插件版本不一致就重装。同样必须早于 init 优先级 1。
		add_action( 'init', array( $this, 'ensure_dropin' ), 0 );
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

	/**
	 * 确保 `advanced-cache.php` drop-in 与当前插件版本一致。
	 *
	 * 为什么必须有这一层（真机实测到的整站白屏）：
	 * drop-in 是**复制**到 `wp-content/` 的独立文件，插件升级只替换插件目录里的文件，
	 * 不会动它。它又跑在 WordPress 之前，引用的类名一旦与新版插件对不上就是 PHP Fatal，
	 * 前台与 wp-admin 一起白屏。3.0.2 把命名空间改成 `AT8SA` 时正好踩中这条。
	 *
	 * 兜底是两层的，缺一不可：
	 * - `templates/advanced-cache.php` 里的 `class_exists()` 护栏 → 把"白屏"降级为
	 *   "暂时没有页面缓存"，让 WordPress 还能正常加载；
	 * - 本方法 → 在 WordPress 起来之后，把那份过期的 drop-in 重写成当前版本。
	 *
	 * 对"后台自动更新"尤其重要：那条路径（`wp_doing_cron()`）不会停用/重新激活插件，
	 * 所以不会走 `Activator::activate()`，drop-in 只能靠这里修好。
	 *
	 * @return void
	 */
	public function ensure_dropin() {
		// 只有"高级缓存（drop-in）"开着时才自愈。
		// 关掉它之后 `SettingsSync::sync()` 只是"不再安装"，并不会删掉磁盘上已有的文件；
		// 少了这道门禁，用户关掉 drop-in 之后一升级又会被装回来。
		if ( ! $this->container->get( Settings::class )->is_on( 'advanced_cache' ) ) {
			return;
		}

		$dropin = $this->container->get( AdvancedCache::class );

		if ( ! $dropin->needs_reinstall() ) {
			return;
		}

		if ( $dropin->install() ) {
			$this->container->get( Logger::class )->info( 'advanced-cache.php 已按当前版本重装', array( 'version' => AT8SA_VERSION ) );
		}
	}
}

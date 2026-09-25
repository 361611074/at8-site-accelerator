<?php
/**
 * 诊断信息。
 *
 * 计划书 §61 的原则：**只报事实，不做夸张评分**。
 * 所以这里没有"87/100 分"这种哄人的东西，只有可核对的客观项，
 * 以及"这一项意味着什么"的一句话解释。
 *
 * 所有信息都在本地采集，**不会发送到任何远程服务**（计划书 §60）。
 *
 * @package AT8\SiteAccelerator\Diagnostics
 */

namespace AT8\SiteAccelerator\Diagnostics;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Compatibility\CachePluginDetector;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Optimization\BrowserCache;
use AT8\SiteAccelerator\Optimization\Webp;

defined( 'ABSPATH' ) || exit;

/**
 * Class Diagnostics
 */
final class Diagnostics {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 后端工厂。
	 *
	 * @var BackendFactory
	 */
	private $factory;

	/**
	 * drop-in 管理器。
	 *
	 * @var AdvancedCache
	 */
	private $advanced_cache;

	/**
	 * 冲突检测器。
	 *
	 * @var CachePluginDetector
	 */
	private $detector;

	/**
	 * 浏览器缓存模块。
	 *
	 * @var BrowserCache
	 */
	private $browser_cache;

	/**
	 * WebP 模块。
	 *
	 * @var Webp
	 */
	private $webp;

	/**
	 * 构造。
	 *
	 * @param Settings            $settings       设置。
	 * @param BackendFactory      $factory        后端工厂。
	 * @param AdvancedCache       $advanced_cache drop-in 管理器。
	 * @param CachePluginDetector $detector       冲突检测器。
	 * @param BrowserCache        $browser_cache  浏览器缓存。
	 * @param Webp                $webp           WebP 模块。
	 */
	public function __construct(
		Settings $settings,
		BackendFactory $factory,
		AdvancedCache $advanced_cache,
		CachePluginDetector $detector,
		BrowserCache $browser_cache,
		Webp $webp
	) {
		$this->settings       = $settings;
		$this->factory        = $factory;
		$this->advanced_cache = $advanced_cache;
		$this->detector       = $detector;
		$this->browser_cache  = $browser_cache;
		$this->webp           = $webp;
	}

	/**
	 * 采集全部诊断项。
	 *
	 * @return array
	 */
	public function collect() {
		return array(
			'cache'        => $this->cache_section(),
			'object_cache' => $this->object_cache_section(),
			'php'          => $this->php_section(),
			'wordpress'    => $this->wordpress_section(),
			'server'       => $this->server_section(),
			'https'        => $this->https_section(),
			'database'     => $this->database_section(),
			'conflicts'    => $this->conflict_section(),
		);
	}

	/**
	 * 页面缓存状态。
	 *
	 * @return array
	 */
	private function cache_section() {
		$backend = $this->factory->make();
		$stats   = $backend ? $backend->stats() : array(
			'count' => 0,
			'bytes' => 0,
		);

		return array(
			'label' => __( '页面缓存', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( '缓存开关', 'at8-site-accelerator' ),
					'value'  => $this->settings->is_on( 'page_cache' ) ? __( '已启用', 'at8-site-accelerator' ) : __( '已关闭', 'at8-site-accelerator' ),
					'status' => $this->settings->is_on( 'page_cache' ) ? 'good' : 'warn',
					'note'   => __( '关闭后所有页面都由 PHP 实时渲染。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '当前后端', 'at8-site-accelerator' ),
					'value'  => $backend ? $backend->name() : __( '无（未生效）', 'at8-site-accelerator' ),
					'status' => $backend ? 'good' : 'warn',
					'note'   => __( 'auto 模式下 Redis 可达时优先 Redis，否则用磁盘。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( 'advanced-cache drop-in', 'at8-site-accelerator' ),
					'value'  => $this->advanced_cache->is_installed() ? __( '已安装', 'at8-site-accelerator' ) : __( '未安装', 'at8-site-accelerator' ),
					'status' => $this->advanced_cache->is_installed() ? 'good' : 'warn',
					'note'   => __( '未安装时缓存命中仍会先启动 WordPress，命中路径的收益会打对折。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( 'WP_CACHE 常量', 'at8-site-accelerator' ),
					'value'  => $this->advanced_cache->is_wp_cache_enabled() ? 'true' : __( '未定义 / false', 'at8-site-accelerator' ),
					'status' => $this->advanced_cache->is_wp_cache_enabled() ? 'good' : 'warn',
					'note'   => __( 'drop-in 只有在 wp-config.php 里 WP_CACHE 为 true 时才会被加载。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '已缓存页面数', 'at8-site-accelerator' ),
					'value'  => number_format_i18n( (int) $stats['count'] ),
					'status' => (int) $stats['count'] > 0 ? 'good' : 'info',
					'note'   => __( '磁盘后端统计 index*.html 文件数；Redis 后端统计索引集合成员数。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '缓存占用', 'at8-site-accelerator' ),
					'value'  => size_format( (int) $stats['bytes'], 2 ),
					'status' => 'info',
					'note'   => __( '仅磁盘后端可精确统计。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '失效策略', 'at8-site-accelerator' ),
					'value'  => 'all' === $this->settings->get( 'purge_scope' ) ? __( '整站失效', 'at8-site-accelerator' ) : __( '仅相关页面', 'at8-site-accelerator' ),
					'status' => 'all' === $this->settings->get( 'purge_scope' ) ? 'warn' : 'good',
					'note'   => __( '「仅相关页面」在内容更新时只失效受影响的页面，命中率显著更高。', 'at8-site-accelerator' ),
				),
			),
		);
	}

	/**
	 * 对象缓存状态。
	 *
	 * @return array
	 */
	private function object_cache_section() {
		$has_dropin = is_file( WP_CONTENT_DIR . '/object-cache.php' );

		return array(
			'label' => __( '对象缓存', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( 'object-cache.php', 'at8-site-accelerator' ),
					'value'  => $has_dropin ? __( '已存在', 'at8-site-accelerator' ) : __( '不存在', 'at8-site-accelerator' ),
					'status' => $has_dropin ? 'good' : 'info',
					'note'   => __( '持久化对象缓存（如 Redis Object Cache）能显著减少数据库查询。本插件不提供也不接管它。', 'at8-site-accelerator' ),
				),
			),
		);
	}

	/**
	 * PHP 环境。
	 *
	 * @return array
	 */
	private function php_section() {
		$memory = $this->bytes_from_ini( ini_get( 'memory_limit' ) );

		return array(
			'label' => __( 'PHP', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( '版本', 'at8-site-accelerator' ),
					'value'  => PHP_VERSION,
					'status' => version_compare( PHP_VERSION, '7.4', '>=' ) ? 'good' : 'warn',
					'note'   => __( '本插件最低要求 PHP 7.4。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '内存上限', 'at8-site-accelerator' ),
					'value'  => $memory > 0 ? size_format( $memory, 0 ) : (string) ini_get( 'memory_limit' ),
					'status' => ( $memory >= 134217728 || $memory <= 0 ) ? 'good' : 'warn',
					'note'   => __( '低于 128M 时，大站点在清理缓存与生成缩略图时容易耗尽内存。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '最大执行时间', 'at8-site-accelerator' ),
					'value'  => (int) ini_get( 'max_execution_time' ) . 's',
					'status' => 'info',
					'note'   => __( '数据库清理是分批执行的，不受此项影响。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( 'GD WebP 支持', 'at8-site-accelerator' ),
					'value'  => $this->webp->supported() ? __( '支持', 'at8-site-accelerator' ) : __( '不支持', 'at8-site-accelerator' ),
					'status' => $this->webp->supported() ? 'good' : 'info',
					'note'   => __( '不支持时 WebP 自动转换会静默跳过，不影响其它功能。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( 'OPcache', 'at8-site-accelerator' ),
					'value'  => function_exists( 'opcache_get_status' ) ? __( '已启用', 'at8-site-accelerator' ) : __( '未启用', 'at8-site-accelerator' ),
					'status' => function_exists( 'opcache_get_status' ) ? 'good' : 'info',
					'note'   => __( 'OPcache 能让 drop-in 读取配置文件的开销降到接近零。', 'at8-site-accelerator' ),
				),
			),
		);
	}

	/**
	 * WordPress 环境。
	 *
	 * @return array
	 */
	private function wordpress_section() {
		$theme = wp_get_theme();

		return array(
			'label' => __( 'WordPress', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( '版本', 'at8-site-accelerator' ),
					'value'  => get_bloginfo( 'version' ),
					'status' => version_compare( get_bloginfo( 'version' ), '5.8', '>=' ) ? 'good' : 'warn',
					'note'   => __( '本插件最低要求 WordPress 5.8。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '当前主题', 'at8-site-accelerator' ),
					'value'  => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
					'status' => 'info',
					'note'   => __( '切换主题会自动清空整站缓存。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '站点地址', 'at8-site-accelerator' ),
					'value'  => home_url(),
					'status' => 'info',
					'note'   => __( '域名变更后需要重新生成 drop-in 配置。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '字符集', 'at8-site-accelerator' ),
					'value'  => get_bloginfo( 'charset' ),
					'status' => 'info',
					'note'   => '',
				),
			),
		);
	}

	/**
	 * 服务器环境。
	 *
	 * @return array
	 */
	private function server_section() {
		$type = $this->browser_cache->server_type();

		$labels = array(
			'nginx'     => 'nginx',
			'apache'    => 'Apache',
			'litespeed' => 'LiteSpeed',
			'unknown'   => __( '未知', 'at8-site-accelerator' ),
		);

		return array(
			'label' => __( '服务器', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( 'Web 服务器', 'at8-site-accelerator' ),
					'value'  => isset( $labels[ $type ] ) ? $labels[ $type ] : $labels['unknown'],
					'status' => 'info',
					'note'   => __( 'nginx 用户请在后台复制对应的静态资源缓存规则片段。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '缓存目录可写', 'at8-site-accelerator' ),
					'value'  => wp_is_writable( WP_CONTENT_DIR . '/cache' ) || wp_mkdir_p( WP_CONTENT_DIR . '/cache' ) ? __( '是', 'at8-site-accelerator' ) : __( '否', 'at8-site-accelerator' ),
					'status' => wp_is_writable( WP_CONTENT_DIR ) ? 'good' : 'warn',
					'note'   => __( '磁盘后端需要 wp-content/cache 可写。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '服务器软件标识', 'at8-site-accelerator' ),
					'value'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
					'status' => 'info',
					'note'   => '',
				),
			),
		);
	}

	/**
	 * HTTPS 状态。
	 *
	 * @return array
	 */
	private function https_section() {
		$is_ssl = is_ssl();

		return array(
			'label' => __( 'HTTPS', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( '当前协议', 'at8-site-accelerator' ),
					'value'  => $is_ssl ? 'HTTPS' : 'HTTP',
					'status' => $is_ssl ? 'good' : 'warn',
					'note'   => __( 'HTTPS 是浏览器缓存、HTTP/2、Service Worker 等能力的前提。', 'at8-site-accelerator' ),
				),
			),
		);
	}

	/**
	 * 数据库。
	 *
	 * @return array
	 */
	private function database_section() {
		global $wpdb;

		$size = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$size += isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0;
				$size += isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0;
			}
		}

		return array(
			'label' => __( '数据库', 'at8-site-accelerator' ),
			'items' => array(
				array(
					'label'  => __( '版本', 'at8-site-accelerator' ),
					'value'  => $wpdb->db_version(),
					'status' => 'info',
					'note'   => '',
				),
				array(
					'label'  => __( '数据总大小', 'at8-site-accelerator' ),
					'value'  => size_format( $size, 2 ),
					'status' => 'info',
					'note'   => __( '含索引。可在「数据库清理」里预览可回收的修订版与过期数据。', 'at8-site-accelerator' ),
				),
				array(
					'label'  => __( '表数量', 'at8-site-accelerator' ),
					'value'  => (string) count( (array) $wpdb->tables( 'all', false ) ),
					'status' => 'info',
					'note'   => '',
				),
			),
		);
	}

	/**
	 * 冲突检测。
	 *
	 * @return array
	 */
	private function conflict_section() {
		$conflicts = $this->detector->scan();
		$items     = array();

		if ( empty( $conflicts ) ) {
			$items[] = array(
				'label'  => __( '冲突检测', 'at8-site-accelerator' ),
				'value'  => __( '未发现已知冲突', 'at8-site-accelerator' ),
				'status' => 'good',
				'note'   => __( '仅检测已知的缓存/优化插件，不覆盖全部可能性。', 'at8-site-accelerator' ),
			);
		} else {
			foreach ( $conflicts as $conflict ) {
				$items[] = array(
					'label'  => isset( $conflict['name'] ) ? $conflict['name'] : '',
					'value'  => isset( $conflict['type'] ) ? $conflict['type'] : '',
					'status' => ( isset( $conflict['severity'] ) && 'high' === $conflict['severity'] ) ? 'warn' : 'info',
					'note'   => __( '本插件不会自动停用任何插件，请你自行决定保留哪一个整页缓存。', 'at8-site-accelerator' ),
				);
			}
		}

		return array(
			'label' => __( '兼容性', 'at8-site-accelerator' ),
			'items' => $items,
		);
	}

	/**
	 * 把 php.ini 的简写值（128M / 1G / -1）转成字节。
	 *
	 * @param string $value 值。
	 * @return int
	 */
	private function bytes_from_ini( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		$unit  = strtolower( substr( $value, -1 ) );
		$bytes = (int) $value;

		switch ( $unit ) {
			case 'g':
				$bytes *= 1024;
				// no break
			case 'm':
				$bytes *= 1024;
				// no break
			case 'k':
				$bytes *= 1024;
				break;
		}

		return $bytes;
	}
}

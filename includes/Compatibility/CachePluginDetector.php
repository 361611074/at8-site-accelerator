<?php
/**
 * 第三方缓存 / 优化插件冲突检测。
 *
 * 计划书 §68 的要求很明确：**检测 + 提示，绝不自动停用**（§104 也禁止自动停用其它插件）。
 * 因为"自动停用别人的插件"是性能插件最招人恨的行为，而且很容易把站点搞挂。
 *
 * 检测策略：不只看插件是否激活，还要判断它是否**真的在接管整页缓存**
 * （很多插件装了但功能是关的，误报会消耗用户的信任）。
 *
 * @package AT8\SiteAccelerator\Compatibility
 */

namespace AT8\SiteAccelerator\Compatibility;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class CachePluginDetector
 */
final class CachePluginDetector {

	/**
	 * 检测结果 transient 键。
	 */
	const CACHE_KEY = 'at8sa_conflict_scan';

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * 已知的整页缓存 / 优化插件清单。
	 *
	 * @return array<string, array{name:string,type:string,file:string,dropin?:string,const?:string}>
	 */
	public function known_plugins() {
		return array(
			'wp-rocket'         => array(
				'name' => 'WP Rocket',
				'type' => 'full-page-cache',
				'file' => 'wp-rocket/wp-rocket.php',
			),
			'litespeed-cache'   => array(
				'name' => 'LiteSpeed Cache',
				'type' => 'full-page-cache',
				'file' => 'litespeed-cache/litespeed-cache.php',
			),
			'w3-total-cache'    => array(
				'name' => 'W3 Total Cache',
				'type' => 'full-page-cache',
				'file' => 'w3-total-cache/w3-total-cache.php',
			),
			'wp-super-cache'    => array(
				'name'   => 'WP Super Cache',
				'type'   => 'full-page-cache',
				'file'   => 'wp-super-cache/wp-cache.php',
				'dropin' => 'advanced-cache.php',
			),
			'autoptimize'       => array(
				'name' => 'Autoptimize',
				'type' => 'asset-optimization',
				'file' => 'autoptimize/autoptimize.php',
			),
			'flying-press'      => array(
				'name' => 'FlyingPress',
				'type' => 'full-page-cache',
				'file' => 'flying-press/flying-press.php',
			),
			'perfmatters'       => array(
				'name' => 'Perfmatters',
				'type' => 'asset-optimization',
				'file' => 'perfmatters/perfmatters.php',
			),
			'cache-enabler'     => array(
				'name'   => 'Cache Enabler',
				'type'   => 'full-page-cache',
				'file'   => 'cache-enabler/cache-enabler.php',
				'dropin' => 'advanced-cache.php',
			),
			'swift-performance' => array(
				'name' => 'Swift Performance',
				'type' => 'full-page-cache',
				'file' => 'swift-performance-lite/performance.php',
			),
			'nginx-helper'      => array(
				'name' => 'Nginx Helper',
				'type' => 'full-page-cache',
				'file' => 'nginx-helper/nginx-helper.php',
			),
			'hummingbird'       => array(
				'name' => 'Hummingbird',
				'type' => 'full-page-cache',
				'file' => 'hummingbird-performance/wp-hummingbird.php',
			),
		);
	}

	/**
	 * 扫描冲突（结果缓存 1 小时）。
	 *
	 * @param bool $force 强制重新扫描。
	 * @return array 冲突列表。
	 */
	public function scan( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$conflicts = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );

		// 多站点网络激活的插件。
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		foreach ( $this->known_plugins() as $slug => $info ) {
			if ( ! in_array( $info['file'], $active, true ) ) {
				continue;
			}

			$conflicts[ $slug ] = array(
				'name'     => $info['name'],
				'type'     => $info['type'],
				'severity' => 'full-page-cache' === $info['type'] ? 'high' : 'low',
			);
		}

		// 检测是否有第三方 advanced-cache.php 占位（可能是别的缓存插件装的 drop-in）。
		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';

		if ( is_file( $dropin ) ) {
			$head = (string) @file_get_contents( $dropin, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === strpos( $head, 'AT8 Site Accelerator' ) ) {
				$conflicts['foreign-dropin'] = array(
					'name'     => __( '未知来源的 advanced-cache.php', 'at8-site-accelerator' ),
					'type'     => 'dropin',
					'severity' => 'high',
				);
			}
		}

		set_transient( self::CACHE_KEY, $conflicts, HOUR_IN_SECONDS );

		return $conflicts;
	}

	/**
	 * 清扫描缓存。
	 *
	 * @return void
	 */
	public function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * 是否存在"高风险"冲突（另一个整页缓存）。
	 *
	 * @return bool
	 */
	public function has_high_risk_conflict() {
		foreach ( $this->scan() as $conflict ) {
			if ( isset( $conflict['severity'] ) && 'high' === $conflict['severity'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 是否应当建议用户进入安全模式。
	 *
	 * @return bool
	 */
	public function suggests_safe_mode() {
		return $this->has_high_risk_conflict();
	}

	/**
	 * 生成给用户看的中性提示文案（不吓人、不推销）。
	 *
	 * @return string
	 */
	public function notice_text() {
		$conflicts = $this->scan();

		if ( empty( $conflicts ) ) {
			return '';
		}

		$names = array();

		foreach ( $conflicts as $conflict ) {
			$names[] = isset( $conflict['name'] ) ? $conflict['name'] : '';
		}

		$names = array_filter( $names );

		return sprintf(
			/* translators: %s: comma separated plugin names */
			__( '检测到其它缓存/优化系统：%s。建议避免同时启用多个整页缓存，否则可能出现「页面内容不更新」或「样式错乱」。本插件不会自动停用任何插件。', 'at8-site-accelerator' ),
			implode( '、', $names )
		);
	}
}

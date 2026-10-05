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
 * @package AT8SA\Compatibility
 */

namespace AT8SA\Compatibility;

use AT8SA\Core\Settings;

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

		/*
		 * 活跃插件列表：只读两个 option，**不加载任何 WordPress Core 文件**。
		 *
		 * 旧实现在这里 `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
		 * 以获得 `get_plugins()`，但下面从来没用过它——`get_plugins()` 读的是
		 * `plugins` option 且返回全部已安装插件（含未激活的），对"谁正在接管整页
		 * 缓存"这个问题毫无用处；而为了一个用不到的函数去 include Core 文件，
		 * 既不符合插件目录指南（插件不应直接加载 wp-admin 的 Core 文件，前台请求
		 * 上加载 admin 代码还会提前引入 `is_admin()` 相关依赖与额外内存开销），
		 * 也会在 WordPress.org 自动审核里被直接标为不符合规范。
		 *
		 * `get_option( 'active_plugins' )` 与 `get_site_option( 'active_sitewide_plugins' )`
		 * 是 Core 公开 API，插件侧无需 include 任何文件即可调用。
		 */
		$active = $this->active_plugin_files();

		foreach ( $this->known_plugins() as $slug => $info ) {
			if ( ! $this->is_active( $active, $info['file'] ) ) {
				continue;
			}

			$conflicts[ $slug ] = array(
				'name'     => $info['name'],
				'type'     => $info['type'],
				'severity' => 'full-page-cache' === $info['type'] ? 'high' : 'low',
			);
		}

		// 检测是否有第三方 advanced-cache.php 占位（可能是别的缓存插件装的 drop-in）。
		//
		// 判定与 `AdvancedCache::has_foreign_dropin()` 用同一条规则（文件头是否含
		// 本插件归属标记），避免"检测器说有冲突、插件自己却照样能装"这种自相矛盾。
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
	 * 当前处于激活状态的插件主文件路径集合（小写）。
	 *
	 * 数据来源全部是 Core 公开 option，**不加载 wp-admin 的 Core 文件**。
	 * 逐项处理了文档要求的每一个边界：
	 * - 单站：`active_plugins`（值是插件文件路径数组）；
	 * - multisite：叠加 `active_sitewide_plugins`（键是插件文件路径，值为激活时间）；
	 * - option 不存在 / 被写成非数组 → 一律降级为空数组，不产生 Warning；
	 * - 统一小写后比较 → 规避文件系统大小写敏感差异。
	 *
	 * @return string[]
	 */
	private function active_plugin_files() {
		$active = get_option( 'active_plugins', array() );

		// option 可能被第三方插件写坏（存成字符串 / 对象），必须做类型兜底。
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $network ) ) {
				// 网络激活表是 `文件路径 => 激活时间`，取键即可。
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$files = array();

		foreach ( $active as $file ) {
			if ( is_string( $file ) && '' !== trim( $file ) ) {
				$files[] = strtolower( trim( $file ) );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * 某个插件文件是否在激活集合里。
	 *
	 * @param string[] $active 已激活插件文件（小写）。
	 * @param string   $file   待检测的插件文件路径。
	 * @return bool
	 */
	private function is_active( array $active, $file ) {
		return in_array( strtolower( (string) $file ), $active, true );
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
	 * 已经开着安全模式就不再建议——重复提示只会让用户怀疑开关没生效。
	 *
	 * @return bool
	 */
	public function suggests_safe_mode() {
		if ( $this->settings->is_on( 'safe_mode' ) ) {
			return false;
		}

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

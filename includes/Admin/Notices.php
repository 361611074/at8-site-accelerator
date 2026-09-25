<?php
/**
 * 后台提示。
 *
 * 提示策略：**只在需要用户做决定的时候出现**。
 * 不做"感谢安装本插件"这种噪声，也不做长期驻留的推广横幅。
 * 每条提示都必须是可执行的（告诉用户下一步点哪里），否则就不该存在。
 *
 * @package AT8\SiteAccelerator\Admin
 */

namespace AT8\SiteAccelerator\Admin;

use AT8\SiteAccelerator\Cache\AdvancedCache;
use AT8\SiteAccelerator\Compatibility\CachePluginDetector;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Optimization\BrowserCache;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notices
 */
final class Notices {

	/**
	 * 依赖。
	 *
	 * @var array<string, object>
	 */
	private $deps;

	/**
	 * 构造。
	 *
	 * @param array $deps 依赖。
	 */
	public function __construct( array $deps ) {
		$this->deps = $deps;
	}

	/**
	 * 取依赖。
	 *
	 * @param string $key 键。
	 * @return mixed
	 */
	private function dep( $key ) {
		return isset( $this->deps[ $key ] ) ? $this->deps[ $key ] : null;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * 输出提示。
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// 设置页自身不再重复显示（页面里已经有状态区）。
		$is_own_page = $screen && 'toplevel_page_' . SettingsPage::SLUG === $screen->id;

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );

		if ( $settings->was_migrated() && ! $is_own_page ) {
			$this->notice(
				'success',
				sprintf(
					/* translators: %s: plugin admin page URL */
					__( 'AT8 Site Accelerator 已从 2.x 升级并自动迁移设置。原有开关保持不变，旧的 %s 选项仍保留以便回滚。', 'at8-site-accelerator' ),
					'<code>site_accelerator_settings</code>'
				)
			);
		}

		if ( $is_own_page ) {
			return;
		}

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( $settings->is_on( 'page_cache' ) && $settings->is_on( 'advanced_cache' ) ) {
			if ( ! $dropin->is_wp_cache_enabled() ) {
				$this->notice(
					'warning',
					sprintf(
						/* translators: %s: settings page URL */
						__( 'AT8 Site Accelerator：<code>wp-config.php</code> 中的 <code>WP_CACHE</code> 未启用，高级缓存 drop-in 不会生效。可到 %s 一键启用（会自动备份 wp-config.php）。', 'at8-site-accelerator' ),
						$this->link()
					)
				);
			} elseif ( ! $dropin->is_installed() ) {
				$this->notice(
					'warning',
					sprintf(
						/* translators: %s: settings page URL */
						__( 'AT8 Site Accelerator：<code>advanced-cache.php</code> 尚未安装，缓存命中仍会先启动 WordPress。可到 %s 安装。', 'at8-site-accelerator' ),
						$this->link()
					)
				);
			}
		}

		/** @var CachePluginDetector $detector */
		$detector = $this->dep( 'detector' );
		$text     = $detector->notice_text();

		if ( '' !== $text && $detector->has_high_risk_conflict() ) {
			$this->notice( 'warning', esc_html( $text ) . ' ' . $this->link( __( '查看详情', 'at8-site-accelerator' ) ) );
		}

		/** @var BrowserCache $browser_cache */
		$browser_cache = $this->dep( 'browser_cache' );

		if ( $browser_cache->has_risky_combination() ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: settings page URL */
					__( 'AT8 Site Accelerator：你同时开启了「移除静态资源 ?ver= 版本号」与「静态资源长缓存」。这会导致主题/插件更新后访客仍使用旧的 CSS/JS。建议关闭其中一项，详见 %s。', 'at8-site-accelerator' ),
					$this->link( __( '兼容与诊断', 'at8-site-accelerator' ) )
				)
			);
		}
	}

	/**
	 * 输出单条提示。
	 *
	 * @param string $type    success|warning|error|info。
	 * @param string $message 消息（允许有限 HTML，调用方需自行转义动态部分）。
	 * @return void
	 */
	private function notice( $type, $message ) {
		$allowed = array(
			'code' => array(),
			'a'    => array( 'href' => array() ),
			'br'   => array(),
		);

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses( $message, $allowed )
		);
	}

	/**
	 * 设置页链接。
	 *
	 * @param string $label 链接文字。
	 * @return string
	 */
	private function link( $label = '' ) {
		if ( '' === $label ) {
			$label = __( '前往设置', 'at8-site-accelerator' );
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html( $label )
		);
	}
}

<?php
/**
 * 后台提示。
 *
 * 提示策略：**只在需要用户做决定的时候出现**。
 * 不做"感谢安装本插件"这种噪声，也不做长期驻留的推广横幅。
 * 每条提示都必须是可执行的（告诉用户下一步点哪里），否则就不该存在。
 *
 * 两条硬约束（插件目录指南 11：不得劫持后台）：
 * 1. **只在真正需要用户处理时才出现，且问题解决后自动消失** —— 本文件里每条
 *    提示都挂在某个可判定的状态上（`WP_CACHE` 没开 / drop-in 没装 / 检测到冲突），
 *    状态一变提示就没了。不做需要"手动关闭"的提示，因为那反而会把真问题关掉。
 * 2. **纯信息类提示不放这里** —— 例如"2.x 设置已迁移"，它永远不会有"已解决"的状态，
 *    放在全站通知里就是一条永远不消失的提示。它已经在设置页的状态区里
 *    （见 `templates/settings-page.php`），只在用户主动打开插件页面时出现。
 *
 * @package AT8SA\Admin
 */

namespace AT8SA\Admin;

use AT8SA\Cache\AdvancedCache;
use AT8SA\Compatibility\CachePluginDetector;
use AT8SA\Core\Settings;
use AT8SA\Optimization\BrowserCache;

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
	 * 当前 screen 是否是本插件允许显示提示的页面。
	 *
	 * 插件目录指南 11 明确禁止「劫持后台」：不得在**别的**页面顶部插自己的横幅。
	 * `admin_notices` 是全局钩子，挂上去就意味着「全站每一个后台页面」都会执行到，
	 * 所以必须用**白名单**而不是「排除法」——白名单里没列到的 screen 一律不显示。
	 *
	 * 为什么保留 `admin_notices` 而不是彻底改成设置页内联：
	 * 「drop-in 被别的缓存插件占着」这类问题如果只在设置页显示，管理员在
	 * 插件列表页点「停用」时会以为是自己操作弄坏的，缺少一个全局的出口。
	 * 所以保留钩子，但把可见范围收到插件自己的两个页面。
	 *
	 * @return bool
	 */
	private function is_allowed_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// 拿不到 screen（例如某些 AJAX / REST 上下文）时一律不显示。
		// `WP_Screen::$id` 是必填属性，`get_current_screen()` 返回的对象一定有值，
		// 所以只需判空对象本身。
		if ( ! $screen ) {
			return false;
		}

		$allowed = array(
			// 插件自己的设置页。
			'toplevel_page_' . SettingsPage::SLUG,
			// 插件列表页：让"drop-in 装不上"这类问题在用户最可能看到的地方有出口。
			'plugins',
			// 仪表盘：只显示这一类必须用户处理的问题。
			'dashboard',
		);

		return in_array( $screen->id, $allowed, true );
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

		// 屏幕白名单：不在自己的页面里，一概不输出。
		if ( ! $this->is_allowed_screen() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// 设置页自身不再重复显示（页面里已经有状态区）。
		$is_own_page = $screen && 'toplevel_page_' . SettingsPage::SLUG === $screen->id;

		if ( $is_own_page ) {
			return;
		}

		/** @var Settings $settings */
		$settings = $this->dep( 'settings' );

		/** @var AdvancedCache $dropin */
		$dropin = $this->dep( 'advanced_cache' );

		if ( $settings->is_on( 'page_cache' ) && $settings->is_on( 'advanced_cache' ) ) {
			/*
			 * 归属冲突优先于"未安装"。
			 *
			 * 槽位被别的缓存系统占用时，插件**主动让路**（不覆盖别人的文件），
			 * 结果就是 drop-in 始终装不上。如果只报"尚未安装"，管理员会反复点
			 * "安装"却永远失败，而真正的原因（另一个插件占着这个位置）没被说出来。
			 * 这里把冲突原因直接讲清楚，并给出下一步。
			 */
			$blocked = $dropin->blocked_reason();

			if ( '' !== $blocked ) {
				$this->notice( 'warning', $blocked . ' ' . $this->link( __( '查看详情', 'at8-site-accelerator' ) ) );
			} elseif ( ! $dropin->is_wp_cache_enabled() ) {
				$this->notice(
					'warning',
					sprintf(
						/* translators: %s: settings page URL */
						__( 'AT8 Site Accelerator：<code>wp-config.php</code> 中的 <code>WP_CACHE</code> 未启用，高级缓存 drop-in 不会生效。可到 %s 一键启用（写入前临时备份，校验通过后立即删除）。', 'at8-site-accelerator' ),
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

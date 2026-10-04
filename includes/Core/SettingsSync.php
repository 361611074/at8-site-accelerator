<?php
/**
 * 设置变更 → 运行时同步。
 *
 * 为什么必须独立成一个服务（真机实测到的缺陷）：
 * 这条链路的三个动作——刷新设置缓存、重写 drop-in 运行时配置、清理缓存——
 * 原先挂在 `SettingsPage::boot()` 里，而 `SettingsPage` 只在 `is_admin()` 为真时
 * 才被 boot。于是 **非 wp-admin 上下文里改设置完全不生效**：
 *
 *   wp option update at8sa_settings --format=json ...   # WP-CLI
 *   update_option( 'at8sa_settings', $x )               # 其它插件 / 主题 / 迁移脚本
 *   WP-Cron / 外部 REST 客户端
 *
 * 实测现象：连改 6 次 `cache_backend`（disk/redis/auto 轮换），
 * `cache/at8-site-accelerator/config/<host>.php` 里的 `'backend'` 始终是旧值，
 * 前台响应头也一直走旧后端。用户看到的就是"设置保存了但没生效"。
 *
 * 因此这里把监听器挪到 `boot_shared()`（前后台 + CLI + Cron 都会执行），
 * 让"改了设置就一定同步"成为一条与上下文无关的不变式。
 *
 * @package AT8SA\Core
 */

namespace AT8SA\Core;

use AT8SA\Cache\AdvancedCache;
use AT8SA\Cache\Backend\BackendFactory;
use AT8SA\Cache\Config;
use AT8SA\Purge\Purger;

defined( 'ABSPATH' ) || exit;

/**
 * Class SettingsSync
 */
final class SettingsSync {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 后端工厂（缓存探测结果需随设置作废）。
	 *
	 * @var BackendFactory
	 */
	private $factory;

	/**
	 * 运行时配置构建器。
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * drop-in 安装器。
	 *
	 * @var AdvancedCache
	 */
	private $dropin;

	/**
	 * 缓存失效器。
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * 构造。
	 *
	 * @param Settings       $settings 设置。
	 * @param BackendFactory $factory  后端工厂。
	 * @param Config         $config   配置构建器。
	 * @param AdvancedCache  $dropin   drop-in 安装器。
	 * @param Purger         $purger   失效器。
	 */
	public function __construct(
		Settings $settings,
		BackendFactory $factory,
		Config $config,
		AdvancedCache $dropin,
		Purger $purger
	) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->config   = $config;
		$this->dropin   = $dropin;
		$this->purger   = $purger;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * 设置被写入后同步运行时。
	 *
	 * 入参来自 `update_option_{$option}` 动作，本方法一律从**已刷新的**设置对象
	 * 重新取值，因此不消费这两个参数（显式 unset 以表明是有意为之，不是漏用）。
	 *
	 * @param mixed $old_value 旧值。
	 * @param mixed $value     新值。
	 * @return void
	 */
	public function on_settings_updated( $old_value, $value ) {
		unset( $old_value, $value );

		$this->sync();
	}

	/**
	 * 执行同步（幂等）。
	 *
	 * 顺序不能调换：
	 * 1. 先丢设置缓存，否则后面读到的还是旧值；
	 * 2. 再丢 Redis 探测结果，因为 `cache_backend` 可能刚改过；
	 * 3. 然后才生成配置，此时读到的才是新值。
	 *
	 * @return void
	 */
	public function sync() {
		$this->settings->flush_cache();
		$this->factory->reset_probe();

		$this->config->write( $this->config->runtime() );

		if ( $this->settings->is_on( 'advanced_cache' ) ) {
			$this->dropin->install();
		}

		// 配置变了（尤其换了后端）后旧条目可能落在另一个存储里，必须清干净。
		$this->purger->purge_all();
	}
}

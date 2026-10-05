<?php
/**
 * 设置存储：默认值、读取、校验、以及 2.x → 3.0 的幂等迁移。
 *
 * 设计要点（计划书 §104 禁止自动改 WordPress Core；§71 要求 Sanitization）：
 * - 新选项名 `at8sa_settings`，旧选项 `site_accelerator_settings` 原样保留不删，
 *   以便用户回滚到 2.x 时设置仍在。
 * - 键名与 2.x 保持同名，迁移即"整体拷贝 + 补新键"，避免用户升级后行为漂移。
 * - 迁移是幂等的：只有旧选项存在、且新选项尚未标记已迁移时才执行。
 *
 * @package AT8SA\Core
 */

namespace AT8SA\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
final class Settings {

	/**
	 * 当前选项名。
	 */
	const OPTION = 'at8sa_settings';

	/**
	 * 2.x 选项名（只读，用于迁移）。
	 */
	const LEGACY_OPTION = 'site_accelerator_settings';

	/**
	 * 迁移完成标记：**只在真的迁移了 2.x 数据时才置位**。
	 *
	 * 消费方是设置页与后台提示 —— 它们据此告诉用户"已从 2.x 升级并自动迁移"。
	 * 全新安装绝不能置位，否则这句话就是假的。
	 */
	const MIGRATED_FLAG = 'at8sa_migrated_from_legacy';

	/**
	 * "已检查过 2.x 数据"标记。
	 *
	 * 与 `MIGRATED_FLAG` **必须分开**，两者语义不同：
	 * - "查过了、别再查" —— 防重复执行。迁移不能跑第二次，因为旧选项按设计保留
	 *   （供用户回滚），重复迁移会用旧值覆盖用户当前设置；
	 * - "真的迁移过了" —— 决定要不要告诉用户。
	 * 共用一个标志就会出现"没旧数据也置位"→ 全新安装显示迁移成功提示的问题。
	 */
	const LEGACY_CHECKED_FLAG = 'at8sa_legacy_checked';

	/**
	 * 设置页 option group。
	 */
	const GROUP = 'at8sa_settings_group';

	/**
	 * 运行期缓存的全量设置。
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * 默认值。
	 *
	 * 新增键一律放这里，而不是散落在各模块的 `get()` 兜底里，
	 * 这样后台设置页、REST、缓存配置能共用同一份真相。
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			/* --- ① 页面缓存 --- */
			'page_cache'             => 1,
			'advanced_cache'         => 1,
			'cache_backend'          => 'auto',
			'cache_ttl'              => 3600,
			// 'cache_logged_in' 于 3.0.5 移除，登录用户一律不进公共缓存。
			'cache_mobile'           => 1,
			'exclude_urls'           => '',
			'bypass_cookies'         => '',
			'ignore_query'           => '',

			/* --- ② 失效策略 --- */
			'purge_scope'            => 'related',
			'purge_home_on_save'     => 1,

			/* --- ③ 智能预加载（前端链接预取） --- */
			'preload_enable'         => 1,
			'hover_delay'            => 50,
			'touch_delay'            => 100,
			'preload_viewport'       => 1,
			'preload_strategy'       => array( 'prefetch', 'preconnect', 'dns-prefetch' ),
			'max_preloads'           => 20,
			'max_per_domain'         => 10,
			'preload_cooldown'       => 300,
			'dns_prefetch'           => 1,
			'preconnect_hosts'       => "fonts.googleapis.com\nfonts.gstatic.com",
			'resource_preload'       => 0,
			'preload_debug'          => 0,

			/* --- ④ 浏览器缓存 --- */
			'browser_cache'          => 1,
			'browser_cache_ttl'      => 31536000,
			'browser_cache_html'     => 0,
			'browser_cache_html_ttl' => 3600,

			/* --- ⑤ HTML 压缩 --- */
			'html_minify'            => 1,
			'html_minify_inline'     => 0,

			/* --- ⑥ 图片懒加载 --- */
			'lazyload'               => 1,
			'lazyload_iframes'       => 1,
			'lazyload_skip_first'    => 1,
			'lazyload_exclude'       => '',

			/* --- ⑦ 前端资源精简 --- */
			'disable_emoji'          => 1,
			'disable_embeds'         => 1,
			'remove_wp_generator'    => 1,
			'disable_jquery_migrate' => 0,
			'disable_dashicons'      => 1,
			'remove_query_strings'   => 1,
			'heartbeat'              => 'reduce',
			'disable_block_css'      => 0,

			/* --- ⑧ 图片 --- */
			'webp_convert'           => 1,

			/* --- ⑨ 数据库清理（默认全关：清理是破坏性操作，必须用户显式勾选） --- */
			'db_revisions'           => 0,
			'db_auto_drafts'         => 0,
			'db_trashed_posts'       => 0,
			'db_spam_comments'       => 0,
			'db_trashed_comments'    => 0,
			'db_transients'          => 0,
			'db_optimize'            => 0,
			'db_schedule'            => 'off',

			/* --- ⑩ 后台精简 --- */
			'remove_site_health'     => 1,
			'remove_events_news'     => 1,
			'disable_version_checks' => 1,
			'disable_large_thumbs'   => 1,

			/* --- ⑪ 诊断与安全 --- */
			'log_enabled'            => 0,
			'log_level'              => 'error',
			'safe_mode'              => 0,
			'keep_data_on_uninstall' => 1,
		);
	}

	/**
	 * 需要按整数 0/1 处理的布尔键。
	 *
	 * @return array
	 */
	public function boolean_keys() {
		return array(
			'page_cache',
			'advanced_cache',
			// 'cache_logged_in' 已在 3.0.5 移除（缓存键无用户维度，登录态共享会越权）。
			// 从白名单里去掉后，`sanitize()` 重建数组时不会再写这个键，
			// 用户下次保存设置即完成历史数据清理。
			'cache_mobile',
			'purge_home_on_save',
			'preload_enable',
			'preload_viewport',
			'dns_prefetch',
			'resource_preload',
			'preload_debug',
			'browser_cache',
			'browser_cache_html',
			'html_minify',
			'html_minify_inline',
			'lazyload',
			'lazyload_iframes',
			'lazyload_skip_first',
			'disable_emoji',
			'disable_embeds',
			'remove_wp_generator',
			'disable_jquery_migrate',
			'disable_dashicons',
			'remove_query_strings',
			'disable_block_css',
			'webp_convert',
			'db_revisions',
			'db_auto_drafts',
			'db_trashed_posts',
			'db_spam_comments',
			'db_trashed_comments',
			'db_transients',
			'db_optimize',
			'remove_site_health',
			'remove_events_news',
			'disable_version_checks',
			'disable_large_thumbs',
			'log_enabled',
			'safe_mode',
			'keep_data_on_uninstall',
		);
	}

	/**
	 * 读取全量设置（带默认值补齐）。
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = array_merge( $this->defaults(), $stored );

		return $this->cache;
	}

	/**
	 * 读取单项设置。
	 *
	 * @param string $key      键名。
	 * @param mixed  $fallback 键不存在时的回退值。
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * 判断布尔开关是否开启。
	 *
	 * @param string $key 键名。
	 * @return bool
	 */
	public function is_on( $key ) {
		return ! empty( $this->get( $key ) );
	}

	/**
	 * 保存设置（已经过 sanitize）。
	 *
	 * @param array $sanitized 已清洗的全量设置。
	 * @return void
	 */
	public function persist( array $sanitized ) {
		update_option( self::OPTION, $sanitized );
		$this->cache = $sanitized;
	}

	/**
	 * 刷新运行期缓存（外部直接写库后调用）。
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * 清洗输入（计划书 §71 Sanitization / §124 所有输入必须 sanitize）。
	 *
	 * **缺键 ≠ 关**。这是本方法唯一需要解释的规则：
	 * 表单里的 checkbox 未勾选时键会缺失，因此设置页在每个开关前都补了一个
	 * `value="0"` 的 hidden 字段（见 templates/settings-page.php），未勾选时提交的就是 0。
	 * 于是"键缺失"只可能来自**局部更新**（REST / 导入设置 / 第三方代码）。
	 * 对局部更新，把缺键一律当成 0 等于悄悄关掉用户没碰过的功能——
	 * 这类"保存一个 TTL 就顺手把整站缓存关了"的 bug 极难排查，
	 * 所以缺键一律**保留当前已存值**（从未保存过则用默认值）。
	 *
	 * @param mixed $input 原始表单输入。
	 * @return array 完整的、可直接落库的设置数组。
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->all();
		$out     = array();

		foreach ( $this->boolean_keys() as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$out[ $key ] = empty( $current[ $key ] ) ? 0 : 1;
				continue;
			}

			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['cache_backend'] = $this->enum( $input, 'cache_backend', array( 'auto', 'disk', 'redis' ), 'auto' );
		$out['purge_scope']   = $this->enum( $input, 'purge_scope', array( 'related', 'all' ), 'related' );
		$out['heartbeat']     = $this->enum( $input, 'heartbeat', array( 'default', 'reduce', 'disable' ), 'reduce' );
		$out['db_schedule']   = $this->enum( $input, 'db_schedule', array( 'off', 'daily', 'weekly' ), 'off' );
		$out['log_level']     = $this->enum( $input, 'log_level', array( 'error', 'warning', 'info', 'debug' ), 'error' );

		$out['cache_ttl']              = $this->clamp_int( $input, 'cache_ttl', 60, 86400 * 30, 3600 );
		$out['browser_cache_ttl']      = $this->clamp_int( $input, 'browser_cache_ttl', 3600, 31536000, 31536000 );
		$out['browser_cache_html_ttl'] = $this->clamp_int( $input, 'browser_cache_html_ttl', 0, 86400 * 30, 3600 );
		$out['hover_delay']            = $this->clamp_int( $input, 'hover_delay', 0, 2000, 50 );
		$out['touch_delay']            = $this->clamp_int( $input, 'touch_delay', 0, 2000, 100 );
		$out['max_preloads']           = $this->clamp_int( $input, 'max_preloads', 1, 200, 20 );
		$out['max_per_domain']         = $this->clamp_int( $input, 'max_per_domain', 1, 100, 10 );
		$out['preload_cooldown']       = $this->clamp_int( $input, 'preload_cooldown', 0, 86400, 300 );

		if ( array_key_exists( 'preload_strategy', $input ) ) {
			$strategy = is_array( $input['preload_strategy'] ) ? $input['preload_strategy'] : array();
			$strategy = array_values( array_intersect( $strategy, array( 'prefetch', 'preconnect', 'dns-prefetch', 'prerender' ) ) );

			if ( empty( $strategy ) ) {
				$strategy = array( 'prefetch', 'preconnect', 'dns-prefetch' );
			}
		} else {
			$strategy = (array) $this->current_value( 'preload_strategy', array( 'prefetch', 'preconnect', 'dns-prefetch' ) );
		}

		$out['preload_strategy'] = array_values( $strategy );

		$out['exclude_urls']     = $this->lines( $input, 'exclude_urls' );
		$out['bypass_cookies']   = $this->lines( $input, 'bypass_cookies' );
		$out['ignore_query']     = $this->lines( $input, 'ignore_query' );
		$out['preconnect_hosts'] = $this->lines( $input, 'preconnect_hosts' );
		$out['lazyload_exclude'] = $this->lines( $input, 'lazyload_exclude' );

		return array_merge( $this->defaults(), $out );
	}

	/**
	 * 枚举取值。键缺失时保留当前已存值（见 sanitize() 的说明）。
	 *
	 * @param array  $input    输入。
	 * @param string $key      键名。
	 * @param array  $allowed  允许值。
	 * @param string $fallback 回退值。
	 * @return string
	 */
	private function enum( $input, $key, array $allowed, $fallback ) {
		if ( ! array_key_exists( $key, $input ) ) {
			$current = $this->current_value( $key, $fallback );

			return in_array( $current, $allowed, true ) ? $current : $fallback;
		}

		$value = $input[ $key ];

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * 整数区间钳制。键缺失时保留当前已存值（见 sanitize() 的说明）。
	 *
	 * @param array  $input    输入。
	 * @param string $key      键名。
	 * @param int    $min      下界。
	 * @param int    $max      上界。
	 * @param int    $fallback 回退值。
	 * @return int
	 */
	private function clamp_int( $input, $key, $min, $max, $fallback ) {
		if ( ! array_key_exists( $key, $input ) ) {
			$current = (int) $this->current_value( $key, $fallback );

			return max( $min, min( $max, $current ) );
		}

		if ( '' === $input[ $key ] ) {
			return $fallback;
		}

		$value = (int) $input[ $key ];

		return max( $min, min( $max, $value ) );
	}

	/**
	 * 多行/逗号分隔文本 → 规范化后的单行文本（保留换行，便于 textarea 回显）。
	 * 键缺失时保留当前已存值（见 sanitize() 的说明）。
	 *
	 * @param array  $input 输入。
	 * @param string $key   键名。
	 * @return string
	 */
	private function lines( $input, $key ) {
		if ( ! array_key_exists( $key, $input ) ) {
			$current = $this->current_value( $key, '' );

			return is_array( $current ) ? implode( "\n", $current ) : (string) $current;
		}

		$raw = sanitize_textarea_field( (string) $input[ $key ] );

		return trim( $raw );
	}

	/**
	 * 读取当前已存值（缺省回退）。
	 *
	 * @param string $key      键名。
	 * @param mixed  $fallback 回退值。
	 * @return mixed
	 */
	private function current_value( $key, $fallback ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/*
	---------------------------------------------------------------------
	 * 迁移
	 * ------------------------------------------------------------------
	 */

	/**
	 * 2.x → 3.0 设置迁移。幂等，可在每次升级时安全调用。
	 *
	 * 绝大多数旧键与新键同名，直接覆盖即可；只有下面这张映射表里的少数几个
	 * 在 3.0 里改了名字（改名是为了让开关的**名字说人话**，见注释）。
	 * 用户从未用过的键保持 3.0 默认值。
	 *
	 * **返回值只表示"这次真的迁移了 2.x 的数据"**，调用方据此决定要不要告诉用户
	 * "已从 2.x 升级"。全新安装返回 false，且不会留下任何"迁移过"的痕迹。
	 * 附带自愈：撤回 3.0.1 在"没有旧数据"时误置的迁移标记（见方法内注释）。
	 *
	 * @return bool 是否实际执行了迁移。
	 */
	public function migrate_from_legacy() {
		/*
		 * 两个标志都查：
		 * - LEGACY_CHECKED_FLAG：本次逻辑"已经查过"，避免重复走这一段；
		 * - MIGRATED_FLAG：旧版本（3.0.2 之前）留下的"查过"痕迹 —— 那些站点上
		 *   它可能是"没旧数据"时误置的，但既然置了就说明已经检查过，
		 *   这里必须继续认它，否则会二次迁移、用旧值覆盖用户当前设置。
		 */
		$flagged_migrated = get_option( self::MIGRATED_FLAG );

		if ( get_option( self::LEGACY_CHECKED_FLAG ) || $flagged_migrated ) {
			// 自愈：3.0.2 之前，"没有 2.x 数据"的全新安装也会置 MIGRATED_FLAG。
			// 那些站点上这句话是假的（设置页会显示"检测到 2.x 的旧设置，已自动迁移"），
			// 而这条提示按设计不会自己消失 —— 违反插件目录指南 11。
			//
			// 判据：标记在、但旧选项**根本不存在** ⇒ 当时什么都没迁移，撤回标记。
			// 为什么撤回是安全的：旧选项不存在时迁移本身就是空操作，不会引发二次搬运；
			// 反过来说，只要旧选项还在，就一律不动标记（真迁移过的站点必须继续认它）。
			if ( $flagged_migrated && ! is_array( get_option( self::LEGACY_OPTION, null ) ) ) {
				delete_option( self::MIGRATED_FLAG );
				update_option( self::LEGACY_CHECKED_FLAG, AT8SA_VERSION, false );

				return false;
			}

			return false;
		}

		// 先落"已检查"标记：无论有没有旧数据，这段都不再重复执行。
		update_option( self::LEGACY_CHECKED_FLAG, AT8SA_VERSION, false );

		$legacy = get_option( self::LEGACY_OPTION, null );

		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			// 全新安装：什么都不做，**也绝不置 MIGRATED_FLAG**。
			// 置了的话设置页会显示"检测到 2.x 的旧设置，已自动迁移"——对一个刚装上的
			// 站点是假话，而且是一条永远不消失的提示（违反插件目录指南 11）。
			return false;
		}

		$defaults = $this->defaults();
		$merged   = $defaults;

		foreach ( array_keys( $defaults ) as $key ) {
			if ( array_key_exists( $key, $legacy ) ) {
				$merged[ $key ] = $legacy[ $key ];
			}
		}

		// 改名键映射。表里必须写清"为什么改名"，否则后人会以为是笔误。
		$renamed = array(
			// 2.x 叫 http2_push，但它的实现是 `Link: <url>; rel=preload`，
			// 属于**资源预加载**而不是 HTTP/2 Server Push（后者已被主流浏览器移除）。
			// 名字不改，用户会以为自己开了个已经失效的功能；所以改名为 resource_preload。
			'http2_push' => 'resource_preload',
		);

		foreach ( $renamed as $old_key => $new_key ) {
			if ( array_key_exists( $old_key, $legacy ) && ! array_key_exists( $new_key, $legacy ) ) {
				$merged[ $new_key ] = $legacy[ $old_key ];
			}
		}

		// 旧版把预加载策略存在同一个键里，结构一致，但需重新校验一次。
		$merged = $this->sanitize( $merged );

		update_option( self::OPTION, $merged );
		update_option( self::MIGRATED_FLAG, AT8SA_VERSION, false );
		$this->flush_cache();

		return true;
	}

	/**
	 * 是否发生过 2.x 迁移。
	 *
	 * @return bool
	 */
	public function was_migrated() {
		return (bool) get_option( self::MIGRATED_FLAG );
	}
}

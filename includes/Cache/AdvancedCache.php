<?php
/**
 * advanced-cache.php drop-in 的安装 / 卸载，以及 wp-config.php 中 WP_CACHE 的安全开关。
 *
 * 计划书 §104 明令"禁止自动修改 WordPress Core"。这里只动 `wp-content/advanced-cache.php`
 * 与 `wp-config.php` 两个**用户配置文件**，且：
 * - 改 wp-config.php 前强制做**临时**备份，校验结束后立即删除（不留在磁盘上）；
 * - 改完做完整性校验（文件非空、仍含 DB_NAME、仍含 wp-settings.php 引入），任一不满足立即回滚；
 * - 只增删带专属标记的那一行，绝不重写整文件；
 * - 卸载时只删自己写的那一行，恢复原状。
 *
 * @package AT8SA\Cache
 */

namespace AT8SA\Cache;

use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdvancedCache
 */
final class AdvancedCache {

	/**
	 * wp-config.php 中我们插入的行的标记。
	 */
	const WP_CACHE_MARKER = '// Added by AT8 Site Accelerator';

	/**
	 * drop-in 文件中的归属标记。
	 */
	const DROPIN_MARKER = 'AT8 Site Accelerator —— advanced-cache.php drop-in';

	/**
	 * drop-in 模板 / 已安装文件中的版本戳字段。
	 *
	 * 写成 docblock 里的自定义标签，而不是 define 或变量：drop-in 是纯执行文件，
	 * 多一个"没人用"的常量/变量会误导后来者，而 docblock 标签既不会被执行，
	 * 又能被稳定地正则读回。
	 */
	const VERSION_TAG = '@at8sa-dropin-version';

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 构造。
	 *
	 * @param Logger $logger 日志。
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * drop-in 目标路径。
	 *
	 * @return string
	 */
	public function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * 模板路径。
	 *
	 * @return string
	 */
	private function template_path() {
		return AT8SA_PATH . 'templates/advanced-cache.php';
	}

	/**
	 * 已安装的 drop-in 是否属于本插件。
	 *
	 * @return bool
	 */
	public function is_installed() {
		return '' !== $this->dropin_head();
	}

	/**
	 * 目标路径上是否存在**不属于本插件**的 drop-in。
	 *
	 * 这是"绝不能覆盖别人的文件"这条铁律的判断入口。返回 true 表示：
	 * `wp-content/advanced-cache.php` 已经存在，且归属标记不是本插件的——
	 * 极可能是 WP Super Cache / W3 Total Cache / LiteSpeed Cache / 主机环境
	 * 装上去的。此时插件**必须**放弃写入，把决定权交还管理员。
	 *
	 * @return bool
	 */
	public function has_foreign_dropin() {
		$path = $this->dropin_path();

		// 文件不存在 → 没有归属冲突，可以安装。
		if ( ! is_file( $path ) ) {
			return false;
		}

		// 文件是我们自己的 → 属于升级覆盖，不算冲突。
		return '' === $this->dropin_head();
	}

	/**
	 * drop-in 安装被拒绝的原因（可展示给管理员）。
	 *
	 * @return string 空串代表没有阻塞原因。
	 */
	public function blocked_reason() {
		if ( ! $this->has_foreign_dropin() ) {
			return '';
		}

		return __( '检测到 <code>wp-content/advanced-cache.php</code> 已存在且不属于本插件，为避免破坏其它缓存系统已跳过安装。请先停用其它缓存插件，或手动移走该文件后再安装。', 'at8-site-accelerator' );
	}

	/**
	 * 读取已安装 drop-in 的文件头（仅当它属于本插件时）。
	 *
	 * 返回空串有两种含义，都必须与"是本插件的 drop-in"区分开：
	 * - 文件不存在；
	 * - 文件存在但不是我们的 —— 这时**绝不能覆盖**，它可能是别的缓存插件的文件。
	 *
	 * @return string
	 */
	private function dropin_head() {
		$path = $this->dropin_path();

		if ( ! is_file( $path ) ) {
			return '';
		}

		$head = (string) @file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === strpos( $head, 'AT8 Site Accelerator' ) ) {
			return '';
		}

		return $head;
	}

	/**
	 * 安装 drop-in（幂等 + 归属保护）。
	 *
	 * **绝不覆盖别人的文件**：`wp-content/advanced-cache.php` 是全站唯一的
	 * 整页缓存 drop-in 槽位，可能已被 WP Super Cache / W3 Total Cache /
	 * LiteSpeed Cache / 主机环境占用。旧实现在这里无条件 `file_put_contents()`，
	 * 意味着"用户一激活本插件，别人的缓存就被静默顶掉"——这既会弄坏站点，
	 * 也正是 WordPress.org 审核明确点出的问题。
	 *
	 * 现在的判定顺序：
	 * 1. 文件不存在 → 允许安装；
	 * 2. 文件存在且归属标记是本插件 → 允许覆盖（这是升级 / 自愈路径，必须能覆盖）；
	 * 3. 文件存在但**不属于**本插件 → 拒绝，记 warning，返回 false。
	 *
	 * @return bool
	 */
	public function install() {
		if ( $this->has_foreign_dropin() ) {
			$this->logger->warning(
				'advanced-cache.php 已被其它系统占用，跳过安装（不覆盖）',
				array( 'path' => $this->dropin_path() )
			);

			return false;
		}

		$template = $this->template_path();

		if ( ! is_readable( $template ) ) {
			$this->logger->error( 'advanced-cache 模板不可读', array( 'path' => $template ) );

			return false;
		}

		$content = (string) file_get_contents( $template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		$content = str_replace(
			array( '{{AT8SA_PATH}}', '{{AT8SA_VERSION}}' ),
			array( trailingslashit( wp_normalize_path( AT8SA_PATH ) ), AT8SA_VERSION ),
			$content
		);

		$target = $this->dropin_path();

		if ( ! wp_is_writable( dirname( $target ) ) ) {
			$this->logger->error( 'wp-content 不可写，无法安装 advanced-cache.php' );

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== @file_put_contents( $target, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * 已安装 drop-in 的版本戳。
	 *
	 * @return string 读不到时返回空串。
	 */
	public function installed_version() {
		$head = $this->dropin_head();

		if ( '' === $head ) {
			return '';
		}

		if ( preg_match( '/' . preg_quote( self::VERSION_TAG, '/' ) . '\s+([0-9A-Za-z.\-]+)/', $head, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * 已安装的 drop-in 是否需要重装。
	 *
	 * 为什么必须存在这一层（真机实测到的整站白屏）：
	 * drop-in 是**复制**到 `wp-content/` 的独立文件，插件升级只替换插件目录里的文件，
	 * 不会动它。而它跑在 WordPress 之前，引用的类名一旦与新版插件对不上就是 PHP Fatal——
	 * 前台与 wp-admin 一起白屏，且因为 WordPress 根本没机会加载，drop-in 永远得不到修复。
	 * 3.0.2 把命名空间改成 `AT8SA` 时正好踩中：老 drop-in + 新插件 = 整站打不开。
	 *
	 * 模板里的 `class_exists()` 护栏把"白屏"降级为"暂时没缓存"，
	 * 本方法则负责在 WordPress 能跑起来之后把 drop-in 修好，二者缺一不可。
	 *
	 * 未安装时返回 false：装不装 drop-in 由激活流程与设置开关决定，不在这里擅自安装。
	 *
	 * @return bool
	 */
	public function needs_reinstall() {
		$head = $this->dropin_head();

		if ( '' === $head ) {
			return false;
		}

		return $this->installed_version() !== AT8SA_VERSION;
	}

	/**
	 * 卸载 drop-in（只删属于自己的）。
	 *
	 * @return bool
	 */
	public function uninstall() {
		if ( ! $this->is_installed() ) {
			return true;
		}

		$target = $this->dropin_path();

		wp_delete_file( $target );

		// 必须清 stat 缓存再回查：上面的 `is_installed()` 已经用 `is_file()` 把
		// "文件存在"缓存进了 PHP 的请求级 stat 缓存，不清就会读到旧结果，
		// 把一次成功的删除报成失败。
		clearstatcache( true, $target );

		// 刻意不依赖 `wp_delete_file()` 的返回值，而是清完 stat 缓存后回查
		// "文件是否还在"：返回值只能说明"那一次删除调用的结果"，
		// 而回查能覆盖任何原因造成的未删除（目录不可写、文件被占用、
		// 其它插件的 wp_delete_file 钩子改写行为），避免目录不可写时假装成功。
		return ! is_file( $target );
	}

	/**
	 * WP_CACHE 是否已启用。
	 *
	 * @return bool
	 */
	public function is_wp_cache_enabled() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * wp-config.php 路径。
	 *
	 * @return string
	 */
	private function wp_config_path() {
		return ABSPATH . 'wp-config.php';
	}

	/**
	 * 在 wp-config.php 中启用 WP_CACHE。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function enable_wp_cache() {
		$path = $this->wp_config_path();

		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return array(
				'ok'      => true,
				'message' => __( 'WP_CACHE 已启用。', 'at8-site-accelerator' ),
			);
		}

		if ( ! is_file( $path ) || ! wp_is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 不可写，请手动在文件中加入：define( \'WP_CACHE\', true );', 'at8-site-accelerator' ),
			);
		}

		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( '' === $original ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 读取失败。', 'at8-site-accelerator' ),
			);
		}

		if ( false !== strpos( $original, 'WP_CACHE' ) ) {
			// 文件里已经是 `true`（例如上次已由我们或站点管理员开启）→ 幂等返回成功，
			// 不能落到下面"无法自动改写"的报错分支，否则重复调用会误报失败。
			if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;/', $original ) ) {
				return array(
					'ok'      => true,
					'message' => __( 'WP_CACHE 已启用。', 'at8-site-accelerator' ),
				);
			}

			// 已存在但为 false，替换掉它。
			$updated = preg_replace(
				'/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)\s*;/',
				"define( 'WP_CACHE', true ); " . self::WP_CACHE_MARKER,
				$original,
				1
			);

			if ( is_string( $updated ) && $updated !== $original ) {
				return $this->write_wp_config( $path, $original, $updated );
			}

			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 中已有 WP_CACHE 定义且无法自动改写，请手动设为 true。', 'at8-site-accelerator' ),
			);
		}

		// 严格"只插入一行"：ltrim 去掉 $line 的前导换行，$line 自带的尾部换行即行尾，
		// 不再额外追加 "\n"，这样"删掉标记行"就能精确还原原文（可逆性校验依赖此不变量）。
		$line = "\ndefine( 'WP_CACHE', true ); " . self::WP_CACHE_MARKER . "\n";

		// 插到 "stop editing" 之前，这是 wp-config.php 的标准锚点。
		$anchors = array(
			"/* That's all, stop editing!",
			"require_once ABSPATH . 'wp-settings.php';",
			'require_once ABSPATH . "wp-settings.php";',
		);

		$updated = '';

		foreach ( $anchors as $anchor ) {
			$pos = strpos( $original, $anchor );

			if ( false !== $pos ) {
				$updated = substr( $original, 0, $pos ) . ltrim( $line ) . substr( $original, $pos );
				break;
			}
		}

		if ( '' === $updated ) {
			return array(
				'ok'      => false,
				'message' => __( '未能在 wp-config.php 中定位插入点，请手动加入：define( \'WP_CACHE\', true );', 'at8-site-accelerator' ),
			);
		}

		return $this->write_wp_config( $path, $original, $updated );
	}

	/**
	 * 从 wp-config.php 中移除我们插入的 WP_CACHE 行。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function disable_wp_cache() {
		$path = $this->wp_config_path();

		if ( ! is_file( $path ) || ! wp_is_writable( $path ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 不可写，请手动移除 WP_CACHE 定义。', 'at8-site-accelerator' ),
			);
		}

		$original = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( false === strpos( $original, self::WP_CACHE_MARKER ) ) {
			return array(
				'ok'      => true,
				'message' => __( '未发现本插件写入的 WP_CACHE 定义，无需处理。', 'at8-site-accelerator' ),
			);
		}

		$updated = preg_replace(
			'/^.*' . preg_quote( self::WP_CACHE_MARKER, '/' ) . '.*$\R?/m',
			'',
			$original
		);

		if ( ! is_string( $updated ) || $updated === $original ) {
			return array(
				'ok'      => false,
				'message' => __( '移除失败，请手动删除带 "Added by AT8 Site Accelerator" 标记的那一行。', 'at8-site-accelerator' ),
			);
		}

		return $this->write_wp_config( $path, $original, $updated );
	}

	/**
	 * 写 wp-config.php：临时备份 → 写入 → 校验 → 失败回滚 → **立即删除临时备份**。
	 *
	 * 为什么必须删掉备份（这不是洁癖，是安全问题）：
	 * `wp-config.php` 里是数据库密码、AUTH_KEY / SECURE_AUTH_KEY / LOGGED_IN_KEY /
	 * NONCE_KEY 以及 8 个 SALT。备份文件如果**长期**留在 `ABSPATH` 下，
	 * 它就是一个只要猜到路径就能直接下载的明文凭据文件 ——
	 * 任何访客、任何扫 `.bak` 后缀的爬虫、任何一次目录列举都能拿到整站密钥。
	 * 早前版本把备份长期留在原地（文件名形如 `wp-config.php` + 备份后缀），
	 * 属于 WordPress.org 会直接判 P0 的问题。
	 *
	 * 现在的流程严格遵循「临时文件」语义：
	 * 1. 备份到 `wp-config.php` **同目录**的临时文件（同目录才能保证 `rename()`
	 *    是原子操作，不会因为跨文件系统而失败）；
	 * 2. 写入 → 校验；
	 * 3. 成功或失败，**都在 `finally` 语义下删掉临时文件**。
	 *
	 * 真正需要"留底"的场景是写入失败且回滚也失败 —— 那种情况下内容还在内存里，
	 * 已通过日志记录路径告知管理员手工恢复，不再依赖磁盘上的明文副本。
	 *
	 * @param string $path     路径。
	 * @param string $original 原始内容。
	 * @param string $updated  新内容。
	 * @return array{ok:bool,message:string}
	 */
	private function write_wp_config( $path, $original, $updated ) {
		$backup = $path . '.at8sa.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $backup, $original ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '无法创建 wp-config.php 临时备份，操作已中止（安全优先）。', 'at8-site-accelerator' ),
			);
		}

		$result = $this->apply_wp_config( $path, $original, $updated );

		// 无论成败都立刻删除临时副本，不给明文凭据留任何在 Web Root 里的时间。
		$this->discard_wp_config_backup( $backup );

		return $result;
	}

	/**
	 * 实际执行「写入 → 校验 → 失败回滚」，并返回面向用户的结果。
	 *
	 * 与 {@see write_wp_config()} 分开，是为了让临时备份的清理只有一个出口，
	 * 不会因为将来有人在中间加 early return 而漏删。
	 *
	 * @param string $path     路径。
	 * @param string $original 原始内容。
	 * @param string $updated  新内容。
	 * @return array{ok:bool,message:string}
	 */
	private function apply_wp_config( $path, $original, $updated ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $path, $updated ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array(
				'ok'      => false,
				'message' => __( '写入 wp-config.php 失败。', 'at8-site-accelerator' ),
			);
		}

		if ( ! $this->verify_wp_config( $path, $original ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $path, $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$this->logger->error( 'wp-config.php 校验失败，已自动回滚' );

			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 写入后校验未通过，已自动回滚。请手动添加 WP_CACHE 定义。', 'at8-site-accelerator' ),
			);
		}

		$this->logger->info( 'wp-config.php 已更新（临时备份已删除）' );

		return array(
			'ok'      => true,
			'message' => __( '已启用 WP_CACHE。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 删除 wp-config.php 的临时备份。
	 *
	 * 只用 `wp_delete_file()`，不再保留 `@unlink` 兜底。
	 *
	 * 这里经历过一次方向反转，理由必须写清楚，免得日后有人"善意地"把兜底加回来：
	 *
	 * 上一版是"先 `wp_delete_file()`、失败再 `@unlink`"的两段式，动机是
	 * `wp_delete_file()` 内部先 `is_file()` 再 `unlink`，一旦 stat 失败
	 * （权限、符号链接、open_basedir）就返回 false，对"绝不能留在磁盘上的明文
	 * 凭据文件"来说，静默跳过删除不能接受。
	 *
	 * 但实测下来这个兜底**保不住**：Plugin Check 的
	 * `WordPress.WP.AlternativeFunctions.unlink_unlink` 是 ERROR 级硬门槛，
	 * 只要源码里出现 `unlink` 调用就报，`phpcs:ignore` 注解挡不住
	 * （本地 phpcs 认豁免、Plugin Check 照样报 —— 两者行为不一致）。
	 *
	 * 而兜底的实际收益近乎为零：本方法开头已经用 `file_exists()` 确认过文件
	 * 可 stat，能走到这一步说明 stat 通路是好的，此时 `wp_delete_file()` 里的
	 * `is_file()` 同样会通过；反过来说，若真是权限问题导致 `is_file()` 失败，
	 * `unlink` 也会因为同一个权限而失败。用一个"几乎用不上的兜底"去换
	 * 一条阻断上架的 ERROR，不划算。
	 *
	 * 现在的降级方式改为**大声告警**：删除失败时在日志里点名残留文件路径，
	 * 让站长能手动清掉 —— 不是静默跳过，仍然有可观测、可处理的出口。
	 * 另有一层兜底在更上游：临时文件只在一次请求内存活，绝大多数情况下
	 * 它还没被扫到就已经不存在了。
	 *
	 * @param string $backup 临时备份路径。
	 * @return void
	 */
	private function discard_wp_config_backup( $backup ) {
		if ( ! file_exists( $backup ) ) {
			return;
		}

		// `wp_delete_file()` 是 WordPress 规定的文件删除入口：除删除本身外还会触发
		// `wp_delete_file` 动作，站点的审计钩子能观测到"含凭据的文件已被删除"。
		// 插件最低支持 WP 5.8，该函数（4.2 引入）必然存在，无需 function_exists 探测。
		if ( wp_delete_file( $backup ) ) {
			return;
		}

		$this->logger->error(
			'wp-config.php 临时备份删除失败，磁盘上可能残留明文凭据文件，请手动删除',
			array( 'backup' => $backup )
		);
	}

	/**
	 * 校验 wp-config.php 仍然是"看起来能跑的"配置。
	 *
	 * 检查项刻意保守：非空、含 DB_NAME、含 wp-settings.php 引入、大括号配平（**词法级**）、
	 * 且我们的改动可逆（拿掉标记行能还原原文）。
	 *
	 * 为什么不用 `substr_count( $content, '{' )`：wp-config.php 里的 8 个随机
	 * salt（AUTH_KEY / SECURE_AUTH_KEY / … / NONCE_SALT）字符集包含 `{` 和 `}`，
	 * 几乎必然出现"字符串内括号"，朴素的全文计数会把它算进来 → 括号数永不配平 →
	 * 校验恒失败 → 每次都误回滚 → WP_CACHE 永远开不起来。必须只统计真实代码 token。
	 *
	 * @param string $path     路径。
	 * @param string $original 写入前的原文，用于可逆性比对。
	 * @return bool
	 */
	private function verify_wp_config( $path, $original = '' ) {
		$content = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( strlen( $content ) < 100 ) {
			return false;
		}

		if ( false === strpos( $content, 'DB_NAME' ) ) {
			return false;
		}

		if ( false === strpos( $content, 'wp-settings.php' ) ) {
			return false;
		}

		if ( ! $this->braces_balanced( $content ) ) {
			return false;
		}

		// 可逆性：我们的改动必须能精确还原成原文，否则说明写坏了别的内容。
		if ( '' !== $original && ! $this->is_reversible( $original, $content ) ) {
			return false;
		}

		return true;
	}

	/**
	 * 只在真实代码 token 上统计大括号，忽略字符串与注释。
	 *
	 * 词法器不可用或解析异常时返回 true（不阻断），由可逆性检查兜底。
	 *
	 * @param string $content 文件内容。
	 * @return bool
	 */
	private function braces_balanced( $content ) {
		if ( ! function_exists( 'token_get_all' ) ) {
			return true;
		}

		try {
			$tokens = @token_get_all( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			return true;
		}

		$depth = 0;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				// 字符串插值里的 `{`：T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES。
				if ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
					++$depth;
				}
				// 其余数组型 token（常量字符串、注释、关键字）一律不计。
				continue;
			}

			if ( '{' === $token ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;

				if ( $depth < 0 ) {
					return false;
				}
			}
		}

		return 0 === $depth;
	}

	/**
	 * 判断 $original 与 $content 是否"只差我们那一行"。
	 *
	 * 必须**双向**成立，因为启用是"加一行"、停用是"减一行"：
	 * ① 启用-插入：strip(新文) === 原文
	 * ② 停用-删除：strip(原文) === 新文
	 * ③ 启用-替换：把 `WP_CACHE, true` 换回 `false` === 原文
	 *
	 * 只做单向判断会导致停用路径恒失败 → 误回滚（曾实测踩到）。
	 *
	 * @param string $original 写入前原文。
	 * @param string $content  写入后内容。
	 * @return bool
	 */
	private function is_reversible( $original, $content ) {
		$marker = preg_quote( self::WP_CACHE_MARKER, '/' );
		$line   = '/^.*' . $marker . '.*$\R?/m';

		$stripped_content  = preg_replace( $line, '', $content, 1 );
		$stripped_original = preg_replace( $line, '', $original, 1 );

		// ① 启用（插入了一行）。
		if ( is_string( $stripped_content ) && $stripped_content === $original ) {
			return true;
		}

		// ② 停用（删掉了一行）。
		if ( is_string( $stripped_original ) && $stripped_original === $content ) {
			return true;
		}

		// ③ 启用（把 `false` 原地换成 `true`）。
		$restored = preg_replace(
			'/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)\s*;\s*' . $marker . '/',
			"define( 'WP_CACHE', false );",
			$content,
			1
		);

		return is_string( $restored ) && $restored === $original;
	}
}

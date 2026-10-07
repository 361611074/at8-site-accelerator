<?php
/**
 * advanced-cache.php drop-in 的安装 / 卸载，以及 wp-config.php 中 WP_CACHE 的安全开关。
 *
 * 计划书 §104 明令"禁止自动修改 WordPress Core"。这里只动 `wp-content/advanced-cache.php`
 * 与 `wp-config.php` 两个**用户配置文件**，且：
 * - **不创建 wp-config.php 的任何磁盘副本**。原文只存在于本次请求的 PHP 内存里，
 *   写入失败或校验失败都从内存回滚（3.0.6 起，见 `write_wp_config()` 的说明）；
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

		$original = $this->read_file( $path );

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

		$original = $this->read_file( $path );

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
	 * 写 wp-config.php：写入 → 回读校验 → 失败从**内存**回滚。
	 *
	 * ## 3.0.6 的架构级变更：不再创建任何磁盘副本
	 *
	 * 3.0.5 及更早的做法是"先把原文复制到 `wp-config.php.at8sa.tmp`，写完再删掉"。
	 * WordPress.org 人工审核（Review ID: `at8-site-accelerator/x361611074/5Oct26/T2
	 * 7Oct26/4.3`）判定这是 P0：
	 *
	 * > wp-config.php contents, including authentication keys and salts, are
	 * > temporarily written to the predictable web-root file
	 * > wp-config.php.at8sa.tmp, which may remain exposed if deletion fails.
	 *
	 * 问题不在"删得够不够快"，而在**这个模型本身**：只要有一瞬间磁盘上存在
	 * `ABSPATH` 下、名字可预测的明文副本，`https://站点/wp-config.php.at8sa.tmp`
	 * 就是一份可被直接下载的整站凭据（数据库密码 + 4 个 KEY + 8 个 SALT）。
	 * 删除失败、进程被 kill、只读异常、并发请求撞上——任何一条都会让它留下来。
	 *
	 * 换成内存回滚之后，这个风险面被**整体消除**：
	 *
	 * ```text
	 * read original
	 *      ↓
	 * validate original        （原文只在 $original 变量里，不落盘）
	 *      ↓
	 * build updated in memory
	 *      ↓
	 * write target directly
	 *      ↓
	 * read back + verify
	 *      ↓
	 * 失败 → 用内存里的 $original 写回 → 再读回比对
	 * ```
	 *
	 * 回滚能力没有削弱：唯一的变化是"回滚源"从磁盘临时文件变成 PHP 变量，
	 * 而 PHP 变量在整个请求生命周期内都在，比"删之前还在的临时文件"更可靠。
	 *
	 * 日志同样收紧：只记录"写入失败 / 校验失败 / 回滚失败"这三类事件名，
	 * 绝不记录 `$original`、`$updated` 或任何配置内容。
	 *
	 * @param string $path     路径。
	 * @param string $original 原始内容（唯一的回滚源）。
	 * @param string $updated  新内容。
	 * @return array{ok:bool,message:string}
	 */
	private function write_wp_config( $path, $original, $updated ) {
		if ( ! $this->write_file( $path, $updated ) ) {
			$this->logger->error( 'wp-config.php 写入失败' );

			// `file_put_contents()` 是"先截断再写"，失败时文件可能只剩半截。
			// 因此即便写入失败，也要试着用内存里的原文把文件恢复回去。
			$this->restore_wp_config( $path, $original );

			return array(
				'ok'      => false,
				'message' => __( '写入 wp-config.php 失败，已尝试恢复原始内容。', 'at8-site-accelerator' ),
			);
		}

		if ( ! $this->verify_wp_config( $path, $original ) ) {
			$this->logger->error( 'wp-config.php 校验失败' );

			if ( ! $this->restore_wp_config( $path, $original ) ) {
				$this->logger->error( 'wp-config.php 回滚失败' );

				return array(
					'ok'      => false,
					'message' => __( 'wp-config.php 校验未通过且自动回滚失败，请手动检查该文件。', 'at8-site-accelerator' ),
				);
			}

			return array(
				'ok'      => false,
				'message' => __( 'wp-config.php 写入后校验未通过，已自动回滚。请手动添加 WP_CACHE 定义。', 'at8-site-accelerator' ),
			);
		}

		$this->logger->info( 'wp-config.php 已更新' );

		return array(
			'ok'      => true,
			'message' => __( '已启用 WP_CACHE。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 用内存里的原文恢复 wp-config.php，并回读确认恢复成功。
	 *
	 * 判定标准刻意用"回读内容 === 原文"而不是 `verify_wp_config()`：
	 * 后者含**可逆性**检查（新文去掉我们那一行要能还原成原文），
	 * 而"原文 vs 原文"在停用路径上天然不满足可逆性（见 `is_reversible()` 的注释），
	 * 用它判断恢复结果会把一次成功的恢复误报成回滚失败。
	 *
	 * @param string $path     路径。
	 * @param string $original 原始内容。
	 * @return bool 是否确认已恢复。
	 */
	private function restore_wp_config( $path, $original ) {
		if ( ! $this->write_file( $path, $original ) ) {
			return false;
		}

		// 必须清 stat 缓存：上面的写入会改变文件大小，不清会读到旧的长度。
		clearstatcache( true, $path );

		return $this->read_file( $path ) === $original;
	}

	/**
	 * 读文件内容（优先走 WordPress Filesystem API）。
	 *
	 * 为什么"优先"而不是"只用它"：初始化 `WP_Filesystem()` 需要
	 * `wp-admin/includes/file.php`，在非 direct 传输模式的主机上还会弹出
	 * FTP 凭证表单——把那套东西引进"前台一键开关 WP_CACHE"的路径里，
	 * 等于用一个更大的可用性风险去换一个小收益。
	 * 所以这里只在 WordPress 已经初始化好 `$wp_filesystem` 时复用它，
	 * 否则退回原生读取。两者对同一个文件读到的内容完全一致。
	 *
	 * @param string $path 绝对路径。
	 * @return string 读不到时返回空串。
	 */
	private function read_file( $path ) {
		$fs = $this->wp_filesystem();

		if ( $fs ) {
			$contents = $fs->get_contents( $path );

			return is_string( $contents ) ? $contents : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$contents = @file_get_contents( $path );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * 写文件（优先走 WordPress Filesystem API，见 `read_file()` 的说明）。
	 *
	 * @param string $path     绝对路径。
	 * @param string $contents 内容。
	 * @return bool
	 */
	private function write_file( $path, $contents ) {
		$fs = $this->wp_filesystem();

		if ( $fs ) {
			return (bool) $fs->put_contents( $path, $contents );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== @file_put_contents( $path, $contents );
	}

	/**
	 * 当前请求里可用的 WordPress Filesystem 实例。
	 *
	 * 只取**已经初始化好的**那一个，绝不在这里主动 `WP_Filesystem()`——
	 * 主动初始化会在部分主机上触发凭证表单输出，把一次后台按钮点击变成白屏。
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private function wp_filesystem() {
		global $wp_filesystem;

		if ( isset( $wp_filesystem ) && $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		return null;
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
		$content = $this->read_file( $path );

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

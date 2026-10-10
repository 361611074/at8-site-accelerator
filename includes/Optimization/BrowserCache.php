<?php
/**
 * 浏览器缓存（HTTP 缓存头）。
 *
 * 这里有一个**必须说清楚的边界**：
 * PHP 只能给"经过 PHP 的响应"（也就是 HTML 文档）加缓存头。CSS/JS/图片由 Web 服务器
 * 直接返回，PHP 根本没机会插手。想给它们加长缓存，只能写服务器规则。
 *
 * 因此本模块只负责**静态资源**这一半：生成 nginx / Apache 片段供用户**自行**启用。
 *
 * 计划书 §125 明确"不要自动覆盖用户已有规则"，所以本模块**绝不自动写 .htaccess**，
 * 只提供可复制的片段；Apache 用户可以在后台显式点按钮写入带标记的独立块。
 *
 * ⚠️ 组合风险：`remove_query_strings`（移除 ?ver=）与"静态资源长缓存"同时开启时，
 * 主题/插件更新后浏览器仍会使用旧的 CSS/JS，且因为 URL 没变而无法自动失效。
 * 后台会就此给出明确警告，详见 docs/COMPATIBILITY.md。
 *
 * ## HTML 公共浏览器缓存：Free 版已整体移除（3.0.6.3）
 *
 * 3.0.6 曾实现过一道"公共响应资格 Gate"（十五道检查全部通过才发送
 * `Cache-Control: public`）。WordPress.org 第二轮人工审核否决了这个方案：
 * 该 Gate 挂在 `send_headers` 钩子上，而此时主题/插件的模板代码还没跑完——
 * 它们**之后**才添加的 `Set-Cookie`、`Cache-Control: private/no-store`、
 * `Vary: Cookie` 或才定义的 `DONOTCACHEPAGE`，Gate 在发送头的那一刻根本看不见。
 * 只凭发送时刻的快照就宣布"整份响应可公共缓存"，正确性无法保证。
 *
 * 因此 Free 版**不再为 HTML 响应主动发送任何公共缓存头**：
 * 历史数据库里遗留的 `browser_cache_html=1` 会被运行时忽略（该键不再有任何
 * 读取方），HTML 的新鲜度完全交给本插件的整页缓存机制控制；
 * CSS/JS/图片/字体的静态资源缓存不受影响，继续可用。
 *
 * @package AT8SA\Optimization
 */

namespace AT8SA\Optimization;

use AT8SA\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class BrowserCache
 */
final class BrowserCache {

	/**
	 * .htaccess 规则块的开始标记。
	 */
	const HTACCESS_BEGIN = '# ==== BEGIN AT8 Site Accelerator ====';

	/**
	 * .htaccess 规则块的结束标记。
	 */
	const HTACCESS_END = '# ==== END AT8 Site Accelerator ====';

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
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		// 3.0.6.3（WordPress.org 第二轮整改）：Free 版不再为 HTML 响应注册
		// `send_headers` 钩子——公共 HTML 浏览器缓存路径已整体移除，历史数据库
		// 里的 browser_cache_html=1 在运行时被忽略。本方法保留只为维持容器
		// 装配（Plugin::boot() 仍调用它），现在是有意的空操作。
	}

	/**
	 * 静态资源缓存时长（秒）。
	 *
	 * @return int
	 */
	public function asset_ttl() {
		return max( 3600, (int) $this->settings->get( 'browser_cache_ttl', 31536000 ) );
	}

	/**
	 * nginx 规则片段。
	 *
	 * @return string
	 */
	public function nginx_rules() {
		$ttl = $this->asset_ttl();

		$rules  = "# ==== AT8 Site Accelerator: 静态资源浏览器缓存 ====\n";
		$rules .= "# 把本段放入站点的 server { } 块内（或 include 本文件）。\n";
		$rules .= "# 若你已有 location ~* \\.(css|js|...)$ 规则，请合并，不要重复声明。\n\n";
		$rules .= "location ~* \\.(?:css|js|mjs|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico|mp4|webm|pdf)$ {\n";
		$rules .= "    expires {$ttl}s;\n";
		$rules .= "    add_header Cache-Control \"public, max-age={$ttl}, immutable\";\n";
		$rules .= "    access_log off;\n";
		$rules .= "}\n\n";
		$rules .= "# HTML 不做长缓存（由插件整页缓存控制新鲜度）\n";
		$rules .= "location ~* \\.(?:html|php)$ {\n";
		$rules .= "    add_header Cache-Control \"no-cache, must-revalidate\";\n";
		$rules .= "}\n";

		return $rules;
	}

	/**
	 * Apache .htaccess 规则片段。
	 *
	 * @return string
	 */
	public function apache_rules() {
		$ttl   = $this->asset_ttl();
		$years = max( 1, (int) round( $ttl / 31536000 ) );

		$rules  = "# ==== BEGIN AT8 Site Accelerator ====\n";
		$rules .= "<IfModule mod_expires.c>\n";
		$rules .= "    ExpiresActive On\n";
		$rules .= "    ExpiresByType text/css \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType application/javascript \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/webp \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/avif \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/jpeg \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/png \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType image/svg+xml \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType font/woff2 \"access plus {$years} year\"\n";
		$rules .= "    ExpiresByType text/html \"access plus 0 seconds\"\n";
		$rules .= "</IfModule>\n";
		$rules .= "# ==== END AT8 Site Accelerator ====\n";

		return $rules;
	}

	/**
	 * 是否已写入 .htaccess 标记块。
	 *
	 * @return bool
	 */
	public function htaccess_has_rules() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}

		$content = $this->read_file( $file );

		return false !== strpos( $content, self::HTACCESS_BEGIN );
	}

	/**
	 * 显式写入 .htaccess 标记块（仅用户主动触发时调用）。
	 *
	 * ## 为什么不再生成 `.htaccess.at8sa.bak`
	 *
	 * 3.0.5 及更早会在 `ABSPATH` 下留一份 `.htaccess.at8sa.bak`。它虽然不含数据库
	 * 密码，但同样是一个**位于 Web Root、名字可预测**的明文副本：任何人请求
	 * `https://站点/.htaccess.at8sa.bak` 就能拿到整份重写规则（里面有站点目录结构、
	 * 自定义跳转、防盗链/鉴权路径），而且它**永远不会被清理**——比 wp-config 那个
	 * 用完即删的临时文件留得更久。
	 *
	 * 3.0.6 起与 wp-config 采用同一套模型：原文只留在内存里，
	 * 写入失败或校验失败就用它回滚，磁盘上不产生任何副本。
	 * 回滚安全性没有下降——移除功能本来就能一键还原（见 `remove_htaccess()`）。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function write_htaccess() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return array(
				'ok'      => false,
				'message' => __( '未检测到可写的 .htaccess，请手动把规则片段粘贴到你的服务器配置中。', 'at8-site-accelerator' ),
			);
		}

		$original = $this->read_file( $file );

		if ( false !== strpos( $original, self::HTACCESS_BEGIN ) ) {
			return array(
				'ok'      => true,
				'message' => __( '规则已存在，无需重复写入。', 'at8-site-accelerator' ),
			);
		}

		$updated = rtrim( $original ) . "\n\n" . $this->apache_rules();

		if ( ! $this->write_file( $file, $updated ) ) {
			return array(
				'ok'      => false,
				'message' => __( '写入 .htaccess 失败。', 'at8-site-accelerator' ),
			);
		}

		// 回读校验：新内容必须同时"含我们的标记块"且"原有规则还在"。
		// 任何一条不成立就用内存里的原文回滚——.htaccess 写坏会直接让整站 500。
		if ( ! $this->verify_htaccess( $file, $original, true ) ) {
			if ( ! $this->restore_file( $file, $original ) ) {
				return array(
					'ok'      => false,
					'message' => __( '写入 .htaccess 后校验未通过，且自动回滚失败，请手动检查该文件。', 'at8-site-accelerator' ),
				);
			}

			return array(
				'ok'      => false,
				'message' => __( '写入 .htaccess 后校验未通过，已自动回滚。', 'at8-site-accelerator' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( '规则已追加到 .htaccess 末尾。可在同一位置一键移除，插件不会在站点上留下任何备份文件。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 移除 .htaccess 标记块。
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function remove_htaccess() {
		$file = $this->htaccess_path();

		if ( ! is_file( $file ) || ! wp_is_writable( $file ) ) {
			return array(
				'ok'      => false,
				'message' => __( '.htaccess 不可写。', 'at8-site-accelerator' ),
			);
		}

		$original = $this->read_file( $file );

		$updated = preg_replace(
			'/\n*' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\n?/s',
			"\n",
			$original
		);

		if ( ! is_string( $updated ) || $updated === $original ) {
			return array(
				'ok'      => false,
				'message' => __( '未找到本插件写入的规则块。', 'at8-site-accelerator' ),
			);
		}

		if ( ! $this->write_file( $file, $updated ) ) {
			return array(
				'ok'      => false,
				'message' => __( '写入 .htaccess 失败。', 'at8-site-accelerator' ),
			);
		}

		if ( ! $this->verify_htaccess( $file, $original, false ) ) {
			if ( ! $this->restore_file( $file, $original ) ) {
				return array(
					'ok'      => false,
					'message' => __( '移除 .htaccess 规则块后校验未通过，且自动回滚失败，请手动检查该文件。', 'at8-site-accelerator' ),
				);
			}

			return array(
				'ok'      => false,
				'message' => __( '移除 .htaccess 规则块后校验未通过，已自动回滚。', 'at8-site-accelerator' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( '规则块已移除。', 'at8-site-accelerator' ),
		);
	}

	/**
	 * 回读并校验 .htaccess 的写入结果。
	 *
	 * 两条都查，缺一不可：
	 * - 标记块的存在性（写/删有没有真的生效）；
	 * - 原有内容仍在（有没有把用户的规则冲掉）。
	 *
	 * @param string $path     文件路径。
	 * @param string $original 原文（用于判断"原有内容仍在"）。
	 * @param bool   $written  true = 期望标记块存在；false = 期望标记块已消失。
	 * @return bool
	 */
	private function verify_htaccess( $path, $original, $written ) {
		$content = $this->read_file( $path );

		if ( '' === $content ) {
			return false;
		}

		$has_marker = false !== strpos( $content, self::HTACCESS_BEGIN );

		if ( $has_marker !== $written ) {
			return false;
		}

		// 原有规则必须完整保留：把标记块去掉后应能还原为原文。
		if ( $written ) {
			$stripped = preg_replace(
				'/\n*' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\n?/s',
				"\n",
				$content
			);

			return is_string( $stripped ) && false !== strpos( $stripped, trim( $original ) );
		}

		// 移除路径：原文去掉标记块后应与当前内容完全一致。
		$expected = preg_replace(
			'/\n*' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?' . preg_quote( self::HTACCESS_END, '/' ) . '\n?/s',
			"\n",
			$original
		);

		return is_string( $expected ) && $content === $expected;
	}

	/**
	 * 读文件内容。
	 *
	 * @param string $path 路径。
	 * @return string 读不到时返回空串。
	 */
	private function read_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$contents = @file_get_contents( $path );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * 写文件。
	 *
	 * @param string $path     路径。
	 * @param string $contents 内容。
	 * @return bool
	 */
	private function write_file( $path, $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== @file_put_contents( $path, $contents );
	}

	/**
	 * 用内存里的原文恢复文件，并回读确认。
	 *
	 * @param string $path     路径。
	 * @param string $original 原文。
	 * @return bool
	 */
	private function restore_file( $path, $original ) {
		if ( ! $this->write_file( $path, $original ) ) {
			return false;
		}

		clearstatcache( true, $path );

		return $this->read_file( $path ) === $original;
	}

	/**
	 * .htaccess 路径。
	 *
	 * @return string
	 */
	private function htaccess_path() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * 服务器类型（用于后台给出对应建议）。
	 *
	 * @return string nginx|apache|litespeed|unknown
	 */
	public function server_type() {
		// 这个值只用于 strpos 判断，返回值是下面四个固定常量之一，
		// 不会被回显、不会拼进 SQL / 路径，所以无需额外净化。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';

		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}

		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}

		return 'unknown';
	}

	/**
	 * 是否存在"移除 ?ver= + 长缓存"的危险组合。
	 *
	 * @return bool
	 */
	public function has_risky_combination() {
		return $this->settings->is_on( 'browser_cache' )
			&& $this->settings->is_on( 'remove_query_strings' )
			&& $this->asset_ttl() > 604800;
	}
}

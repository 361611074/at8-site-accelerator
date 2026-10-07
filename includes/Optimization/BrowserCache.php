<?php
/**
 * 浏览器缓存（HTTP 缓存头）。
 *
 * 这里有一个**必须说清楚的边界**：
 * PHP 只能给"经过 PHP 的响应"（也就是 HTML 文档）加缓存头。CSS/JS/图片由 Web 服务器
 * 直接返回，PHP 根本没机会插手。想给它们加长缓存，只能写服务器规则。
 *
 * 因此本模块分两半：
 * 1. HTML 响应头 —— PHP 直接设置（有效）；
 * 2. 静态资源规则 —— 生成 nginx / Apache 片段供用户**自行**启用。
 *
 * 计划书 §125 明确"不要自动覆盖用户已有规则"，所以本模块**绝不自动写 .htaccess**，
 * 只提供可复制的片段；Apache 用户可以在后台显式点按钮写入带标记的独立块。
 *
 * ⚠️ 组合风险：`remove_query_strings`（移除 ?ver=）与"静态资源长缓存"同时开启时，
 * 主题/插件更新后浏览器仍会使用旧的 CSS/JS，且因为 URL 没变而无法自动失效。
 * 后台会就此给出明确警告，详见 docs/COMPATIBILITY.md。
 *
 * ## 公共 HTML 浏览器缓存的安全边界（3.0.6）
 *
 * WordPress.org 人工审核指出：只凭"是否登录"判断一个 HTML 响应能否被
 * **共享缓存**（CDN、公司网关、运营商代理都会读 `Cache-Control: public`）是不够的——
 * 匿名购物车、密码保护页、带 Set-Cookie 的响应一旦被标成 public，
 * 就可能被中间层端给别的访客。
 *
 * 因此 `browser_cache_html` 开启后，响应必须逐条通过
 * {@see BrowserCache::allow_public_html_cache()} 这道 Gate；
 * 任何一条不成立就退回 `no-cache`。判定顺序见该方法内的注释。
 *
 * @package AT8SA\Optimization
 */

namespace AT8SA\Optimization;

use AT8SA\Cache\RequestGuard;
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
		if ( ! $this->settings->is_on( 'browser_cache' ) ) {
			return;
		}

		add_action( 'send_headers', array( $this, 'send_html_headers' ) );
	}

	/**
	 * 为 HTML 响应设置缓存头。
	 *
	 * @return void
	 */
	public function send_html_headers() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) {
			return;
		}

		if ( $this->allow_public_html_cache() ) {
			$ttl = max( 0, (int) $this->settings->get( 'browser_cache_html_ttl', 3600 ) );

			if ( $ttl > 0 ) {
				header( 'Cache-Control: public, max-age=' . $ttl );
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
			}

			return;
		}

		// 已有更严格的 `no-store` / `private` 时不覆盖：那是别的插件/主题明确
		// 声明的保护（WordPress 自己的 wp_get_nocache_headers() 也会用它），
		// 把它改写成 `no-cache, max-age=0` 反而是**放宽**。
		if ( $this->has_stronger_no_store() ) {
			return;
		}

		// 默认：HTML 不做浏览器长缓存，交给本插件的整页缓存去控制新鲜度。
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
	}

	/**
	 * 判定当前响应是否可以安全地发送**公共** HTML 浏览器缓存头。
	 *
	 * ## 为什么不能只看"是否登录"
	 *
	 * 官方原文：
	 *
	 * > Public HTML browser-cache headers are applied based only on login status,
	 * > so anonymous cart, password-protected, or other personalized responses can
	 * > be marked cacheable by shared intermediaries.
	 *
	 * `Cache-Control: public` 不是"这个访客可以缓存"，而是"**任何**中间层都可以缓存并
	 * 分发给别人"。匿名购物车、密码保护页、A/B 分流、带 Set-Cookie 的响应
	 * 都属于"因人而异"，标成 public 就是把一个人的内容端给另一个人。
	 *
	 * ## 判定顺序（全部成立才返回 true）
	 *
	 * ```text
	 * 请求方法是 GET / HEAD
	 *   ↓
	 * 不是 wp-admin / AJAX / REST / XML-RPC / feed / search / 404 / preview
	 *   ↓
	 * 未登录
	 *   ↓
	 * 没有密码保护
	 *   ↓
	 * URI 未命中排除表（含 WooCommerce 动态路径）
	 *   ↓
	 * 没有 Query String
	 *   ↓
	 * 不是 WooCommerce 购物车 / 结算 / 账户 / 端点页
	 *   ↓
	 * 没有会话 / 购物车 / 评论者 Cookie
	 *   ↓
	 * 状态码 200
	 *   ↓
	 * Content-Type 是 text/html
	 *   ↓
	 * 响应没有 Set-Cookie
	 *   ↓
	 * 响应没有 Vary: Cookie
	 *   ↓
	 * 响应未被声明 no-cache / no-store / private / max-age=0 / Pragma: no-cache
	 *   ↓
	 * 未定义 DONOTCACHEPAGE
	 * ```
	 *
	 * 宁可放过、不可错放：任何一条拿不准都返回 false，此时 HTML 走 `no-cache`，
	 * 正确性优先于命中率。
	 *
	 * @param array|null $headers 响应头列表，默认取 `headers_list()`（便于测试注入）。
	 * @param int|null   $status  响应状态码，默认取 `http_response_code()`。
	 * @return bool
	 */
	public function allow_public_html_cache( $headers = null, $status = null ) {
		if ( ! $this->settings->is_on( 'browser_cache' ) || ! $this->settings->is_on( 'browser_cache_html' ) ) {
			return false;
		}

		if ( max( 0, (int) $this->settings->get( 'browser_cache_html_ttl', 3600 ) ) <= 0 ) {
			return false;
		}

		$headers = null === $headers ? (array) headers_list() : $headers;
		$status  = null === $status ? (int) http_response_code() : (int) $status;

		// ① 只有幂等请求允许被共享缓存。
		$method = strtoupper( RequestGuard::server( 'REQUEST_METHOD', 'GET' ) );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}

		// ② WordPress 明确动态的上下文。
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		// DONOTCACHEPAGE 是缓存生态的既成标准常量（WP Super Cache / W3TC / LiteSpeed
		// 都读它），必须原样识别，加前缀反而让其它插件认不出来。
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		// 条件标签直接调用即可：插件运行在 WordPress 完全加载之后，这些函数必然存在
		// （与 `CacheEngine::should_cache_response()` 同一写法）。
		if ( is_feed() || is_search() || is_404() || is_preview() || is_trackback() ) {
			return false;
		}

		// ③ 登录用户。
		if ( is_user_logged_in() ) {
			return false;
		}

		// ④ 密码保护内容：未输密码的访客看到的其实是"请输入密码"页，
		// 输过密码的访客看到的是正文，两者绝不能共用一份公共缓存。
		if ( post_password_required() ) {
			return false;
		}

		// ⑤ URI 排除表（与整页缓存共用同一份规则）。
		$uri = RequestGuard::server( 'REQUEST_URI', '/' );

		foreach ( $this->html_excluded_paths() as $needle ) {
			if ( '' !== (string) $needle && false !== stripos( $uri, (string) $needle ) ) {
				return false;
			}
		}

		// ⑥ 带 Query String 的请求默认不进公共缓存。
		//
		// 插件内部虽然会把 UTM 之类参数归一化后再做整页缓存，但**浏览器和中间层
		// 并不会做同样的归一化**：`/?utm_source=x` 与 `/` 在它们眼里是两个 URL。
		// 一旦允许，utm/fbclid/gclid 这类带来源标记的请求就会各自存一份，
		// 而个性化参数（`?lang=`、`?currency=`）更可能直接串内容。
		if ( false !== strpos( $uri, '?' ) ) {
			return false;
		}

		// ⑦ WooCommerce 动态页面（逐个判存在，不赌它们一定一起定义）。
		//
		// 走 `is_conditional_tag()` 而不是内联 `function_exists( $tag ) && $tag()`：
		// WooCommerce 未激活时这些函数根本不存在，运行时必须判存在；
		// 而函数名作为**变量**传入后静态分析无法折叠，既保住运行时安全也不产生噪声。
		if ( $this->is_conditional_tag( 'is_cart' )
			|| $this->is_conditional_tag( 'is_checkout' )
			|| $this->is_conditional_tag( 'is_account_page' )
			|| $this->is_conditional_tag( 'is_wc_endpoint_url' )
		) {
			return false;
		}

		// ⑧ 会话 / 购物车 / 评论者 Cookie —— 与整页缓存完全同一套规则。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只读 Cookie **名**，不读值，用于判定响应是否因人而异。
		if ( RequestGuard::has_bypass_cookie( $this->personalization_cookie_rules(), (array) $_COOKIE ) ) {
			return false;
		}

		// ⑨ 只有 200 允许被共享缓存（3xx/4xx/5xx 往往依赖请求上下文）。
		if ( 200 !== $status ) {
			return false;
		}

		// ⑩ 只给真正的 HTML 发公共缓存头。
		$types = $this->header_values( $headers, 'Content-Type' );

		if ( empty( $types ) || false === stripos( $types[0], 'text/html' ) ) {
			return false;
		}

		// ⑪ 有 Set-Cookie 说明响应与会话相关，绝不能标 public。
		if ( ! empty( $this->header_values( $headers, 'Set-Cookie' ) ) ) {
			return false;
		}

		// ⑫ Vary: Cookie 说明响应随 Cookie 变化，同样不是公共内容。
		foreach ( $this->header_values( $headers, 'Vary' ) as $vary ) {
			if ( false !== stripos( $vary, 'cookie' ) ) {
				return false;
			}
		}

		// ⑬ 已经有人声明 no-cache / private / no-store 的一律尊重，不反向放宽。
		if ( $this->has_no_cache_directive( $headers ) ) {
			return false;
		}

		return true;
	}

	/**
	 * 安全地调用一个可能不存在的条件标签。
	 *
	 * WooCommerce 未激活时 `is_cart()` 之类函数根本没有声明，直接调用会致命；
	 * 反之若站点装了 WC 却把某个函数删掉（子插件 / 主题覆写），单判一个也会白屏。
	 * 统一在这里判存在再调用。
	 *
	 * @param string $tag 条件标签函数名。
	 * @return bool 函数存在且返回真值时为 true。
	 */
	private function is_conditional_tag( $tag ) {
		if ( ! is_string( $tag ) || '' === $tag || ! function_exists( $tag ) ) {
			return false;
		}

		return (bool) call_user_func( $tag );
	}

	/**
	 * HTML 公共缓存的 URI 排除表（内置 + 用户自定义）。
	 *
	 * 与整页缓存共用 `RequestGuard::default_excluded_paths()`：
	 * 一个 URL 若连整页缓存都不该进，就更不该被标成 public 交给中间层分发。
	 *
	 * @return array
	 */
	private function html_excluded_paths() {
		return RequestGuard::merge_rules(
			RequestGuard::default_excluded_paths(),
			(string) $this->settings->get( 'exclude_urls', '' )
		);
	}

	/**
	 * 会让响应"因人而异"的 Cookie 规则（内置 + 用户自定义）。
	 *
	 * @return array
	 */
	private function personalization_cookie_rules() {
		return RequestGuard::merge_rules(
			RequestGuard::default_bypass_cookies(),
			(string) $this->settings->get( 'bypass_cookies', '' )
		);
	}

	/**
	 * 取出某个响应头的所有值（大小写不敏感）。
	 *
	 * @param array  $headers 响应头列表（`headers_list()` 格式）。
	 * @param string $name    头名。
	 * @return array
	 */
	private function header_values( array $headers, $name ) {
		$values = array();
		$needle = strtolower( (string) $name );

		foreach ( $headers as $header ) {
			$parts = explode( ':', is_string( $header ) ? $header : '', 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			if ( strtolower( trim( $parts[0] ) ) === $needle ) {
				$values[] = trim( $parts[1] );
			}
		}

		return $values;
	}

	/**
	 * 响应是否已被声明为不可共享缓存。
	 *
	 * 这里覆盖 WordPress 自己的 `wp_get_nocache_headers()`
	 * （`no-cache, must-revalidate, max-age=0`）以及其它插件 / 主题 / 反向代理
	 * 写入的 `no-store`、`private`、`Pragma: no-cache`。
	 *
	 * @param array $headers 响应头列表。
	 * @return bool
	 */
	private function has_no_cache_directive( array $headers ) {
		$directives = array( 'no-cache', 'no-store', 'private', 'max-age=0' );

		foreach ( $this->header_values( $headers, 'Cache-Control' ) as $value ) {
			foreach ( $directives as $directive ) {
				if ( false !== stripos( $value, $directive ) ) {
					return true;
				}
			}
		}

		foreach ( $this->header_values( $headers, 'Pragma' ) as $value ) {
			if ( false !== stripos( $value, 'no-cache' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 当前响应是否已被声明了比我们要发的 `no-cache` 更严格的指令。
	 *
	 * @return bool
	 */
	private function has_stronger_no_store() {
		foreach ( (array) headers_list() as $header ) {
			$parts = explode( ':', (string) $header, 2 );

			if ( 2 !== count( $parts ) || 'cache-control' !== strtolower( trim( $parts[0] ) ) ) {
				continue;
			}

			$value = strtolower( trim( $parts[1] ) );

			if ( false !== strpos( $value, 'no-store' ) || false !== strpos( $value, 'private' ) ) {
				return true;
			}
		}

		return false;
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

<?php
/**
 * 页面缓存引擎（插件侧 = 写入路径）。
 *
 * 职责划分（对应计划书 §62 Cache Engine）：
 * - **HIT 路径**：由 `advanced-cache.php` drop-in 在 WordPress 加载插件之前完成，
 *   这里只做兜底（drop-in 未安装 / 被禁用时）。
 * - **MISS 路径**：本类在 `init` 挂输出缓冲，响应生成后落盘。
 *
 * 之所以不把写入也塞进 drop-in：drop-in 阶段无法安全使用 `is_404()` /
 * `is_user_logged_in()` 等条件函数，把复杂判断放在那里极易产出"缓存了 404 页"
 * 之类的经典事故。
 *
 * @package AT8\SiteAccelerator\Cache
 */

namespace AT8\SiteAccelerator\Cache;

use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Optimization\HtmlMinifier;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class CacheEngine
 */
final class CacheEngine {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 后端工厂。
	 *
	 * @var BackendFactory
	 */
	private $factory;

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * HTML 压缩器。
	 *
	 * @var HtmlMinifier
	 */
	private $minifier;

	/**
	 * 本次请求是否已经检查过 HIT。
	 *
	 * @var bool
	 */
	private $hit_checked = false;

	/**
	 * 构造。
	 *
	 * @param Settings       $settings 设置。
	 * @param BackendFactory $factory  后端工厂。
	 * @param Logger         $logger   日志。
	 * @param HtmlMinifier   $minifier HTML 压缩器。
	 */
	public function __construct( Settings $settings, BackendFactory $factory, Logger $logger, HtmlMinifier $minifier ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->logger   = $logger;
		$this->minifier = $minifier;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		// drop-in 未安装时的兜底命中检查；已安装时 drop-in 早就 exit 了，不会走到这里。
		add_action( 'init', array( $this, 'maybe_serve_from_cache' ), 1 );
		// 输出缓冲必须早于主题渲染，但晚于 WP 条件判断所需的最小初始化。
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
	}

	/**
	 * 兜底：直接从缓存吐页面（drop-in 缺失时生效）。
	 *
	 * @return void
	 */
	public function maybe_serve_from_cache() {
		if ( $this->hit_checked ) {
			return;
		}

		$this->hit_checked = true;

		$config = $this->runtime_config();

		if ( RequestGuard::should_bypass( $config ) ) {
			$this->send_header( 'BYPASS' );

			return;
		}

		$backend = $this->factory->make();

		if ( ! $backend ) {
			$this->send_header( 'BYPASS' );

			return;
		}

		$host   = RequestGuard::host();
		$uri    = RequestGuard::uri( $config );
		$mobile = $this->is_mobile_variant();
		$html   = $backend->get( $host, $uri, $mobile );

		if ( false === $html ) {
			$this->send_header( 'MISS' );

			return;
		}

		$this->send_header( 'HIT' );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-AT8-Cache-Backend: ' . $backend->name() );
			header( 'Cache-Control: public, max-age=' . (int) $this->settings->get( 'cache_ttl', 3600 ) );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 缓存内容已在写入时消毒。
		exit;
	}

	/**
	 * 启动输出缓冲。
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$config = $this->runtime_config();

		if ( RequestGuard::should_bypass( $config ) ) {
			$this->send_header( 'BYPASS' );

			return;
		}

		if ( ! $this->factory->make() ) {
			$this->send_header( 'BYPASS' );

			return;
		}

		ob_start( array( $this, 'store' ) );
	}

	/**
	 * 输出缓冲回调：校验 + 压缩 + 落盘。
	 *
	 * @param string $buffer 响应体。
	 * @return string 原样或压缩后的响应体。
	 */
	public function store( $buffer ) {
		if ( ! is_string( $buffer ) || '' === $buffer ) {
			return $buffer;
		}

		// 纵深防御：start_buffer() 已经判过一次，但 store() 是公开的 ob 回调，
		// 任何直接调用（或未来新增的调用点）都必须自己再确认一次"这次请求允许缓存"。
		// 少这一道，POST / 登录态 / 预览请求就可能被写进共享缓存。
		if ( RequestGuard::should_bypass( $this->runtime_config() ) ) {
			return $buffer;
		}

		// 到这里 WordPress 已经完全加载，可以放心用条件函数。
		if ( ! $this->should_cache_response() ) {
			return $buffer;
		}

		$backend = $this->factory->make();

		if ( ! $backend ) {
			return $buffer;
		}

		// Elementor 样式就绪护栏：宁可少缓存一次，也不吐出样式 404 的陈旧页。
		if ( $this->has_missing_elementor_css( $buffer ) ) {
			$this->logger->warning( 'Elementor post CSS 尚未就绪，放弃本次缓存', array( 'uri' => RequestGuard::uri( $this->runtime_config() ) ) );

			return $buffer;
		}

		$payload = $buffer;

		/**
		 * 过滤：允许优化器（懒加载等）在落盘前改写 HTML。
		 *
		 * 处理后的结果同时用于"写缓存"和"本次响应"，保证首个访客与后续
		 * 命中访客看到一致的页面——否则首访没有懒加载、二访才有，属于典型的不一致 bug。
		 *
		 * @param string $payload 页面 HTML。
		 */
		$payload = apply_filters( 'at8sa_after_cache_buffer', $payload );

		if ( $this->settings->is_on( 'html_minify' ) ) {
			$payload = $this->minifier->minify( $payload );
		}

		// 在 HTML 末尾留一个可诊断的指纹，便于用"查看源代码"确认命中版本。
		$payload = $this->stamp( $payload );

		$config = $this->runtime_config();
		$ttl    = max( 60, (int) $this->settings->get( 'cache_ttl', 3600 ) );

		$saved = $backend->set(
			RequestGuard::host(),
			RequestGuard::uri( $config ),
			$payload,
			$ttl,
			$this->is_mobile_variant()
		);

		if ( $saved ) {
			$this->send_header( 'MISS-SAVED' );
		}

		return $payload;
	}

	/**
	 * 响应级准入判断（WP 条件函数可用）。
	 *
	 * @return bool
	 */
	private function should_cache_response() {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		if ( is_404() || is_search() || is_feed() || is_preview() || is_trackback() ) {
			return false;
		}

		if ( is_user_logged_in() && ! $this->settings->is_on( 'cache_logged_in' ) ) {
			return false;
		}

		if ( post_password_required() ) {
			return false;
		}

		if ( ! $this->settings->is_on( 'cache_logged_in' ) && function_exists( 'is_cart' ) ) {
			// WooCommerce 动态页面：购物车 / 结算 / 我的账户永远不缓存（计划书 §67）。
			if ( ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() )
				|| ( function_exists( 'is_account_page' ) && is_account_page() )
				|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() )
			) {
				return false;
			}
		}

		/**
		 * 过滤最终准入结果，给站点留出逃生舱。
		 *
		 * @param bool $should 是否缓存。
		 */
		return (bool) apply_filters( 'at8sa_should_cache_response', true );
	}

	/**
	 * 当前是否走移动端缓存变体。
	 *
	 * @return bool
	 */
	private function is_mobile_variant() {
		if ( ! $this->settings->is_on( 'cache_mobile' ) ) {
			return false;
		}

		return RequestGuard::is_mobile();
	}

	/**
	 * 运行时配置（带进程内缓存）。
	 *
	 * @return array
	 */
	private function runtime_config() {
		static $config = null;

		if ( null === $config ) {
			$builder = new Config( $this->settings, $this->factory );
			$config  = $builder->runtime();
		}

		return $config;
	}

	/**
	 * 在 HTML 末尾插入指纹注释。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private function stamp( $html ) {
		$marker = sprintf(
			'<!-- AT8 Site Accelerator %s | cached %s -->',
			AT8SA_VERSION,
			gmdate( 'Y-m-d H:i:s' )
		);

		$pos = strripos( $html, '</html>' );

		if ( false === $pos ) {
			return $html . "\n" . $marker;
		}

		return substr( $html, 0, $pos ) . $marker . "\n" . substr( $html, $pos );
	}

	/**
	 * Elementor 样式就绪护栏（从 2.x 移植，行为保持）。
	 *
	 * 若页面引用了本站 uploads 下"此刻尚不存在"的 elementor/css/post-*.css，
	 * 说明 Elementor 正在重建样式；此时固化缓存会让访客命中样式 404 的陈旧页。
	 *
	 * @param string $html 页面 HTML。
	 * @return bool 存在引用但缺失时为 true（应放弃缓存）。
	 */
	private function has_missing_elementor_css( $html ) {
		if ( false === stripos( $html, 'elementor/css/post-' ) ) {
			return false;
		}

		$uploads = wp_upload_dir();

		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base_url = trailingslashit( $uploads['baseurl'] );
		$base_dir = trailingslashit( $uploads['basedir'] );

		$matched = preg_match_all(
			'/<link\b[^>]+rel=["\']?stylesheet["\']?[^>]+href=["\']([^"\']*elementor\/css\/post-\d+\.css(\?[^"\']*)?)["\']/i',
			$html,
			$matches
		);

		if ( ! $matched ) {
			return false;
		}

		foreach ( array_unique( $matches[1] ) as $url ) {
			$clean = preg_replace( '/\?.*$/', '', $url );

			if ( 0 !== stripos( $clean, $base_url ) ) {
				continue; // CDN / 外链一律放行。
			}

			$relative = substr( $clean, strlen( $base_url ) );

			if ( '' !== $relative && ! is_file( $base_dir . $relative ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 发送诊断响应头。
	 *
	 * @param string $state 状态。
	 * @return void
	 */
	private function send_header( $state ) {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-AT8-Cache: ' . $state );
	}
}

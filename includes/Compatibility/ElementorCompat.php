<?php
/**
 * Elementor 兼容层。
 *
 * 这是本插件最有价值、也最"血泪"的一段代码，来自 2.x 在真实站点上踩出的坑：
 *
 * **问题**：Elementor 的 `post-*.css` 是按需生成到 `uploads/elementor/css/` 的。
 * 在"保存文章"这个瞬间，存在一个极短的窗口——页面 HTML 已经引用了新的
 * `post-123.css`，但该文件还没落盘。如果整页缓存正好在这个窗口里把页面固化下来，
 * 访客就会长期命中一个样式 404 的页面，表现为"Elementor 缓存丢失 / 排版全乱"。
 *
 * **解法（三层）**：
 * 1. 写入护栏：`CacheEngine::store()` 检测到"引用了本站 uploads 下不存在的
 *    Elementor CSS"时，直接放弃本次缓存（宁可少缓存一次）。
 * 2. 版本盐：失效时递增缓存版本，旧键立即不可达，即便删除不干净也不会命中。
 * 3. 异步补生成：保存后（响应已发出）在 shutdown 阶段重建该文章的 CSS，
 *    既保证源站先有文件，又绝不阻塞保存请求——2.x 曾因为同步重建导致 nginx 502。
 *
 * @package AT8SA\Compatibility
 */

namespace AT8SA\Compatibility;

use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class ElementorCompat
 */
final class ElementorCompat {

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 本次请求已安排过异步重建的文章。
	 *
	 * @var array<int, bool>
	 */
	private $scheduled = array();

	/**
	 * 构造。
	 *
	 * @param Logger $logger 日志。
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Elementor 是否已激活。
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'save_post', array( $this, 'maybe_schedule_css_rebuild' ), 998, 3 );
	}

	/**
	 * 安排异步重建 Elementor CSS。
	 *
	 * @param int           $post_id 文章 ID。
	 * @param \WP_Post|null $post    文章。
	 * @param bool          $update  是否更新。
	 * @return void
	 */
	public function maybe_schedule_css_rebuild( $post_id, $post = null, $update = false ) {
		unset( $update );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// 只有 Elementor 真正接管过的文章才需要。
		if ( ! get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return;
		}

		// 编辑器保存场景：Elementor 自身已完成 CSS 重建，我们不要重复劳动
		// （同步重算会拖垮保存 AJAX，2.x 的 502 就是这么来的）。
		if ( $this->is_editor_save_request() ) {
			return;
		}

		if ( isset( $this->scheduled[ $post_id ] ) ) {
			return;
		}

		$this->scheduled[ $post_id ] = true;

		$post_id = (int) $post_id;

		register_shutdown_function(
			function () use ( $post_id ) {
				// 先把响应交给浏览器，再跑重活。
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}

				$this->rebuild_post_css( $post_id );
			}
		);
	}

	/**
	 * 重建单篇文章的 Elementor CSS。
	 *
	 * 注意是**单篇**重建，不是 Elementor 的 `clear_cache()` 全量重建——
	 * 全量重建在文章多的站点上必然超时。
	 *
	 * @param int $post_id 文章 ID。
	 * @return bool
	 */
	public function rebuild_post_css( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( ! isset( $plugin->files_manager ) || ! method_exists( $plugin->files_manager, 'generate_css' ) ) {
			return false;
		}

		if ( ! get_post_meta( (int) $post_id, '_elementor_edit_mode', true ) ) {
			return false;
		}

		try {
			$plugin->files_manager->generate_css( (int) $post_id );

			return true;
		} catch ( \Throwable $e ) {
			// 失败不阻断发布主流程。走 Logger 而不是裸 error_log()：
			// Logger 会按 log_enabled / log_level 决定是否落盘，并做密钥脱敏
			// （计划书 §78 / §79）。裸 error_log() 两样都没有。
			$this->logger->error(
				'Elementor CSS 重建失败',
				array(
					'post_id' => (int) $post_id,
					'reason'  => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * 当前是否处于 Elementor 编辑器保存请求。
	 *
	 * @return bool
	 */
	private function is_editor_save_request() {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$action = isset( $_POST['action'] ) ? sanitize_key( (string) $_POST['action'] ) : '';

		if ( '' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		}

		return '' !== $action && false !== strpos( $action, 'elementor' );
	}
}

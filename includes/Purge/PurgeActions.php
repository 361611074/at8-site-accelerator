<?php
/**
 * 失效触发器：把 WordPress 的生命周期事件映射到 Purger 的精准失效方法。
 *
 * 与 2.x 最大的行为差异：**默认不再"保存文章 = 整站清空"**。
 * 默认 `purge_scope = related`，只失效与该文章真正相关的页面（计划书 §64）。
 * 需要旧行为的用户可以在设置里切回 `all`。
 *
 * @package AT8SA\Purge
 */

namespace AT8SA\Purge;

use AT8SA\Core\Settings;
use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class PurgeActions
 */
final class PurgeActions {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 失效器。
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 本次请求内已失效过的文章：`文章 ID => 内容指纹`。
	 *
	 * 为什么不是简单的 `ID => true`（真机实测到的缺陷）：
	 * 一次保存会连续触发 `transition_post_status` → `publish_post` → `save_post`
	 * 三次回调，必须去重，否则同一篇文章要清三遍缓存。
	 * 但"同一个请求"并不等于"同一次保存"——批量脚本、导入器，以及"在 `save_post`
	 * 里再调一次 `wp_update_post()`"的插件，都会在同一个请求内对同一篇文章发起
	 * **多次内容不同的保存**。只按 ID 去重会把后面几次真实改动一并吞掉：
	 * 缓存里留着上一版页面，直到 TTL 过期才被纠正。
	 * 实测：同一个请求内先把标题改成 A 再改成 B，缓存里始终是 A。
	 *
	 * 指纹里带上修改时间与标题/正文等字段，于是：
	 * - 同一次保存的那三次回调指纹相同 → 仍然只失效一次（去重目的不变）；
	 * - 同请求内的第二次真实保存指纹不同 → 会再失效一次。
	 *
	 * @var array<int, string>
	 */
	private $handled = array();

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 * @param Purger   $purger   失效器。
	 * @param Logger   $logger   日志。
	 */
	public function __construct( Settings $settings, Purger $purger, Logger $logger ) {
		$this->settings = $settings;
		$this->purger   = $purger;
		$this->logger   = $logger;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		// 内容变更：统一 999 优先级，确保 Elementor 等插件已写完数据再失效。
		add_action( 'save_post', array( $this, 'on_save_post' ), 999, 3 );
		add_action( 'publish_post', array( $this, 'on_save_post' ), 999, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 999, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 1 );

		// 结构变更：必须整站失效。
		$global_events = array(
			'switch_theme',
			'activated_plugin',
			'deactivated_plugin',
			'upgrader_process_complete',
			'wp_update_nav_menu',
			'customize_save_after',
			'elementor/editor/after_save',
			'elementor/core/files/clear_cache',
			'at8sa_settings_saved',
		);

		foreach ( $global_events as $event ) {
			add_action( $event, array( $this, 'on_global_event' ), 999 );
		}

		// 评论：只失效该文章相关页面。
		add_action( 'comment_post', array( $this, 'on_comment' ), 999, 3 );
		add_action( 'wp_set_comment_status', array( $this, 'on_comment_status' ), 999, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_status' ), 999, 2 );

		// 分类项变更。
		add_action( 'created_term', array( $this, 'on_term' ), 999, 3 );
		add_action( 'edited_term', array( $this, 'on_term' ), 999, 3 );
		add_action( 'delete_term', array( $this, 'on_term' ), 999, 3 );
	}

	/**
	 * save_post / publish_post 回调。
	 *
	 * @param int           $post_id 文章 ID。
	 * @param \WP_Post|null $post    文章对象。
	 * @param bool          $update  是否更新。
	 * @return void
	 */
	public function on_save_post( $post_id, $post = null, $update = false ) {
		unset( $update );

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->maybe_purge_post( $post );
	}

	/**
	 * transition_post_status 回调。
	 *
	 * @param string        $new_status 新状态。
	 * @param string        $old_status 旧状态。
	 * @param \WP_Post|null $post       文章。
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// 草稿之间的来回切换不影响前台，跳过。
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		$this->maybe_purge_post( $post );
	}

	/**
	 * 文章删除前失效（删除后就取不到 permalink 了）。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public function on_delete_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->maybe_purge_post( $post );
	}

	/**
	 * 统一的文章失效入口。
	 *
	 * @param \WP_Post $post 文章。
	 * @return void
	 */
	private function maybe_purge_post( \WP_Post $post ) {
		// 自动保存 / 修订版：编辑过程中每次按键都清缓存是 2.x 的痛点，这里直接跳过。
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		// 非公开状态的内容不进前台缓存。
		if ( ! in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			return;
		}

		// 附件不需要单独失效（它挂在文章页里）。
		if ( 'attachment' === $post->post_type ) {
			return;
		}

		$fingerprint = $this->fingerprint( $post );

		if ( isset( $this->handled[ $post->ID ] ) && $this->handled[ $post->ID ] === $fingerprint ) {
			return;
		}

		$this->handled[ $post->ID ] = $fingerprint;

		// 这是一次新的保存：把"已失效 URL"的备忘作废。
		// 上一次保存之后预热器已经把页面重新写回缓存，若沿用备忘，
		// 本次失效会被静默跳过，缓存里就会留下上一版的页面。
		$this->purger->reset_purged();

		/**
		 * 过滤是否对该文章执行失效。
		 *
		 * @param bool     $should 是否失效。
		 * @param \WP_Post $post   文章。
		 */
		if ( ! apply_filters( 'at8sa_should_purge_post', true, $post ) ) {
			return;
		}

		if ( 'all' === $this->settings->get( 'purge_scope', 'related' ) ) {
			$this->purger->purge_all();

			return;
		}

		$count = $this->purger->purge_post( $post->ID );

		if ( $this->settings->is_on( 'purge_home_on_save' ) ) {
			$this->purger->purge_home();
		}

		$this->logger->info(
			'文章相关缓存已失效',
			array(
				'post_id' => $post->ID,
				'urls'    => $count,
			)
		);
	}

	/**
	 * 内容指纹：区分"同一次保存的多次回调"与"同一请求内的多次保存"。
	 *
	 * `post_modified_gmt` 只有秒级精度，同一秒内的两次改动会得到相同的时间戳，
	 * 所以必须把正文相关字段一起算进来。
	 *
	 * @param \WP_Post $post 文章。
	 * @return string
	 */
	private function fingerprint( \WP_Post $post ) {
		return md5(
			implode(
				'|',
				array(
					(string) $post->post_modified_gmt,
					(string) $post->post_status,
					(string) $post->post_title,
					(string) $post->post_name,
					(string) $post->post_content,
					(string) $post->post_excerpt,
				)
			)
		);
	}

	/**
	 * 结构性事件 → 整站失效。
	 *
	 * @return void
	 */
	public function on_global_event() {
		$this->purger->purge_all();
	}

	/**
	 * 新评论 → 失效对应文章。
	 *
	 * @param int        $comment_id 评论 ID。
	 * @param int|string $approved   审核状态。
	 * @param array      $data       评论数据。
	 * @return void
	 */
	public function on_comment( $comment_id, $approved = 0, $data = array() ) {
		unset( $data );

		if ( 1 !== (int) $approved && '1' !== (string) $approved ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		$post_id = (int) $comment->comment_post_ID;

		if ( $post_id > 0 ) {
			$this->purger->purge_post( $post_id );
		}
	}

	/**
	 * 评论状态变更 → 失效对应文章。
	 *
	 * @param int $comment_id 评论 ID。
	 * @param int $status     状态。
	 * @return void
	 */
	public function on_comment_status( $comment_id, $status = 0 ) {
		unset( $status );

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		$post_id = (int) $comment->comment_post_ID;

		if ( $post_id > 0 ) {
			$this->purger->purge_post( $post_id );
		}
	}

	/**
	 * 分类项变更 → 失效该归档（而非整站）。
	 *
	 * @param int    $term_id  分类 ID。
	 * @param int    $tt_id    分类项 ID。
	 * @param string $taxonomy 分类法。
	 * @return void
	 */
	public function on_term( $term_id, $tt_id = 0, $taxonomy = 'category' ) {
		unset( $tt_id );

		if ( ! $taxonomy ) {
			return;
		}

		$this->purger->purge_taxonomy( (int) $term_id, $taxonomy );
	}
}

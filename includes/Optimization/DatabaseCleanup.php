<?php
/**
 * 数据库清理。
 *
 * **这是本项目里唯一的破坏性功能**，因此设计上刻意"啰嗦"：
 * - 默认全部关闭，必须用户逐项显式勾选；
 * - 提供 `preview()` 让后台先展示"将要删除多少条"，用户看到数字再决定；
 * - 每一项都返回实际删除条数，前台给明确回执，不做"已清理"这种糊弄式提示；
 * - 定时任务默认关闭，且只执行用户已经勾选过的项；
 * - 清理前不额外备份（那属于站点备份职责），但**绝不触碰** wp_posts 中非修订/非草稿的内容。
 *
 * @package AT8SA\Optimization
 */

namespace AT8SA\Optimization;

use AT8SA\Core\Settings;
use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class DatabaseCleanup
 */
final class DatabaseCleanup {

	/**
	 * 定时任务钩子。
	 */
	const CRON_HOOK = 'at8sa_db_cleanup_event';

	/**
	 * 单次运行最多删除的行数，避免超大站点一次跑挂。
	 */
	const BATCH_LIMIT = 5000;

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 * @param Logger   $logger   日志。
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'sync_schedule' ), 20 );
	}

	/**
	 * 同步定时任务与设置。
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$schedule = (string) $this->settings->get( 'db_schedule', 'off' );
		$next     = wp_next_scheduled( self::CRON_HOOK );

		if ( 'off' === $schedule ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );
			}

			return;
		}

		if ( $next ) {
			return;
		}

		$recurrence = 'weekly' === $schedule ? 'weekly' : 'daily';
		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::CRON_HOOK );
	}

	/**
	 * 定时执行。
	 *
	 * @return void
	 */
	public function run_scheduled() {
		if ( 'off' === (string) $this->settings->get( 'db_schedule', 'off' ) ) {
			return;
		}

		$result = $this->run();

		$this->logger->info( '定时数据库清理完成', $result );
	}

	/**
	 * 预览：返回每项将要清理的条数。
	 *
	 * @return array<string, array{label:string,count:int,enabled:bool,danger:bool}>
	 */
	public function preview() {
		global $wpdb;

		$items = array();

		// 文章修订版。
		$items['db_revisions'] = array(
			'label'   => __( '文章修订版', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'enabled' => $this->settings->is_on( 'db_revisions' ),
			'danger'  => false,
		);

		// 自动草稿。
		$items['db_auto_drafts'] = array(
			'label'   => __( '自动草稿', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'enabled' => $this->settings->is_on( 'db_auto_drafts' ),
			'danger'  => true,
		);

		// 回收站文章。
		$items['db_trashed_posts'] = array(
			'label'   => __( '回收站中的文章', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'enabled' => $this->settings->is_on( 'db_trashed_posts' ),
			'danger'  => true,
		);

		// 垃圾评论。
		$items['db_spam_comments'] = array(
			'label'   => __( '垃圾评论', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'enabled' => $this->settings->is_on( 'db_spam_comments' ),
			'danger'  => true,
		);

		// 回收站评论。
		$items['db_trashed_comments'] = array(
			'label'   => __( '回收站中的评论', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'enabled' => $this->settings->is_on( 'db_trashed_comments' ),
			'danger'  => true,
		);

		// 过期 transient。
		$items['db_transients'] = array(
			'label'   => __( '过期 transient', 'at8-site-accelerator' ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 后台预览用的实时计数，缓存它只会让用户看到过期数字。
			'count'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$wpdb->esc_like( '_transient_timeout_' ) . '%',
					time()
				)
			),
			'enabled' => $this->settings->is_on( 'db_transients' ),
			'danger'  => false,
		);

		// 表碎片。
		$items['db_optimize'] = array(
			'label'   => __( '优化数据表（消除碎片）', 'at8-site-accelerator' ),
			'count'   => count( $this->tables() ),
			'enabled' => $this->settings->is_on( 'db_optimize' ),
			'danger'  => false,
		);

		return $items;
	}

	/**
	 * 执行清理。
	 *
	 * @return array<string, int> 各项实际删除/处理的条数。
	 */
	public function run() {
		global $wpdb;

		$result = array();

		// 修订版。
		if ( $this->settings->is_on( 'db_revisions' ) ) {
			$result['db_revisions'] = $this->delete_posts_by_type( 'revision' );
		}

		// 自动草稿。
		if ( $this->settings->is_on( 'db_auto_drafts' ) ) {
			$result['db_auto_drafts'] = $this->delete_posts_by_status( 'auto-draft' );
		}

		// 回收站文章。
		if ( $this->settings->is_on( 'db_trashed_posts' ) ) {
			$result['db_trashed_posts'] = $this->delete_posts_by_status( 'trash' );
		}

		// 垃圾评论。
		if ( $this->settings->is_on( 'db_spam_comments' ) ) {
			$result['db_spam_comments'] = $this->delete_comments_by_status( 'spam' );
		}

		// 回收站评论。
		if ( $this->settings->is_on( 'db_trashed_comments' ) ) {
			$result['db_trashed_comments'] = $this->delete_comments_by_status( 'trash' );
		}

		// 过期 transient。
		if ( $this->settings->is_on( 'db_transients' ) ) {
			$result['db_transients'] = $this->delete_expired_transients();
		}

		// 表优化。
		if ( $this->settings->is_on( 'db_optimize' ) ) {
			$result['db_optimize'] = $this->optimize_tables();
		}

		$this->logger->info( '数据库清理完成', $result );

		/**
		 * 数据库清理后触发。
		 *
		 * @param array $result 各项结果。
		 */
		do_action( 'at8sa_db_cleanup_done', $result );

		return $result;
	}

	/**
	 * 删除指定 post_type 的文章（修订版）。
	 *
	 * @param string $post_type 类型。
	 * @return int
	 */
	private function delete_posts_by_type( $post_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 取待删除 ID 列表，缓存会导致删到已经不存在的行。
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT %d",
				$post_type,
				self::BATCH_LIMIT
			)
		);

		return $this->force_delete_posts( $ids );
	}

	/**
	 * 删除指定 post_status 的文章。
	 *
	 * @param string $status 状态。
	 * @return int
	 */
	private function delete_posts_by_status( $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 取待删除 ID 列表，缓存会导致删到已经不存在的行。
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type NOT IN ('attachment','revision') LIMIT %d",
				$status,
				self::BATCH_LIMIT
			)
		);

		return $this->force_delete_posts( $ids );
	}

	/**
	 * 强制删除文章（绕过回收站），并清理关联元数据与分类关系。
	 *
	 * @param array $ids 文章 ID 列表。
	 * @return int
	 */
	private function force_delete_posts( $ids ) {
		global $wpdb;

		$ids = array_map( 'intval', (array) $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $ids as $id ) {
			// 用 WP 官方 API 删除，保证 term_relationships / postmeta 一并清理，
			// 而不是裸 SQL 留下孤儿数据。
			$result = wp_delete_post( $id, true );

			if ( $result ) {
				++$deleted;
			}
		}

		// 兜底清孤儿元数据（历史上被裸 SQL 删过的站点会残留）。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );

		return $deleted;
	}

	/**
	 * 删除指定状态的评论。
	 *
	 * @param string $status 状态。
	 * @return int
	 */
	private function delete_comments_by_status( $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 取待删除 ID 列表，缓存会导致删到已经不存在的行。
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d",
				$status,
				self::BATCH_LIMIT
			)
		);

		$deleted = 0;

		foreach ( (array) $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * 删除过期 transient（含 timeout 与 value 成对清理）。
	 *
	 * @return int
	 */
	private function delete_expired_transients() {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 取待删除的过期 transient 名单，缓存会导致删到已经不存在的行。
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now,
				self::BATCH_LIMIT
			)
		);

		$deleted = 0;

		foreach ( (array) $names as $timeout_name ) {
			$key = str_replace( '_transient_timeout_', '', (string) $timeout_name );

			if ( delete_transient( $key ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * 优化数据表。
	 *
	 * @return int 处理的表数量。
	 */
	private function optimize_tables() {
		global $wpdb;

		$count = 0;

		foreach ( $this->tables() as $table ) {
			// 表名由 WordPress 的 tables() 接口给出，不来自用户输入，没有注入面。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' );
			++$count;
		}

		return $count;
	}

	/**
	 * 站点全部数据表。
	 *
	 * @return array
	 */
	private function tables() {
		global $wpdb;

		return array_values( (array) $wpdb->tables( 'all', false ) );
	}
}

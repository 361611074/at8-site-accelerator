<?php
/**
 * 缓存失效器。
 *
 * 计划书 §64 明确禁止"任何小修改 → 清空整个缓存"，要求优先局部失效。
 * 因此这里把失效拆成一组粒度明确的方法：
 *
 *   purge_all()       整站（仅用于：切换主题 / 启停插件 / 用户手动点击）
 *   purge_url()       单个 URL
 *   purge_urls()      一批 URL
 *   purge_post()      一篇文章 + 它的所有关联页面（首页/归档/分类/作者）
 *   purge_home()      首页
 *   purge_archive()   归档
 *   purge_taxonomy()  分类法归档
 *   purge_author()    作者归档
 *
 * 每次失效都会先把"关联 URL 集合"算出来，再交给后端做精确删除。
 *
 * @package AT8\SiteAccelerator\Purge
 */

namespace AT8\SiteAccelerator\Purge;

use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Cache\CachePath;
use AT8\SiteAccelerator\Cache\Config;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Support\Filesystem;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class Purger
 */
final class Purger {

	/**
	 * 单次失效允许生成的 URL 上限，防止超大站点的分类归档把内存吃光。
	 */
	const MAX_URLS = 300;

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
	 * 请求内已失效过的 URL，避免同一次请求反复删同一目录。
	 *
	 * @var array<string, bool>
	 */
	private $purged = array();

	/**
	 * 构造。
	 *
	 * @param Settings       $settings 设置。
	 * @param BackendFactory $factory  后端工厂。
	 * @param Logger         $logger   日志。
	 */
	public function __construct( Settings $settings, BackendFactory $factory, Logger $logger ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->logger   = $logger;
	}

	/**
	 * 整站失效。
	 *
	 * 顺序很关键：**先递增缓存版本盐**，再 flush。这样即便 flush 因权限/连接问题
	 * 半途失败，旧键也已经在语义上不可达，访客不会命中陈旧页。
	 *
	 * @return int 删除条目数（磁盘后端为删除的页面文件数，无法精确统计时为 0）。
	 */
	public function purge_all() {
		$this->factory->bump_cache_version();

		$backend = $this->factory->make();
		$count   = 0;

		if ( $backend ) {
			$stats = $backend->stats();
			$count = isset( $stats['count'] ) ? (int) $stats['count'] : 0;

			$backend->flush();
		}

		$this->purge_legacy_dirs();
		$this->factory->reset_probe();
		$this->purged = array();

		// 缓存版本盐变了，drop-in 的运行时配置必须同步重写，否则它仍按旧盐读键。
		$this->write_runtime_config();

		$this->logger->info( '整站缓存已失效', array( 'entries' => $count ) );

		/**
		 * 整站缓存失效后触发，供 CDN 等外部系统联动。
		 *
		 * @param int $count 失效条目数。
		 */
		do_action( 'at8sa_purged_all', $count );

		return $count;
	}

	/**
	 * 单个 URL 失效。
	 *
	 * @param string $url 绝对 URL 或站内路径。
	 * @return bool
	 */
	public function purge_url( $url ) {
		return $this->purge_urls( array( $url ) ) >= 0;
	}

	/**
	 * 批量 URL 失效。
	 *
	 * @param array $urls URL 列表。
	 * @return int 处理过的 URL 数量。
	 */
	public function purge_urls( array $urls ) {
		$backend = $this->factory->make();

		if ( ! $backend ) {
			return 0;
		}

		$by_host = array();
		$count   = 0;

		foreach ( $urls as $url ) {
			$target = $this->resolve( $url );

			if ( null === $target ) {
				continue;
			}

			$dedupe = $target['host'] . '|' . $target['uri'];

			if ( isset( $this->purged[ $dedupe ] ) ) {
				continue;
			}

			$this->purged[ $dedupe ] = true;

			if ( ! isset( $by_host[ $target['host'] ] ) ) {
				$by_host[ $target['host'] ] = array();
			}

			$by_host[ $target['host'] ][] = $target['uri'];
			++$count;
		}

		foreach ( $by_host as $host => $uris ) {
			$backend->delete_urls( $host, $uris );
		}

		return $count;
	}

	/**
	 * 文章相关页面失效。
	 *
	 * @param int $post_id 文章 ID。
	 * @return int 失效 URL 数。
	 */
	public function purge_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return 0;
		}

		$urls = $this->related_urls( $post_id );

		/**
		 * 过滤单篇文章失效的关联 URL 集合。
		 *
		 * @param array $urls    URL 列表。
		 * @param int   $post_id 文章 ID。
		 */
		$urls = apply_filters( 'at8sa_post_purge_urls', $urls, $post_id );

		return $this->purge_urls( array_slice( array_unique( $urls ), 0, self::MAX_URLS ) );
	}

	/**
	 * 首页失效。
	 *
	 * @return int
	 */
	public function purge_home() {
		$urls = array( home_url( '/' ) );

		// 静态首页 + 分页。
		$per_page = (int) get_option( 'posts_per_page', 10 );
		$total    = (int) wp_count_posts( 'post' )->publish;
		$pages    = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$last     = min( $pages, 10 );

		for ( $page = 2; $page <= $last; $page++ ) {
			$urls[] = get_pagenum_link( $page );
		}

		return $this->purge_urls( $urls );
	}

	/**
	 * 某文章类型的归档失效。
	 *
	 * @param string $post_type 文章类型。
	 * @return int
	 */
	public function purge_archive( $post_type = 'post' ) {
		$urls = array();

		$archive = get_post_type_archive_link( $post_type );

		if ( $archive ) {
			$urls[] = $archive;
		}

		if ( 'post' === $post_type ) {
			$urls[] = get_post_type_archive_link( 'post' );

			// 日期归档：按月归档是 WP 默认结构，逐月生成代价高，这里只清当年。
			$year   = gmdate( 'Y' );
			$urls[] = home_url( '/' . $year . '/' );
			$urls[] = get_year_link( (int) $year );
		}

		$urls = array_filter( array_unique( $urls ) );

		return $this->purge_urls( $urls );
	}

	/**
	 * 分类法归档失效（含父级与分页）。
	 *
	 * @param int    $term_id  分类 ID。
	 * @param string $taxonomy 分类法。
	 * @return int
	 */
	public function purge_taxonomy( $term_id, $taxonomy = 'category' ) {
		$urls = $this->term_urls( (int) $term_id, $taxonomy );

		return $this->purge_urls( $urls );
	}

	/**
	 * 作者归档失效。
	 *
	 * @param int $author_id 作者 ID。
	 * @return int
	 */
	public function purge_author( $author_id ) {
		$author_id = (int) $author_id;
		$urls      = array();

		$link = get_author_posts_url( $author_id );

		if ( $link ) {
			$urls[] = $link;
			$urls   = array_merge( $urls, $this->pagination_urls( $link, 10 ) );
		}

		return $this->purge_urls( $urls );
	}

	/**
	 * 后端状态快照（REST / 后台展示共用）。
	 *
	 * @return array
	 */
	public function backend_status() {
		$backend = $this->factory->make();

		if ( ! $backend ) {
			return array(
				'active'          => false,
				'backend'         => 'none',
				'cached_pages'    => 0,
				'cache_bytes'     => 0,
				'redis_reachable' => $this->factory->redis_probe(),
			);
		}

		$stats = $backend->stats();

		return array(
			'active'          => true,
			'backend'         => $backend->name(),
			'cached_pages'    => isset( $stats['count'] ) ? (int) $stats['count'] : 0,
			'cache_bytes'     => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
			'cache_version'   => $this->factory->cache_version(),
			'redis_reachable' => $this->factory->redis_probe(),
		);
	}

	/**
	 * 清理 2.x 遗留的缓存目录（升级后一次性）。
	 *
	 * @return void
	 */
	public function purge_legacy_dirs() {
		$legacy = array(
			WP_CONTENT_DIR . '/cache/site-accelerator',
			WP_CONTENT_DIR . '/cache/nginx',
		);

		foreach ( $legacy as $dir ) {
			if ( is_dir( $dir ) && Filesystem::is_inside_cache_root( $dir ) ) {
				Filesystem::rrmdir( $dir );
			}
		}
	}

	/**
	 * 计算一篇文章的所有关联 URL。
	 *
	 * @param int $post_id 文章 ID。
	 * @return array
	 */
	private function related_urls( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$urls = array( home_url( '/' ) );

		$permalink = get_permalink( $post );

		if ( $permalink ) {
			$urls[] = $permalink;
		}

		// 文章类型归档。
		$archive = get_post_type_archive_link( $post->post_type );

		if ( $archive ) {
			$urls[] = $archive;
			$urls   = array_merge( $urls, $this->pagination_urls( $archive, 5 ) );
		}

		// 该文章所属的所有分类法归档（含父级）。
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_taxonomy_viewable( $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$urls = array_merge( $urls, $this->term_urls( (int) $term->term_id, $taxonomy ) );
			}
		}

		// 作者归档。
		$author_link = get_author_posts_url( (int) $post->post_author );

		if ( $author_link ) {
			$urls[] = $author_link;
		}

		// 首页分页（新文章会改变第 1、2 页的内容）。
		$urls = array_merge( $urls, $this->pagination_urls( home_url( '/' ), 3 ) );

		return array_filter( array_unique( $urls ) );
	}

	/**
	 * 分类项归档 URL（含祖先与分页）。
	 *
	 * @param int    $term_id  分类 ID。
	 * @param string $taxonomy 分类法。
	 * @return array
	 */
	private function term_urls( $term_id, $taxonomy ) {
		$urls = array();

		$link = get_term_link( $term_id, $taxonomy );

		if ( is_wp_error( $link ) || ! $link ) {
			return $urls;
		}

		$urls[] = $link;
		$urls   = array_merge( $urls, $this->pagination_urls( $link, 5 ) );

		// 父级归档会列出子分类的文章，必须一并失效。
		$ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );

		foreach ( $ancestors as $ancestor_id ) {
			$ancestor_link = get_term_link( (int) $ancestor_id, $taxonomy );

			if ( ! is_wp_error( $ancestor_link ) && $ancestor_link ) {
				$urls[] = $ancestor_link;
			}
		}

		return $urls;
	}

	/**
	 * 生成分页 URL。
	 *
	 * @param string $base 基准 URL。
	 * @param int    $max  最多生成几页。
	 * @return array
	 */
	private function pagination_urls( $base, $max = 5 ) {
		$urls = array();
		$base = trailingslashit( $base );
		$last = max( 1, (int) $max );

		for ( $page = 2; $page <= $last; $page++ ) {
			$urls[] = $base . 'page/' . $page . '/';
		}

		return $urls;
	}

	/**
	 * 把任意形式的 URL 归一化成 (host, uri)。
	 *
	 * 站外 URL 直接返回 null——我们只清理自己站点的缓存。
	 *
	 * @param string $url URL 或路径。
	 * @return array{host:string,uri:string}|null
	 */
	private function resolve( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return null;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $site_host && strtolower( $site_host ) !== strtolower( $parts['host'] ) ) {
			return null;
		}

		$uri = isset( $parts['path'] ) ? $parts['path'] : '/';

		if ( ! empty( $parts['query'] ) ) {
			$uri .= '?' . $parts['query'];
		}

		return array(
			'host' => CachePath::normalize_host( $parts['host'] ),
			'uri'  => CachePath::normalize_uri( $uri ),
		);
	}

	/**
	 * 失效后重写 drop-in 运行时配置（缓存版本盐变了）。
	 *
	 * @return void
	 */
	private function write_runtime_config() {
		$builder = new Config( $this->settings, $this->factory );
		$builder->write( $builder->runtime() );
	}
}

<?php
/**
 * 后端工厂：按设置选择 Redis / 磁盘，并在 Redis 不可达时安全降级。
 *
 * 探测结果用 transient 缓存，避免每个请求都吃一次 1 秒的 fsockopen 超时
 * （2.x 的老问题，这里保留并强化）。
 *
 * @package AT8SA\Cache\Backend
 */

namespace AT8SA\Cache\Backend;

use AT8SA\Core\Settings;
use AT8SA\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class BackendFactory
 */
final class BackendFactory {

	/**
	 * Redis 探测结果 transient 键。
	 */
	const PROBE_KEY = 'at8sa_redis_probe';

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
	 * 进程内单例。
	 *
	 * @var BackendInterface|false|null
	 */
	private $resolved = null;

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
	 * 取后端。返回 false 表示"本次请求不应缓存"。
	 *
	 * @return BackendInterface|false
	 */
	public function make() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		if ( ! $this->settings->is_on( 'page_cache' ) || $this->settings->is_on( 'safe_mode' ) ) {
			$this->resolved = false;

			return false;
		}

		$mode = (string) $this->settings->get( 'cache_backend', 'auto' );

		if ( 'disk' === $mode ) {
			$this->resolved = $this->make_disk();

			return $this->resolved;
		}

		if ( 'redis' === $mode ) {
			$redis = $this->make_redis();

			if ( $redis->available() ) {
				$this->resolved = $redis;

				return $this->resolved;
			}

			$this->logger->warning( 'cache_backend=redis 但 Redis 不可达，本请求不缓存' );
			$this->resolved = false;

			return false;
		}

		// auto：Redis 优先，降级磁盘。
		if ( $this->redis_probe() ) {
			$redis = $this->make_redis();

			if ( $redis->available() ) {
				$this->resolved = $redis;

				return $this->resolved;
			}

			$this->set_probe( false );
		}

		$this->resolved = $this->make_disk();

		return $this->resolved;
	}

	/**
	 * 站点盐：站点令牌 + 缓存版本。
	 *
	 * 缓存版本自增可让"删不干净"的旧键立即不可达——比单纯 flush 更硬的安全网。
	 *
	 * @return string
	 */
	public function salt() {
		return $this->site_token() . '|v' . $this->cache_version();
	}

	/**
	 * 站点令牌：隔离同服务器多站点。
	 *
	 * @return string
	 */
	public function site_token() {
		if ( defined( 'COOKIEHASH' ) && COOKIEHASH ) {
			return (string) COOKIEHASH;
		}

		return md5( (string) home_url() );
	}

	/**
	 * 当前缓存版本。
	 *
	 * @return int
	 */
	public function cache_version() {
		return max( 1, (int) get_option( 'at8sa_cache_version', 1 ) );
	}

	/**
	 * 递增缓存版本。
	 *
	 * 盐（`site_token()|v<版本>`）是后端实例的构造参数，所以改完版本必须把
	 * **已记忆化的后端实例丢掉**（`$this->resolved = null`）。否则同一请求内
	 * 后续的读写仍落在旧盐命名空间：
	 * - 写进去的条目 drop-in 永远读不到（它按新盐读）→ 表现为"缓存了却永远不命中"；
	 * - `backend_status()['cached_pages']` 会统计旧盐键 → 恒为 0，诊断信息失真。
	 *
	 * 这个缺陷在真机（Redis 后端）实测到：purge_all() 后同请求统计条目数返回 0，
	 * 而 redis-cli 里实际有 71 个新盐键。
	 *
	 * @return int
	 */
	public function bump_cache_version() {
		$next = $this->cache_version() + 1;

		update_option( 'at8sa_cache_version', $next, false );

		// 盐变了，绑在旧盐上的后端实例立即作废。
		$this->resolved = null;

		return $next;
	}

	/**
	 * Redis 是否可达（带 transient 缓存）。
	 *
	 * @return bool
	 */
	public function redis_probe() {
		$probe = get_transient( self::PROBE_KEY );

		if ( 'yes' === $probe ) {
			return true;
		}

		if ( 'no' === $probe ) {
			return false;
		}

		$redis = $this->make_redis();
		$ok    = $redis->available();

		$this->set_probe( $ok );

		return $ok;
	}

	/**
	 * 写探测结果。
	 *
	 * @param bool $ok 是否可达。
	 * @return void
	 */
	private function set_probe( $ok ) {
		set_transient( self::PROBE_KEY, $ok ? 'yes' : 'no', $ok ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
	}

	/**
	 * 清探测缓存。
	 *
	 * @return void
	 */
	public function reset_probe() {
		delete_transient( self::PROBE_KEY );
	}

	/**
	 * 构造磁盘后端。
	 *
	 * @return DiskBackend
	 */
	private function make_disk() {
		return new DiskBackend( AT8SA_CACHE_ROOT );
	}

	/**
	 * 构造 Redis 后端。
	 *
	 * 恒定返回实例、不返回 null —— 连接是惰性的（`RedisBackend::available()`
	 * 才真正拨号），所以"构造成功"与"Redis 可达"是两件事，不要在这里混。
	 *
	 * @return RedisBackend
	 */
	private function make_redis() {
		/**
		 * 过滤 Redis 连接参数，方便用户在 wp-config.php 里覆盖。
		 *
		 * @param array $args 连接参数。
		 */
		$args = apply_filters(
			'at8sa_redis_args',
			array(
				'host' => defined( 'AT8SA_REDIS_HOST' ) ? AT8SA_REDIS_HOST : '127.0.0.1',
				'port' => defined( 'AT8SA_REDIS_PORT' ) ? AT8SA_REDIS_PORT : 6379,
				'db'   => defined( 'AT8SA_REDIS_DB' ) ? AT8SA_REDIS_DB : 2,
			)
		);

		return new RedisBackend(
			$this->salt(),
			isset( $args['host'] ) ? $args['host'] : '127.0.0.1',
			isset( $args['port'] ) ? (int) $args['port'] : 6379,
			isset( $args['db'] ) ? (int) $args['db'] : 2
		);
	}

	/**
	 * 当前实际使用的后端名称（用于后台展示）。
	 *
	 * @return string
	 */
	public function active_name() {
		$backend = $this->make();

		if ( ! $backend ) {
			return 'none';
		}

		return $backend->name();
	}
}

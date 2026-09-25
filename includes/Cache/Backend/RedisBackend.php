<?php
/**
 * Redis 缓存后端。
 *
 * 关键约束（继承 2.x 踩过的坑）：**绝不用 FLUSHDB**。同一台 Redis 上往往跑着
 * 多个站点，FLUSHDB 会连别人的缓存一起清掉。这里用"站点盐前缀 + 索引集合"
 * 双保险：先按索引集合精确 DEL，再用 SCAN 兜底扫一遍游离键。
 *
 * @package AT8\SiteAccelerator\Cache\Backend
 */

namespace AT8\SiteAccelerator\Cache\Backend;

use AT8\SiteAccelerator\Cache\CachePath;
use AT8\SiteAccelerator\Support\RedisClient;

defined( 'ABSPATH' ) || exit;

/**
 * Class RedisBackend
 */
final class RedisBackend implements BackendInterface {

	/**
	 * 站点盐（站点令牌 + 缓存版本），用于隔离多站点。
	 *
	 * @var string
	 */
	private $salt;

	/**
	 * Redis 客户端。
	 *
	 * @var RedisClient
	 */
	private $client;

	/**
	 * 构造。
	 *
	 * @param string $salt   站点盐。
	 * @param string $host   Redis 主机。
	 * @param int    $port   Redis 端口。
	 * @param int    $db     逻辑库。
	 */
	public function __construct( $salt, $host = '127.0.0.1', $port = 6379, $db = 2 ) {
		$this->salt   = (string) $salt;
		$this->client = new RedisClient( $host, $port, 1.0, $db );
	}

	/**
	 * 索引集合键。
	 *
	 * @return string
	 */
	private function index_key() {
		return 'at8sa:' . $this->salt . '|__index';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( $host, $uri, $mobile = false ) {
		if ( ! $this->client->connect() ) {
			return false;
		}

		$html = $this->client->get( CachePath::redis_key( $this->salt, $host, $uri, $mobile ) );

		return is_string( $html ) && '' !== $html ? $html : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set( $host, $uri, $html, $ttl, $mobile = false ) {
		if ( ! $this->client->connect() ) {
			return false;
		}

		$key = CachePath::redis_key( $this->salt, $host, $uri, $mobile );
		$ok  = $this->client->set( $key, $html, $ttl );

		if ( $ok ) {
			$this->client->sadd( $this->index_key(), $key );
		}

		return (bool) $ok;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_url( $host, $uri ) {
		if ( ! $this->client->connect() ) {
			return 0;
		}

		$keys = array(
			CachePath::redis_key( $this->salt, $host, $uri, false ),
			CachePath::redis_key( $this->salt, $host, $uri, true ),
		);

		foreach ( $keys as $key ) {
			$this->client->srem( $this->index_key(), $key );
		}

		$deleted = $this->client->del( $keys );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_urls( $host, array $uris ) {
		if ( ! $this->client->connect() ) {
			return 0;
		}

		$keys = array();

		foreach ( $uris as $uri ) {
			$keys[] = CachePath::redis_key( $this->salt, $host, $uri, false );
			$keys[] = CachePath::redis_key( $this->salt, $host, $uri, true );
		}

		if ( empty( $keys ) ) {
			return 0;
		}

		foreach ( $keys as $key ) {
			$this->client->srem( $this->index_key(), $key );
		}

		$deleted = 0;

		// 分批 DEL，避免单条命令参数过长。
		foreach ( array_chunk( $keys, 200 ) as $chunk ) {
			$result = $this->client->del( $chunk );

			if ( is_int( $result ) ) {
				$deleted += $result;
			}
		}

		return $deleted;
	}

	/**
	 * {@inheritDoc}
	 */
	public function flush() {
		if ( ! $this->client->connect() ) {
			return false;
		}

		if ( '' === $this->salt ) {
			return false; // 无盐时安全退避，绝不冒"误清全局"的风险。
		}

		$index = $this->index_key();
		$keys  = $this->client->smembers( $index );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		foreach ( array_chunk( $keys, 200 ) as $chunk ) {
			if ( ! empty( $chunk ) ) {
				$this->client->del( $chunk );
			}
		}

		$this->client->del( $index );

		// 兜底：索引与实际键不一致时，SCAN 再扫一遍本盐前缀。
		$scanned = $this->client->scan( CachePath::redis_pattern( $this->salt ) );

		foreach ( array_chunk( (array) $scanned, 200 ) as $chunk ) {
			if ( ! empty( $chunk ) ) {
				$this->client->del( $chunk );
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return 'Redis';
	}

	/**
	 * {@inheritDoc}
	 */
	public function available() {
		return $this->client->connect() && false !== $this->client->ping();
	}

	/**
	 * {@inheritDoc}
	 */
	public function stats() {
		if ( ! $this->client->connect() ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$keys = $this->client->smembers( $this->index_key() );

		return array(
			'count' => is_array( $keys ) ? count( $keys ) : 0,
			'bytes' => 0,
		);
	}
}

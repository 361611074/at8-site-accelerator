<?php
/**
 * 纯 PHP Redis 客户端（RESP 协议，不依赖 phpredis 扩展）。
 *
 * 移植自 2.x 的内联实现，抽成独立类。保留原因：大量主机未装 phpredis 扩展，
 * 而这套实现只依赖 fsockopen，覆盖面远大于扩展方案。
 *
 * 安全约束：
 * - 所有命令以数组形式传入，参数逐个按 RESP 批量串编码，天然免疫命令注入。
 * - 连接超时固定 1 秒，Redis 不可达时不会拖慢前台请求。
 *
 * @package AT8SA\Support
 */

namespace AT8SA\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Class RedisClient
 */
final class RedisClient {

	/**
	 * 套接字句柄。
	 *
	 * @var resource|null
	 */
	private $socket = null;

	/**
	 * 主机。
	 *
	 * @var string
	 */
	private $host;

	/**
	 * 端口。
	 *
	 * @var int
	 */
	private $port;

	/**
	 * 超时（秒）。
	 *
	 * @var float
	 */
	private $timeout;

	/**
	 * 逻辑库编号。
	 *
	 * @var int
	 */
	private $database;

	/**
	 * 是否已连接。
	 *
	 * @var bool
	 */
	public $connected = false;

	/**
	 * 构造。
	 *
	 * @param string $host     主机。
	 * @param int    $port     端口。
	 * @param float  $timeout  超时秒数。
	 * @param int    $database 逻辑库。
	 */
	public function __construct( $host = '127.0.0.1', $port = 6379, $timeout = 1.0, $database = 2 ) {
		$this->host     = $host;
		$this->port     = (int) $port;
		$this->timeout  = (float) $timeout;
		$this->database = (int) $database;
	}

	/**
	 * 建立连接（幂等）。
	 *
	 * @return bool
	 */
	public function connect() {
		if ( is_resource( $this->socket ) ) {
			return true;
		}

		// 这里是**网络套接字**，不是文件操作：WP_Filesystem 与 wp_remote_* 都做不了
		// RESP 协议的双向长连接对话（wp_remote_* 每次请求都会重新建连、拿不到连接级
		// 状态）。所以必须直接 fsockopen。Redis 不可达时 @ 抑制连接告警，返回 false
		// 由调用方降级到磁盘后端。
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$this->socket = @fsockopen( $this->host, $this->port, $errno, $errstr, $this->timeout );

		if ( ! $this->socket ) {
			return false;
		}

		stream_set_timeout( $this->socket, (int) ceil( $this->timeout ) );

		if ( $this->database > 0 ) {
			$this->command( array( 'SELECT', $this->database ) );
		}

		$this->connected = true;

		return true;
	}

	/**
	 * 发送命令并读取应答。
	 *
	 * @param array $args 命令参数（第一个为命令名）。
	 * @return mixed false 表示失败/空值；字符串/整数/数组为正常应答。
	 */
	public function command( array $args ) {
		if ( ! is_resource( $this->socket ) && ! $this->connect() ) {
			return false;
		}

		$payload = '*' . count( $args ) . "\r\n";

		foreach ( $args as $arg ) {
			$arg      = (string) $arg;
			$payload .= '$' . strlen( $arg ) . "\r\n" . $arg . "\r\n";
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === fwrite( $this->socket, $payload ) ) {
			$this->disconnect();
			return false;
		}

		return $this->read_reply();
	}

	/**
	 * 读取一条 RESP 应答。
	 *
	 * @return mixed
	 */
	private function read_reply() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		$line = fgets( $this->socket );

		if ( false === $line || '' === $line ) {
			$this->disconnect();
			return false;
		}

		$type = $line[0];
		$data = substr( $line, 1, -2 );

		switch ( $type ) {
			case '+':
				return $data;

			case '-':
				return false;

			case ':':
				return (int) $data;

			case '$':
				$length = (int) $data;

				if ( -1 === $length ) {
					return false;
				}

				// RESP 的 bulk string 是 `$<长度>\r\n<内容>\r\n`，所以要连尾部的 CRLF 一起读完。
				// 注意：循环条件里的 strlen() 必须每轮重算（$body 在增长），
				// 所以这里用 while(true) + 显式 break，而不是把长度提到循环外——
				// 提到外面会导致一次 read 不够时提前退出、拿到截断的响应。
				$body   = '';
				$needed = $length + 2;

				while ( true ) {
					$remaining = $needed - strlen( $body );

					if ( $remaining <= 0 ) {
						break;
					}

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$chunk = fread( $this->socket, $remaining );

					if ( '' === $chunk || false === $chunk ) {
						break;
					}

					$body .= $chunk;
				}

				return substr( $body, 0, $length );

			case '*':
				$count = (int) $data;

				if ( -1 === $count ) {
					return false;
				}

				$items = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$items[] = $this->read_reply();
				}

				return $items;
		}

		return false;
	}

	/**
	 * GET。
	 *
	 * @param string $key 键。
	 * @return mixed
	 */
	public function get( $key ) {
		return $this->command( array( 'GET', $key ) );
	}

	/**
	 * SET（ttl > 0 时使用 EX）。
	 *
	 * @param string   $key 键。
	 * @param string   $value 值。
	 * @param int|null $ttl 秒。
	 * @return mixed
	 */
	public function set( $key, $value, $ttl = 0 ) {
		if ( $ttl > 0 ) {
			return $this->command( array( 'SET', $key, $value, 'EX', (int) $ttl ) );
		}

		return $this->command( array( 'SET', $key, $value ) );
	}

	/**
	 * DEL（支持一次多个键）。
	 *
	 * @param string|array $keys 键。
	 * @return mixed
	 */
	public function del( $keys ) {
		$keys = is_array( $keys ) ? $keys : array( $keys );

		if ( empty( $keys ) ) {
			return 0;
		}

		return $this->command( array_merge( array( 'DEL' ), $keys ) );
	}

	/**
	 * PING。
	 *
	 * @return mixed
	 */
	public function ping() {
		return $this->command( array( 'PING' ) );
	}

	/**
	 * SCAN 全量扫描匹配键。
	 *
	 * @param string $pattern 模式。
	 * @param int    $count   每批数量。
	 * @return array
	 */
	public function scan( $pattern, $count = 500 ) {
		if ( ! $this->connect() ) {
			return array();
		}

		$keys   = array();
		$cursor = 0;

		do {
			$reply = $this->command( array( 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', (int) $count ) );

			if ( ! is_array( $reply ) || count( $reply ) < 2 ) {
				break;
			}

			$cursor = (int) $reply[0];

			if ( is_array( $reply[1] ) ) {
				$keys = array_merge( $keys, $reply[1] );
			}
		} while ( 0 !== $cursor );

		return $keys;
	}

	/**
	 * SADD。
	 *
	 * @param string $key    集合键。
	 * @param string $member 成员。
	 * @return mixed
	 */
	public function sadd( $key, $member ) {
		return $this->command( array( 'SADD', $key, $member ) );
	}

	/**
	 * SREM。
	 *
	 * @param string $key    集合键。
	 * @param string $member 成员。
	 * @return mixed
	 */
	public function srem( $key, $member ) {
		return $this->command( array( 'SREM', $key, $member ) );
	}

	/**
	 * SMEMBERS。
	 *
	 * @param string $key 集合键。
	 * @return array
	 */
	public function smembers( $key ) {
		$reply = $this->command( array( 'SMEMBERS', $key ) );

		return is_array( $reply ) ? $reply : array();
	}

	/**
	 * 关闭连接。
	 *
	 * @return void
	 */
	public function disconnect() {
		if ( is_resource( $this->socket ) ) {
			// 同上：关闭的是套接字句柄，没有对应的 WordPress API。
			// 注意 phpcs:ignore 必须写成**行尾合并**形式——PHPCS 对同一行只会保留
			// 最后一条注解，把 NoSilencedErrors 单独写在行尾会把前一行的
			// fclose 豁免覆盖掉，导致豁免看起来配了却不生效。
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
			@fclose( $this->socket );
		}

		$this->socket    = null;
		$this->connected = false;
	}

	/**
	 * 析构时关闭。
	 */
	public function __destruct() {
		$this->disconnect();
	}
}

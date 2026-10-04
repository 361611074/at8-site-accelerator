<?php
/**
 * 缓存后端契约。
 *
 * 所有后端都必须支持"按键精准删除"，这是计划书 §64 禁止"任何小修改清空整站"的
 * 技术前提——没有精准删除，就只做得出全量 flush。
 *
 * @package AT8SA\Cache\Backend
 */

namespace AT8SA\Cache\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * Interface BackendInterface
 */
interface BackendInterface {

	/**
	 * 读取。
	 *
	 * @param string $host   主机。
	 * @param string $uri    归一化 URI。
	 * @param bool   $mobile 是否移动端变体。
	 * @return string|false
	 */
	public function get( $host, $uri, $mobile = false );

	/**
	 * 写入。
	 *
	 * @param string $host   主机。
	 * @param string $uri    归一化 URI。
	 * @param string $html   HTML 内容。
	 * @param int    $ttl    存活秒数。
	 * @param bool   $mobile 是否移动端变体。
	 * @return bool
	 */
	public function set( $host, $uri, $html, $ttl, $mobile = false );

	/**
	 * 删除单个 URL（同时覆盖桌面与移动变体）。
	 *
	 * @param string $host 主机。
	 * @param string $uri  归一化 URI。
	 * @return int 删除条目数。
	 */
	public function delete_url( $host, $uri );

	/**
	 * 批量删除 URL。
	 *
	 * @param string $host 主机。
	 * @param array  $uris 归一化 URI 列表。
	 * @return int 删除条目数。
	 */
	public function delete_urls( $host, array $uris );

	/**
	 * 清空本站点全部缓存（绝不影响同服务器其它站点）。
	 *
	 * @return bool
	 */
	public function flush();

	/**
	 * 后端名称（用于后台展示与诊断）。
	 *
	 * @return string
	 */
	public function name();

	/**
	 * 后端当前是否可用。
	 *
	 * @return bool
	 */
	public function available();

	/**
	 * 粗略统计（文件数 / 键数、占用字节）。不可用时返回 null 值。
	 *
	 * @return array{count:int,bytes:int}
	 */
	public function stats();
}

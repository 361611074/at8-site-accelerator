<?php
/**
 * CachePath 单元测试。
 *
 * 这是全插件安全等级最高的一段代码：它把任意 URL 映射成文件系统路径，
 * 一旦映射可以被穿越，攻击者就能让缓存写文件到任意位置。
 * 所以这里的用例重点是**穿越防护**与**变体隔离**，而不只是"函数能跑"。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Cache\CachePath;

/**
 * Class CachePathTest
 */
final class CachePathTest extends TestCase {

	/**
	 * 主机名归一化。
	 *
	 * @param string $input    输入。
	 * @param string $expected 期望。
	 * @return void
	 * @dataProvider provide_hosts
	 */
	public function test_normalize_host( $input, $expected ) {
		$this->assertSame( $expected, CachePath::normalize_host( $input ) );
	}

	/**
	 * 主机名用例。
	 *
	 * @return array
	 */
	public static function provide_hosts() {
		return array(
			'普通域名原样保留' => array( 'example.com', 'example.com' ),
			'大写归一为小写'   => array( 'EXAMPLE.COM', 'example.com' ),
			'前后空白被去掉'   => array( "  example.com\t", 'example.com' ),
			'空串回退占位'     => array( '', 'unknown-host' ),
			'空格替换为下划线' => array( 'exa mple.com', 'exa_mple.com' ),
			'端口号保留'       => array( 'example.com:8080', 'example.com:8080' ),
			'IPv6 方括号被替换' => array( '[::1]', '_::1_' ),
			'超长主机被截断到 190' => array( str_repeat( 'a', 300 ), str_repeat( 'a', 190 ) ),
		);
	}

	/**
	 * 路径段消毒：只允许安全字符，且必须干掉穿越序列。
	 *
	 * @return void
	 */
	public function test_sanitize_segment_removes_traversal() {
		$this->assertSame( '', CachePath::sanitize_segment( '..' ), '`..` 必须被彻底清除' );
		$this->assertSame( '', CachePath::sanitize_segment( '.' ), '`.` 必须被清除' );
		$this->assertSame( 'ab', CachePath::sanitize_segment( 'a\\b' ), '反斜杠被移除（Windows 路径穿越）' );
		$this->assertSame( 'a_b', CachePath::sanitize_segment( 'a/b' ), '斜杠被替换，不能形成新层级' );

		// 注意这里断言的是 "__etc" 而不是 "etc"：单段消毒只负责"把危险字符吃掉"，
		// 真正把 `../` 拆成独立段再逐段丢弃的是 path_segments()。
		// 两条防线都要有——只靠单段消毒，'../../etc' 会变成 '__etc'（虽然仍安全，
		// 但缓存路径会变得难以预测）。
		$this->assertSame( '__etc', CachePath::sanitize_segment( '../../etc' ) );
		$this->assertSame( 'etc', CachePath::path_segments( '/../../etc' ), '按段处理后穿越被完全消除' );
	}

	/**
	 * 消毒后的段永远只含白名单字符。
	 *
	 * 这条比逐个断言具体值更本质：只要这个性质成立，就不可能穿越。
	 *
	 * @param string $input 输入。
	 * @return void
	 * @dataProvider provide_dirty_segments
	 */
	public function test_sanitize_segment_output_is_always_safe( $input ) {
		$out = CachePath::sanitize_segment( $input );

		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9._\-]*$/',
			$out,
			"段 '{$input}' 消毒后含非法字符：'{$out}'"
		);
		$this->assertStringNotContainsString( '..', $out, "段 '{$input}' 消毒后仍含 `..`" );
		$this->assertStringNotContainsString( '/', $out, "段 '{$input}' 消毒后仍含 `/`" );
	}

	/**
	 * 脏段用例。
	 *
	 * @return array
	 */
	public static function provide_dirty_segments() {
		return array(
			'中文'       => array( '中文' ),
			'空格'       => array( 'a b c' ),
			'控制字符'   => array( "a\x00b\x1fc\x7f" ),
			'百分号'     => array( '%2e%2e' ),
			'尖括号'     => array( '<script>' ),
			'超长'       => array( str_repeat( 'x', 500 ) ),
		);
	}

	/**
	 * 超长段被钳制到 MAX_SEGMENT。
	 *
	 * @return void
	 */
	public function test_sanitize_segment_is_length_capped() {
		$this->assertSame(
			CachePath::MAX_SEGMENT,
			strlen( CachePath::sanitize_segment( str_repeat( 'x', 500 ) ) )
		);
	}

	/**
	 * 路径 → 目录段。
	 *
	 * @param string $path     路径。
	 * @param string $expected 期望。
	 * @return void
	 * @dataProvider provide_paths
	 */
	public function test_path_segments( $path, $expected ) {
		$this->assertSame( $expected, CachePath::path_segments( $path ) );
	}

	/**
	 * 路径用例。
	 *
	 * @return array
	 */
	public static function provide_paths() {
		return array(
			'根路径落到 __root'    => array( '/', '__root' ),
			'空串也落到 __root'    => array( '', '__root' ),
			'单段'                 => array( '/hello', 'hello' ),
			'多段'                 => array( '/blog/2026/post', 'blog/2026/post' ),
			'结尾斜杠被忽略'       => array( '/blog/post/', 'blog/post' ),
			'无前导斜杠也能处理'   => array( 'blog/post', 'blog/post' ),
			'完整 URL 只取 path'   => array( 'https://example.com/a/b', 'a/b' ),
			'百分号编码穿越被中和' => array( '/%2e%2e/etc/passwd', 'etc/passwd' ),
			'明文穿越被中和'       => array( '/a/../../b', 'a/b' ),
			'连续斜杠不产生空段'   => array( '///a///b///', 'a/b' ),
		);
	}

	/**
	 * 归一化 URI：剥离追踪参数、统一参数顺序。
	 *
	 * @return void
	 */
	public function test_normalize_uri_strips_tracking_params() {
		$this->assertSame( '/', CachePath::normalize_uri( '' ) );
		$this->assertSame( '/', CachePath::normalize_uri( '/' ) );
		$this->assertSame( '/p/', CachePath::normalize_uri( '/p/?utm_source=news' ) );
		$this->assertSame( '/p/?page=2', CachePath::normalize_uri( '/p/?utm_source=news&page=2' ) );
	}

	/**
	 * 参数顺序不影响缓存键（否则同一页面会存多份）。
	 *
	 * @return void
	 */
	public function test_normalize_uri_is_order_insensitive() {
		$this->assertSame(
			CachePath::normalize_uri( '/p/?b=2&a=1' ),
			CachePath::normalize_uri( '/p/?a=1&b=2' )
		);
	}

	/**
	 * 用户自定义忽略规则同样生效。
	 *
	 * @return void
	 */
	public function test_normalize_uri_honours_extra_rules() {
		$this->assertSame( '/p/', CachePath::normalize_uri( '/p/?ref=abc', array( 'ref' ) ) );
		$this->assertSame( '/p/?keep=1', CachePath::normalize_uri( '/p/?keep=1&ref=abc', array( 'ref' ) ) );
	}

	/**
	 * 忽略规则匹配：支持精确、前缀通配、全通配。
	 *
	 * @return void
	 */
	public function test_is_ignored_param() {
		$rules = array( 'utm_*', 'fbclid', '*' );

		$this->assertTrue( CachePath::is_ignored_param( 'utm_source', array( 'utm_*' ) ) );
		$this->assertTrue( CachePath::is_ignored_param( 'UTM_MEDIUM', array( 'utm_*' ) ), '大小写不敏感' );
		$this->assertFalse( CachePath::is_ignored_param( 'page', array( 'utm_*' ) ) );
		$this->assertTrue( CachePath::is_ignored_param( 'fbclid', array( 'fbclid' ) ) );
		$this->assertFalse( CachePath::is_ignored_param( 'fbclid_x', array( 'fbclid' ) ), '精确规则不匹配前缀' );
		$this->assertTrue( CachePath::is_ignored_param( 'anything', $rules ), '`*` 命中一切' );
		$this->assertFalse( CachePath::is_ignored_param( 'x', array( '' ) ), '空规则被忽略，不误伤' );
	}

	/**
	 * split_uri 正确切分 path 与 query。
	 *
	 * @return void
	 */
	public function test_split_uri() {
		$this->assertSame( array( 'path' => '/a', 'query' => '' ), CachePath::split_uri( '/a' ) );
		$this->assertSame( array( 'path' => '/a', 'query' => 'x=1' ), CachePath::split_uri( '/a?x=1' ) );
		$this->assertSame( array( 'path' => '/a', 'query' => 'x=1&y=2' ), CachePath::split_uri( '/a?x=1&y=2' ) );
	}

	/**
	 * 相对目录布局：host/路径，查询串变体单独分目录。
	 *
	 * @return void
	 */
	public function test_relative_dir() {
		$this->assertSame( 'example.com/__root', CachePath::relative_dir( 'example.com', '/' ) );
		$this->assertSame( 'example.com/blog/post', CachePath::relative_dir( 'example.com', '/blog/post' ) );
		$this->assertSame( 'example.com/blog/post/__m', CachePath::relative_dir( 'example.com', '/blog/post', true ) );
	}

	/**
	 * 带查询串的页面必须与不带查询串的页面分开存放。
	 *
	 * 否则 `/p/` 与 `/p/?page=2` 会互相覆盖——这是最容易被忽略的一类缓存串页事故。
	 *
	 * @return void
	 */
	public function test_query_variant_gets_its_own_directory() {
		$plain = CachePath::relative_dir( 'example.com', '/p/' );
		$paged = CachePath::relative_dir( 'example.com', '/p/?page=2' );

		$this->assertNotSame( $plain, $paged );
		$this->assertStringStartsWith( $plain . '/q-', $paged );
	}

	/**
	 * 同一个查询串必然得到同一个目录（哈希稳定）。
	 *
	 * @return void
	 */
	public function test_query_variant_hash_is_stable() {
		$this->assertSame(
			CachePath::relative_dir( 'example.com', '/p/?page=2' ),
			CachePath::relative_dir( 'example.com', '/p/?page=2' )
		);
	}

	/**
	 * 磁盘文件路径拼装。
	 *
	 * @return void
	 */
	public function test_disk_file() {
		$this->assertSame(
			'/cache/example.com/blog/post/index.html',
			CachePath::disk_file( '/cache', 'example.com', '/blog/post' )
		);
		$this->assertSame(
			'/cache/example.com/blog/post/__m/index.html',
			CachePath::disk_file( '/cache', 'example.com', '/blog/post', true )
		);
		$this->assertSame(
			'/cache/example.com/blog/post/index.html',
			CachePath::disk_file( '/cache/', 'example.com', '/blog/post' ),
			'根目录结尾的斜杠不应产生双斜杠'
		);
	}

	/**
	 * disk_dir 刻意**不含**移动端段，一次删除同时覆盖两种变体。
	 *
	 * 这是"精准失效"的关键：清一个 URL 时要连它的移动端副本一起清掉。
	 *
	 * @return void
	 */
	public function test_disk_dir_covers_both_variants() {
		$dir = CachePath::disk_dir( '/cache', 'example.com', '/blog/post' );

		$this->assertSame( '/cache/example.com/blog/post', $dir );
		$this->assertStringStartsWith(
			$dir . '/',
			CachePath::disk_file( '/cache', 'example.com', '/blog/post' ),
			'桌面变体在 disk_dir 之下'
		);
		$this->assertStringStartsWith(
			$dir . '/',
			CachePath::disk_file( '/cache', 'example.com', '/blog/post', true ),
			'移动端变体同样在 disk_dir 之下'
		);
	}

	/**
	 * Redis 键拼装。
	 *
	 * @return void
	 */
	public function test_redis_key_and_pattern() {
		$this->assertSame( 'at8sa:salt:example.com:/p', CachePath::redis_key( 'salt', 'example.com', '/p' ) );
		$this->assertSame( 'at8sa:salt:example.com:/p|m', CachePath::redis_key( 'salt', 'example.com', '/p', true ) );
		$this->assertSame( 'at8sa:salt:*', CachePath::redis_pattern( 'salt' ) );
	}

	/**
	 * Redis 键的主机部分也必须归一化，否则大小写不同会产生两份缓存。
	 *
	 * @return void
	 */
	public function test_redis_key_normalizes_host() {
		$this->assertSame(
			CachePath::redis_key( 'salt', 'EXAMPLE.com', '/p' ),
			CachePath::redis_key( 'salt', 'example.com', '/p' )
		);
	}
}

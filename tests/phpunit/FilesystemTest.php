<?php
/**
 * Filesystem 单元测试。
 *
 * 这个类是**整站安全边界**：所有删目录、写文件的操作都要先过
 * `is_inside_cache_root()`。这条判断写松了，一个路径穿越就能删掉用户整个站点。
 * 所以用例里有一半是在证明"不该放行的路径确实被拒绝了"。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Support\Filesystem;

/**
 * Class FilesystemTest
 */
final class FilesystemTest extends TestCase {

	/**
	 * 测试用目录（位于允许的缓存根之内）。
	 *
	 * @var string
	 */
	private $sandbox = '';

	/**
	 * 建立沙箱目录。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sandbox = WP_CONTENT_DIR . '/cache/at8sa-unit-fs';

		if ( is_dir( $this->sandbox ) ) {
			Filesystem::rrmdir( $this->sandbox );
		}
	}

	/**
	 * 清理沙箱。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->sandbox && is_dir( $this->sandbox ) ) {
			Filesystem::rrmdir( $this->sandbox );
		}

		parent::tearDown();
	}

	/**
	 * 缓存根之内的路径被放行。
	 *
	 * @return void
	 */
	public function test_paths_inside_cache_root_are_allowed() {
		$this->assertTrue( Filesystem::is_inside_cache_root( WP_CONTENT_DIR . '/cache' ) );
		$this->assertTrue( Filesystem::is_inside_cache_root( WP_CONTENT_DIR . '/cache/at8-site-accelerator' ) );
		$this->assertTrue( Filesystem::is_inside_cache_root( WP_CONTENT_DIR . '/cache/a/b/c/d' ) );
	}

	/**
	 * 缓存根之外的路径一律拒绝。
	 *
	 * @param string $path 路径。
	 * @return void
	 * @dataProvider provide_outside_paths
	 */
	public function test_paths_outside_cache_root_are_rejected( $path ) {
		$this->assertFalse(
			Filesystem::is_inside_cache_root( $path ),
			"不该放行：{$path}"
		);
	}

	/**
	 * 越界路径用例。
	 *
	 * @return void
	 */
	public static function provide_outside_paths() {
		return array(
			'wp-content 本身'   => array( WP_CONTENT_DIR ),
			'站点根'            => array( ABSPATH ),
			'系统目录'          => array( '/etc' ),
			'根目录'            => array( '/' ),
			'空串'              => array( '' ),
			'相邻同前缀目录'    => array( WP_CONTENT_DIR . '/cache-evil' ),
		);
	}

	/**
	 * `../` 穿越被 realpath 归一化后拒绝。
	 *
	 * @return void
	 */
	public function test_traversal_is_normalized_and_rejected() {
		// 先让这个目录真实存在，realpath 才能解析出穿越后的绝对路径。
		mkdir( $this->sandbox, 0777, true );

		$this->assertFalse(
			Filesystem::is_inside_cache_root( $this->sandbox . '/../../../' ),
			'`../` 穿越后落到缓存根之外，必须拒绝'
		);
		$this->assertFalse(
			Filesystem::is_inside_cache_root( $this->sandbox . '/../../../../etc/passwd' )
		);
	}

	/**
	 * normalize 对空串返回空串，而不是当前目录。
	 *
	 * 返回 '.' 或 cwd 会让后续的前缀比较意外通过。
	 *
	 * @return void
	 */
	public function test_normalize_empty_string() {
		$this->assertSame( '', Filesystem::normalize( '' ) );
	}

	/**
	 * normalize 去掉结尾斜杠并统一为正斜杠。
	 *
	 * @return void
	 */
	public function test_normalize_removes_trailing_slash() {
		mkdir( $this->sandbox, 0777, true );

		$normalized = Filesystem::normalize( $this->sandbox . '/' );

		$this->assertStringEndsNotWith( '/', $normalized );
		$this->assertStringNotContainsString( '\\', $normalized );
	}

	/**
	 * mkdir_guarded 建目录并放上 index.php 守卫。
	 *
	 * 没有守卫的话，部分服务器会允许直接列出缓存目录，等于把全站 HTML 暴露出去。
	 *
	 * @return void
	 */
	public function test_mkdir_guarded_creates_dir_and_guard() {
		$dir = $this->sandbox . '/deep/nested';

		$this->assertTrue( Filesystem::mkdir_guarded( $dir ) );
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/index.php' );
	}

	/**
	 * mkdir_guarded 幂等：重复调用不报错、不覆盖已有守卫。
	 *
	 * @return void
	 */
	public function test_mkdir_guarded_is_idempotent() {
		$dir = $this->sandbox . '/x';

		Filesystem::mkdir_guarded( $dir );
		file_put_contents( $dir . '/index.php', '<?php // custom' );

		$this->assertTrue( Filesystem::mkdir_guarded( $dir ) );
		$this->assertSame( '<?php // custom', file_get_contents( $dir . '/index.php' ) );
	}

	/**
	 * put_contents 自动建父目录并写入内容。
	 *
	 * @return void
	 */
	public function test_put_contents_creates_parent_dirs() {
		$path = $this->sandbox . '/a/b/index.html';

		$this->assertTrue( Filesystem::put_contents( $path, '<html>ok</html>' ) );
		$this->assertFileExists( $path );
		$this->assertSame( '<html>ok</html>', file_get_contents( $path ) );
	}

	/**
	 * put_contents 覆盖已有文件，不残留临时文件。
	 *
	 * @return void
	 */
	public function test_put_contents_overwrites_atomically() {
		$path = $this->sandbox . '/index.html';

		Filesystem::put_contents( $path, 'first' );
		Filesystem::put_contents( $path, 'second' );

		$this->assertSame( 'second', file_get_contents( $path ) );

		$tmp = glob( $this->sandbox . '/*.tmp' );

		$this->assertSame( array(), is_array( $tmp ) ? $tmp : array() );
	}

	/**
	 * rrmdir 拒绝删除缓存根之外的目录。
	 *
	 * @return void
	 */
	public function test_rrmdir_refuses_outside_cache_root() {
		$outside = WP_CONTENT_DIR . '/uploads';

		if ( ! is_dir( $outside ) ) {
			mkdir( $outside, 0777, true );
		}

		$this->assertFalse( Filesystem::rrmdir( $outside ), '必须拒绝' );
		$this->assertDirectoryExists( $outside, '目录不能被删掉' );
	}

	/**
	 * rrmdir 递归删除缓存根之内的目录树。
	 *
	 * @return void
	 */
	public function test_rrmdir_removes_nested_tree() {
		$dir = $this->sandbox . '/a/b/c';

		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/index.html', 'x' );
		file_put_contents( $this->sandbox . '/a/index.html', 'y' );

		$this->assertTrue( Filesystem::rrmdir( $this->sandbox ) );
		$this->assertDirectoryDoesNotExist( $this->sandbox );
	}

	/**
	 * 不存在的目录视为"已经删干净"，返回 true。
	 *
	 * @return void
	 */
	public function test_rrmdir_on_missing_dir_is_true() {
		$this->assertTrue( Filesystem::rrmdir( $this->sandbox . '/never-existed' ) );
	}

	/**
	 * prune_empty_dir 只收空目录，不碰还有内容的目录。
	 *
	 * @return void
	 */
	public function test_prune_empty_dir() {
		$empty = $this->sandbox . '/empty';
		$full  = $this->sandbox . '/full';

		mkdir( $empty, 0777, true );
		mkdir( $full, 0777, true );
		file_put_contents( $full . '/index.html', 'x' );

		Filesystem::prune_empty_dir( $empty );
		Filesystem::prune_empty_dir( $full );

		$this->assertDirectoryDoesNotExist( $empty );
		$this->assertDirectoryExists( $full );
	}

	/**
	 * cache_root_writable 反映缓存根的真实可写性。
	 *
	 * @return void
	 */
	public function test_cache_root_writable() {
		$this->assertTrue( Filesystem::cache_root_writable(), '测试环境下缓存根应可写' );
	}
}

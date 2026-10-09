<?php
/**
 * wp-config.php 写入安全测试（WordPress.org 审核 P0-01）。
 *
 * 官方原文：
 *
 * > wp-config.php contents, including authentication keys and salts, are
 * > temporarily written to the predictable web-root file wp-config.php.at8sa.tmp,
 * > which may remain exposed if deletion fails.
 *
 * 3.0.5 的做法是"先把原文复制到 Web Root 的临时文件，写完再删掉"。
 * 审核判定这个模型本身就是问题：只要有一瞬间磁盘上存在名字可预测的明文副本，
 * 它就是一份可被直接下载的整站凭据。
 *
 * 3.0.6 从架构上取消了这个行为——原文只存在于 PHP 内存里。
 * 本文件逐条验证：
 * 1. 启用 WP_CACHE 后不存在 `wp-config.php.at8sa.tmp`；
 * 2. 整个运行目录里不存在任何 wp-config 副本 / `.at8sa*` 残留；
 * 3. 写入失败时 wp-config.php 恢复为原始内容；
 * 4. 校验失败时 wp-config.php 恢复为原始内容；
 * 5. 日志里不出现任何 wp-config 敏感内容；
 * 6. 全项目源码里不存在"创建 wp-config Web Root 副本"的代码。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Cache\AdvancedCache;
use AT8SA\Support\Logger;

/**
 * Class WpConfigSafetyTest
 */
final class WpConfigSafetyTest extends TestCase {

	/**
	 * 伪造的 wp-config.php 路径。
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger = null;

	/**
	 * 准备环境。
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->path = ABSPATH . 'wp-config.php';

		if ( ! is_dir( ABSPATH ) ) {
			mkdir( ABSPATH, 0777, true );
		}

		$settings     = $this->make_settings( array( 'log_enabled' => 1, 'log_level' => 'debug' ) );
		$this->logger = new Logger( $settings );
		$this->logger->clear();

		$this->cleanup_strays();
	}

	/**
	 * 清理。
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );

		if ( $this->logger instanceof Logger ) {
			$this->logger->clear();
		}

		$this->cleanup_strays();

		if ( is_file( $this->path ) ) {
			wp_delete_file( $this->path );
		}

		parent::tearDown();
	}

	/**
	 * 删掉可能残留的临时文件。
	 *
	 * @return void
	 */
	private function cleanup_strays() {
		$patterns = array( 'wp-config.php.*', '.htaccess*', '*.at8sa*' );

		foreach ( $patterns as $pattern ) {
			$found = glob( ABSPATH . $pattern );

			if ( is_array( $found ) ) {
				foreach ( $found as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
		}
	}

	/**
	 * 一份形态真实的 wp-config.php（含大括号 salt、DB_PASSWORD、8 个密钥）。
	 *
	 * @return string
	 */
	private function valid_config() {
		return <<<'PHPEOF'
<?php
/**
 * WordPress 基本配置文件。
 *
 * 要创建 wp-config.php 请访问 {@link https://api.wordpress.org/secret-key/1.1/salt/}
 */

// ** MySQL 设置 ** //
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'p@ss{word}' );
define( 'DB_HOST', 'localhost' );

define( 'AUTH_KEY',         'h/U5c%vqYZXfvlg/dC#MvYT)@%g+0*!ViFb1TCg/>0Z=}alP.]7Q--Wj}b0U|iGV' );
define( 'SECURE_AUTH_KEY',  'cd pZldZLDmYM2FBQh%j{<spl]0l1[[6uC0#5OT%c=+26(Z}>|uYmRojRXB2+[ui' );
define( 'LOGGED_IN_KEY',    '_b)Ozm0#b@IlT(OhpVVyU}5S={ Q6 xh_Zh[dBzy5(K~m?Q~F$B9jjX{pz#URn%m' );
define( 'NONCE_KEY',        'vW781le|bUO><,ikmdi&Up-8q!PpVs|1xVg}*;t 8nkWP}zy&&s5bil[T/v~_Iv.' );
define( 'AUTH_SALT',        ':b,V~MOP$;UU,ODpss6mS}_XE;2N b,WZ-w#S5r`u65^GUwxHg8-9qkHq!G}m;7`' );
define( 'SECURE_AUTH_SALT', 'HsB0n+XfC}mR8C8k@Q,qL<z/6(1A{%-)PWjM#nQpV!c>T+Yk.@u1HqZ5vT;K~' );
define( 'LOGGED_IN_SALT',   'Rk-E{q[5vY8nA#pP1z!T$wLm7uJ0cBbD3gHh6iOy2sKfVr4XeN9tQaMl+WdC' );
define( 'NONCE_SALT',       'oLQghWCYC5z:;d;AZni:6;rP-6qMJxD=2qH_wiHh~I(z5IyQ.{`Aw~Tif)<@stQF' );

$table_prefix = 'wp_';

define( 'WP_DEBUG', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHPEOF;
	}

	/**
	 * 写入伪造的 wp-config.php。
	 *
	 * @param string $contents 内容。
	 * @return string 路径。
	 */
	private function write_config( $contents ) {
		file_put_contents( $this->path, $contents );

		return $this->path;
	}

	/**
	 * Test 1：启用 WP_CACHE 后不存在 `wp-config.php.at8sa.tmp`，也不存在任何副本。
	 *
	 * @return void
	 */
	public function test_no_wp_config_temp_backup_is_created() {
		$this->write_config( $this->valid_config() );

		$result = ( new AdvancedCache( $this->logger ) )->enable_wp_cache();

		$this->assertTrue( $result['ok'], $result['message'] );

		// 官方点名的那个文件名。
		$this->assertFileDoesNotExist( $this->path . '.at8sa.tmp' );

		// 以及任何"wp-config.php + 后缀"形态的副本。
		$copies = glob( $this->path . '.*' );

		$this->assertSame( array(), is_array( $copies ) ? $copies : array(), 'wp-config.php 不得产生任何带后缀的副本' );
	}

	/**
	 * Test 2：整个运行目录里不存在 wp-config 副本或 `.at8sa*` 残留。
	 *
	 * 递归扫描而不只看同目录：换后缀名是最容易"以为改好了"的规避方式，
	 *  glob 只查一个文件名会漏掉它。
	 *
	 * @return void
	 */
	public function test_no_stray_wp_config_copy_anywhere() {
		$this->write_config( $this->valid_config() );

		$advanced = new AdvancedCache( $this->logger );

		$advanced->enable_wp_cache();
		$advanced->disable_wp_cache();

		$strays = $this->scan_strays();

		$this->assertSame( array(), $strays, '运行目录里不得残留任何 wp-config 副本或 .at8sa* 文件' );
	}

	/**
	 * 扫描运行目录里的可疑残留。
	 *
	 * @return array 相对路径列表。
	 */
	private function scan_strays() {
		$strays = array();

		if ( ! is_dir( ABSPATH ) ) {
			return $strays;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( ABSPATH, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$name     = $file->getFilename();
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( ABSPATH ) ) );

			// ① 任何 wp-config 的带后缀副本（递归）。
			// ② 任何 .at8sa* 残留（递归）。
			if ( 0 === strpos( $name, 'wp-config.php.' ) || false !== strpos( $name, '.at8sa' ) ) {
				$strays[] = $relative;
				continue;
			}

			// ③ Web Root 顶层（不含子目录）的临时 / 备份后缀。
			// 只扫顶层是因为子目录里可能有缓存后端自己产生的文件，
			// 而"Web Root 顶层"才是能被 HTTP 直接请求到的位置。
			if ( dirname( $file->getPathname() ) === rtrim( ABSPATH, '/\\' )
				&& preg_match( '/\.(tmp|bak|backup)$/i', $name ) ) {
				$strays[] = $relative;
			}
		}

		sort( $strays );

		return $strays;
	}

	/**
	 * Test 3：模拟写入失败 → wp-config.php 恢复为原始内容。
	 *
	 * 通过注入一个"第一次 put_contents() 必然失败"的 WP_Filesystem 实现来真实
	 * 触发 `write_wp_config()` 的写入失败分支——而不是只把文件改成只读让
	 * `enable_wp_cache()` 提前返回（那样根本没走到要测的那段代码）。
	 *
	 * @return void
	 */
	public function test_write_failure_restores_original_from_memory() {
		$original = $this->valid_config();
		$this->write_config( $original );

		$GLOBALS['wp_filesystem'] = new class extends \WP_Filesystem_Base {

			/**
			 * 下一次写入是否失败。
			 *
			 * @var bool
			 */
			private $fail_next = true;

			/**
			 * 读文件。
			 *
			 * @param string $file 路径。
			 * @return string|false
			 */
			public function get_contents( $file ) {
				return is_file( $file ) ? (string) file_get_contents( $file ) : false;
			}

			/**
			 * 写文件（第一次必定失败）。
			 *
			 * @param string $file     路径。
			 * @param string $contents 内容。
			 * @param int    $mode     权限。
			 * @return bool
			 */
			public function put_contents( $file, $contents, $mode = false ) {
				if ( $this->fail_next ) {
					$this->fail_next = false;

					return false;
				}

				return false !== file_put_contents( $file, $contents );
			}
		};

		$result = ( new AdvancedCache( $this->logger ) )->enable_wp_cache();

		$this->assertFalse( $result['ok'], '写入失败必须返回失败' );
		$this->assertSame( $original, (string) file_get_contents( $this->path ), '写入失败后必须恢复为原始内容' );
		$this->assertStringContainsString( '写入 wp-config.php 失败', $result['message'] );
	}

	/**
	 * Test 4：模拟校验失败 → wp-config.php 恢复为原始内容。
	 *
	 * 构造手法：在文件里放一个**位于字符串字面量内**的诱饵锚点
	 * `/* That's all, stop editing!`。`enable_wp_cache()` 的定位逻辑会先命中它，
	 * 于是把新行插进字符串里 —— 文件依然"能跑"（大括号配平、DB_NAME 都在），
	 * 但可逆性校验必然失败（去掉我们那一行无法还原原文），从而走到回滚分支。
	 *
	 * @return void
	 */
	public function test_verification_failure_rolls_back_to_original() {
		$original = str_replace(
			"define( 'WP_DEBUG', true );",
			"// 诱饵锚点：故意放在字符串字面量里，让插入点落进字符串内部。\n\$decoy = \"/* That's all, stop editing! Happy publishing. */\";\ndefine( 'WP_DEBUG', true );",
			$this->valid_config()
		);

		$this->write_config( $original );

		$advanced = new AdvancedCache( $this->logger );
		$result   = $advanced->enable_wp_cache();

		$this->assertFalse( $result['ok'], '校验失败必须返回失败' );
		$this->assertSame( $original, (string) file_get_contents( $this->path ), '校验失败后必须精确恢复为原始内容' );
	}

	/**
	 * Test 5：日志里不出现 wp-config 的敏感内容。
	 *
	 * @return void
	 */
	public function test_logs_contain_no_wp_config_secrets() {
		$this->write_config( $this->valid_config() );

		$advanced = new AdvancedCache( $this->logger );

		// 成功路径 + 失败路径都跑一遍，把三类日志事件（info / error）都写出来。
		$advanced->enable_wp_cache();

		$this->write_config(
			str_replace(
				"define( 'WP_DEBUG', true );",
				"\$decoy = \"/* That's all, stop editing! */\";\ndefine( 'WP_DEBUG', true );",
				$this->valid_config()
			)
		);

		$advanced->enable_wp_cache();

		$tail = $this->logger->tail( 100 );
		$blob = implode( "\n", $tail );

		// 先确认"日志真的写了"，否则下面每条断言都是空转。
		$this->assertNotEmpty( $tail, '本次应至少写入一条日志' );
		$this->assertStringContainsString( 'wp-config.php', $blob, '日志应记录事件名' );

		$secrets = array(
			'DB_PASSWORD',
			'p@ss{word}',
			'AUTH_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
			'h/U5c%vqYZXfvlg',
			'oLQghWCYC5z',
			'wp-settings.php',
		);

		foreach ( $secrets as $secret ) {
			$this->assertStringNotContainsString( $secret, $blob, '日志不得包含：' . $secret );
		}
	}

	/**
	 * Test 6：全项目源码里不存在"创建 wp-config Web Root 副本"的代码。
	 *
	 * 先剥注释再扫：代码库里大量注释在解释"为什么不再这么做"，
	 * 直接对全文 grep 会把解释当成使用。
	 *
	 * @return void
	 */
	public function test_no_source_code_creates_web_root_backups() {
		$root    = \AT8SA_PATH;
		$targets = array( 'includes', 'templates' );
		$files   = array( 'at8-site-accelerator.php', 'uninstall.php' );

		foreach ( $files as $file ) {
			if ( is_file( $root . $file ) ) {
				$this->assert_source_clean( $file, (string) file_get_contents( $root . $file ) );
			}
		}

		foreach ( $targets as $dir ) {
			if ( ! is_dir( $root . $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					$this->assert_source_clean(
						str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ),
						(string) file_get_contents( $file->getPathname() )
					);
				}
			}
		}
	}

	/**
	 * 断言单份源码里没有"创建 Web Root 明文备份"的代码。
	 *
	 * @param string $name   文件名（用于失败信息）。
	 * @param string $source 源码。
	 * @return void
	 */
	private function assert_source_clean( $name, $source ) {
		$code = self::strip_comments( $source );

		// 官方点名的临时文件名，以及任何"换后缀名"的变体。
		foreach ( array( '.at8sa.tmp', '.at8sa.bak', '.at8sa.backup', '.at8sa.old' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $code, $name . ' 不得创建 ' . $needle );
		}

		// 不允许出现"把内容写成 wp-config.php 之外的备份"的形态。
		$this->assertDoesNotMatchRegularExpression(
			'/wp_config_path\s*\(\s*\)\s*\.\s*[\'"]\.?[A-Za-z0-9._-]+[\'"]/',
			$code,
			$name . ' 不得拼接 wp-config 备份路径'
		);
	}

	/**
	 * Test 7：插件源码不得触碰核心 `$wp_version` 全局变量。
	 *
	 * 背景：主入口文件在 wp-settings.php 顶层作用域被 include，3.0.6 及之前
	 * 的 `unset( $wp_version, ... )` 会把核心全局变量真的删掉，导致后续加载
	 * 的插件（如 WPForms）读到 null 直接 Fatal（silkuasilk.com 2026-10-09 事故）。
	 * 本测试锁死三条红线：不许 `global $wp_version`、不许 unset 它、不许给它赋值。
	 *
	 * @return void
	 */
	public function test_sources_never_touch_core_wp_version_global() {
		$root    = \AT8SA_PATH;
		$targets = array( 'includes', 'templates' );
		$files   = array( 'at8-site-accelerator.php', 'uninstall.php' );
		$paths   = array();

		foreach ( $files as $file ) {
			if ( is_file( $root . $file ) ) {
				$paths[] = array( $file, (string) file_get_contents( $root . $file ) );
			}
		}

		foreach ( $targets as $dir ) {
			if ( ! is_dir( $root . $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' === strtolower( $file->getExtension() ) ) {
					$paths[] = array(
						str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) ),
						(string) file_get_contents( $file->getPathname() ),
					);
				}
			}
		}

		$this->assertNotEmpty( $paths, '应至少扫描到入口文件' );

		foreach ( $paths as list( $name, $source ) ) {
			$code = self::strip_comments( $source );

			$this->assertDoesNotMatchRegularExpression(
				'/\bglobal\s+\$wp_version\b/',
				$code,
				$name . ' 不得 `global $wp_version`（入口文件运行在顶层作用域，这就是全局变量本身）'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/unset\s*\([^)]*\$wp_version/',
				$code,
				$name . ' 不得 unset $wp_version'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/(^|[^:>a-zA-Z_])\$wp_version\s*=/',
				$code,
				$name . ' 不得给 $wp_version 赋值'
			);
		}

		// 正向验证：入口文件必须通过 $GLOBALS 只读校验版本（修复后的写法）。
		$entry = self::strip_comments( (string) file_get_contents( $root . 'at8-site-accelerator.php' ) );
		$this->assertStringContainsString( "\$GLOBALS['wp_version']", $entry, '入口文件应以只读方式访问 $GLOBALS[\'wp_version\']' );
	}

	/**
	 * 剥掉 PHP 注释，只保留可执行代码。
	 *
	 * @param string $source 源码。
	 * @return string
	 */
	private static function strip_comments( $source ) {
		if ( ! function_exists( 'token_get_all' ) ) {
			return $source;
		}

		$out = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$out .= $token[1];
			} else {
				$out .= $token;
			}
		}

		return $out;
	}
}

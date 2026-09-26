<?php
/**
 * 打包 Free 版 ZIP（计划书 §87 发布产物）。
 *
 * 原则：**白名单**而不是黑名单。
 * 黑名单（"排除 tests、排除 docs……"）总会在新增目录时漏掉一项，
 * 把不该发的东西一起发出去。这里反过来——只收录明确列出的目录与文件，
 * 新增的开发用目录默认不会被打进去。
 *
 * 用法：
 *   php tools/build-zip.php
 *   php tools/build-zip.php --out=dist/
 *
 * 产物：
 *   dist/at8-site-accelerator-<版本>.zip
 *   ZIP 内层结构为 at8-site-accelerator/...（WordPress 可直接安装）
 *
 * @package AT8\SiteAccelerator\Tools
 */

// phpcs:disable

if ( 'cli' !== PHP_SAPI ) {
	exit( '本脚本只能通过命令行运行。' );
}

$at8sa_root = dirname( __DIR__ );

/* ---------------------------------------------------------------------------
 * 版本号：从主插件文件的头部读取，避免"打包版本与插件头版本不一致"
 * ------------------------------------------------------------------------ */

$at8sa_entry = (string) file_get_contents( $at8sa_root . '/at8-site-accelerator.php' );

if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $at8sa_entry, $at8sa_m ) ) {
	fwrite( STDERR, "错误：无法从 at8-site-accelerator.php 头部读取版本号。\n" );
	exit( 1 );
}

$at8sa_version = $at8sa_m[1];

/* ---------------------------------------------------------------------------
 * 输出目录
 * ------------------------------------------------------------------------ */

$at8sa_out_dir = $at8sa_root . '/dist';

foreach ( $argv as $at8sa_arg ) {
	if ( 0 === strpos( $at8sa_arg, '--out=' ) ) {
		$at8sa_out_dir = rtrim( substr( $at8sa_arg, 6 ), '/\\' );
	}
}

if ( ! is_dir( $at8sa_out_dir ) && ! mkdir( $at8sa_out_dir, 0777, true ) ) {
	fwrite( STDERR, "错误：无法创建输出目录 {$at8sa_out_dir}\n" );
	exit( 1 );
}

/* ---------------------------------------------------------------------------
 * 白名单
 * ------------------------------------------------------------------------ */

$at8sa_include_files = array(
	'at8-site-accelerator.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'CHANGELOG.md',
);

$at8sa_include_dirs = array(
	'includes',
	'templates',
	'assets',
	'languages',
);

// 语言目录里只发 .pot 与 .mo/.po，跳过编辑器临时文件。
$at8sa_language_ext = array( 'pot', 'po', 'mo' );

/* ---------------------------------------------------------------------------
 * 收集文件
 * ------------------------------------------------------------------------ */

$at8sa_files = array();

foreach ( $at8sa_include_files as $at8sa_file ) {
	$at8sa_path = $at8sa_root . '/' . $at8sa_file;

	if ( ! is_file( $at8sa_path ) ) {
		fwrite( STDERR, "警告：缺少 {$at8sa_file}，已跳过。\n" );
		continue;
	}

	$at8sa_files[ $at8sa_file ] = $at8sa_path;
}

foreach ( $at8sa_include_dirs as $at8sa_dir ) {
	$at8sa_path = $at8sa_root . '/' . $at8sa_dir;

	if ( ! is_dir( $at8sa_path ) ) {
		continue;
	}

	$at8sa_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $at8sa_path, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $at8sa_iterator as $at8sa_item ) {
		if ( ! $at8sa_item->isFile() ) {
			continue;
		}

		$at8sa_relative = str_replace( '\\', '/', substr( $at8sa_item->getPathname(), strlen( $at8sa_root ) + 1 ) );

		// 隐藏文件（.DS_Store、.gitignore 等）一律不发。
		if ( false !== strpos( $at8sa_relative, '/.' ) || 0 === strpos( basename( $at8sa_relative ), '.' ) ) {
			continue;
		}

		if ( 'languages' === $at8sa_dir ) {
			$at8sa_ext = strtolower( $at8sa_item->getExtension() );

			if ( ! in_array( $at8sa_ext, $at8sa_language_ext, true ) ) {
				continue;
			}
		}

		$at8sa_files[ $at8sa_relative ] = $at8sa_item->getPathname();
	}
}

ksort( $at8sa_files );

/* ---------------------------------------------------------------------------
 * 写 ZIP
 * ------------------------------------------------------------------------ */

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "错误：缺少 zip 扩展（ZipArchive）。请启用 php_zip。\n" );
	exit( 1 );
}

$at8sa_zip_path = $at8sa_out_dir . '/at8-site-accelerator-' . $at8sa_version . '.zip';

if ( file_exists( $at8sa_zip_path ) ) {
	unlink( $at8sa_zip_path );
}

$at8sa_zip = new ZipArchive();

if ( true !== $at8sa_zip->open( $at8sa_zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "错误：无法创建 {$at8sa_zip_path}\n" );
	exit( 1 );
}

foreach ( $at8sa_files as $at8sa_relative => $at8sa_absolute ) {
	$at8sa_zip->addFile( $at8sa_absolute, 'at8-site-accelerator/' . $at8sa_relative );
}

$at8sa_zip->close();

/* ---------------------------------------------------------------------------
 * 自检：确认没把开发文件打进去
 * ------------------------------------------------------------------------ */

$at8sa_forbidden = array(
	'/tests/',
	'/docs/',
	'/tools/',
	'/.github/',
	'/dist/',
	'/.gitignore',
	'/.gitattributes',
	'/phpcs.xml',
	'/phpstan.neon',
	'/phpunit.xml',
	'/composer.json',
	'/composer.lock',
	'/vendor/',
	'/package.json',
	'/.DS_Store',
);

$at8sa_zip_read = new ZipArchive();
$at8sa_problems = array();

if ( true === $at8sa_zip_read->open( $at8sa_zip_path ) ) {
	for ( $at8sa_i = 0; $at8sa_i < $at8sa_zip_read->numFiles; $at8sa_i++ ) {
		$at8sa_name = (string) $at8sa_zip_read->getNameIndex( $at8sa_i );

		foreach ( $at8sa_forbidden as $at8sa_bad ) {
			if ( false !== strpos( $at8sa_name, $at8sa_bad ) ) {
				$at8sa_problems[] = $at8sa_name;
			}
		}
	}

	$at8sa_count = $at8sa_zip_read->numFiles;
	$at8sa_zip_read->close();
} else {
	fwrite( STDERR, "错误：无法回读刚生成的 ZIP。\n" );
	exit( 1 );
}

if ( ! empty( $at8sa_problems ) ) {
	fwrite( STDERR, "错误：ZIP 里混入了不应发布的路径：\n  " . implode( "\n  ", array_unique( $at8sa_problems ) ) . "\n" );
	exit( 1 );
}

echo "已生成 {$at8sa_zip_path}\n";
echo "版本：{$at8sa_version}\n";
echo "文件数：{$at8sa_count}\n";
echo '体积：' . number_format( filesize( $at8sa_zip_path ) / 1024, 1 ) . " KB\n";

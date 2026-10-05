<?php
/**
 * 临时回验脚本：比对本地包与远端包的内容一致性。
 * 用法：php verify-release.php <远端包路径>
 * @package AT8SA\Tools
 */

// phpcs:disable

$local  = __DIR__ . '/../dist/at8-site-accelerator-3.0.5.zip';
$remote = $argv[1] ?? '';

foreach ( array( '本地' => $local, '远端' => $remote ) as $label => $path ) {
	if ( ! is_readable( $path ) ) {
		echo "{$label}包不可读：{$path}\n";
		exit( 1 );
	}
}

$contents = array();

foreach ( array( '本地' => $local, '远端' => $remote ) as $label => $path ) {
	$z = new ZipArchive();
	if ( true !== $z->open( $path ) ) {
		echo "{$label}包打开失败\n";
		exit( 1 );
	}

	$map = array();
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$name = $z->getNameIndex( $i );
		$map[ $name ] = md5( (string) $z->getFromIndex( $i ) );
	}
	$z->close();
	ksort( $map );
	$contents[ $label ] = $map;

	echo sprintf( "%s包：%d 个文件\n", $label, count( $map ) );
}

$only_local  = array_diff_key( $contents['本地'], $contents['远端'] );
$only_remote = array_diff_key( $contents['远端'], $contents['本地'] );

echo '仅本地有：' . ( $only_local ? implode( ',', array_keys( $only_local ) ) : '无' ) . "\n";
echo '仅远端有：' . ( $only_remote ? implode( ',', array_keys( $only_remote ) ) : '无' ) . "\n";

$diff = array();
foreach ( $contents['本地'] as $name => $md5 ) {
	if ( isset( $contents['远端'][ $name ] ) && $contents['远端'][ $name ] !== $md5 ) {
		$diff[] = $name;
	}
}

echo '内容不同：' . ( $diff ? implode( ',', $diff ) : '无' ) . "\n";

if ( empty( $only_local ) && empty( $only_remote ) && empty( $diff ) ) {
	echo "两个包内容完全一致。\n";
	exit( 0 );
}

echo "两个包存在差异。\n";
exit( 1 );

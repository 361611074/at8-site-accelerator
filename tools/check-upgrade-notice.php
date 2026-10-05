<?php
/**
 * 校验 readme.txt 的 Upgrade Notice：每个版本条目不得超过 300 字符。
 *
 * 为什么要自己校验：Plugin Check 只在后台报一行 WARNING，不告诉你超了多少、
 * 也不告诉你下一个版本会不会同样超。等审核被打回再发现是浪费。
 */

$file = dirname( __DIR__ ) . '/readme.txt';
$txt  = file_get_contents( $file );

// 截取 Upgrade Notice 段（到下一个二级标题或文件末尾为止）。
$start = strpos( $txt, '== Upgrade Notice ==' );
if ( false === $start ) {
	fwrite( STDERR, "未找到 Upgrade Notice 段\n" );
	exit( 1 );
}
$rest = substr( $txt, $start + strlen( '== Upgrade Notice ==' ) );
$end  = strpos( $rest, "\n== " );
$body = ( false === $end ) ? $rest : substr( $rest, 0, $end );

// 按 "= x.y.z =" 切分条目。
//
// 为什么不用 preg_split + PREG_SPLIT_DELIM_CAPTURE：
// 那个组合在遇到**连续两个**分隔符时（=误把Changelog 的写法搬进来，就会出现
// 两个相邻的 "= x.y.z =" 行）会把中间的正文并进下一段，且丢失一条记录 ——
// 实测"插入一个同名重复标题"会让本脚本从5 条变 4 条，3.0.5 整条凭空消失、
// 退出码仍是 0。那种"漏报还报绿"比报错危险得多。
// 改成 preg_match_all 逐个定位，两个相邻标题就是两条独立记录，不会互相吞。
preg_match_all( '/^=\s*([0-9][0-9.]*)\s*=$/m', $body, $at8sa_hits, PREG_OFFSET_CAPTURE );

$limit  = 300;
$failed = 0;
$seen   = array();

if ( ! $at8sa_hits[0] ) {
	fwrite( STDERR, "Upgrade Notice 段里没有任何 '= x.y.z =' 条目，readme.txt 结构可能变了\n" );
	exit( 1 );
}

foreach ( $at8sa_hits[0] as $at8sa_k => $at8sa_match ) {
	$version = trim( $at8sa_hits[1][ $at8sa_k ][0] );

	// 本条正文 = 标题结束处 → 下一个标题开始处（没有下一个就是段尾）。
	$from = $at8sa_match[1];
	$to   = isset( $at8sa_hits[0][ $at8sa_k + 1 ] )
		? $at8sa_hits[0][ $at8sa_k + 1 ][1]
		: strlen( $body );

	$text = trim( substr( $body, $from, $to - $from ) );
	$len  = strlen( $text );

	// 同一版本号出现两次时，Plugin Check 只按版本名匹配，条目会互相干扰，
	// 而且读者也分不清哪段对应哪次升级 —— 单独指出来，别让它静默通过。
	if ( in_array( $version, $seen, true ) ) {
		fwrite( STDERR, "版本号 {$version} 在 Upgrade Notice 段里出现了多次，请合并成一条\n" );
		$failed++;
	}
	$seen[] = $version;

	printf(
		"%-8s %4d 字符  %s\n",
		$version,
		$len,
		$len > $limit ? '❌ 超限' : '✅'
	);

	// 空条目同样要报错：Plugin Check 看到空的 upgrade notice 会当成无效条目。
	if ( '' === $text ) {
		fwrite( STDERR, "版本号 {$version} 的 Upgrade Notice 正文为空\n" );
		$failed++;
	}

	if ( $len > $limit ) {
		$failed++;
	}
}

printf( "\n共 %d 个版本条目，问题%d 个，限制 %d 字符。\n", count( $seen ), $failed, $limit );

exit( $failed > 0 ? 1 : 0 );
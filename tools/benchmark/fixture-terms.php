<?php
// phpcs:disable
/**
 * 给所有文章随机分配 1-3 个分类，让分类归档页真实存在。
 * 不这么做的话，save_post 触发的分类归档失效路径根本没有目标，
 * 「精准失效」会被测得比实际更好（虚高）。
 */

$ids  = get_posts(
	array(
		'post_type'   => 'post',
		'numberposts' => -1,
		'fields'      => 'ids',
		'post_status' => 'publish',
	)
);
$cats = get_terms(
	array(
		'taxonomy'   => 'category',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

if ( is_wp_error( $cats ) ) {
	echo "分类读取失败\n";
	return;
}

$cats = array_values( $cats );
$n    = 0;

foreach ( $ids as $id ) {
	shuffle( $cats );
	$pick = array_slice( $cats, 0, random_int( 1, 3 ) );
	wp_set_post_terms( $id, $pick, 'category', false );
	++$n;
}

echo "已为 {$n} 篇文章分配分类（共 " . count( $cats ) . " 个分类）\n";

// 抽查一篇，确认归档页能生成
$sample = get_posts(
	array(
		'post_type'   => 'post',
		'numberposts' => 1,
		'fields'      => 'ids',
		'post_status' => 'publish',
	)
);

if ( $sample ) {
	$pid  = $sample[0];
	$link = get_permalink( $pid );
	$tms  = wp_get_post_terms( $pid, 'category', array( 'fields' => 'ids' ) );
	echo "抽查文章 #{$pid}: {$link}\n";
	echo '  分类: ' . implode( ',', $tms ) . "\n";
	echo '  分类归档: ' . get_term_link( (int) $tms[0], 'category' ) . "\n";
}

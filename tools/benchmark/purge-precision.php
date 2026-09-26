<?php
// phpcs:disable
/**
 * 「精准失效」核心命题测量。
 *
 * 命题：编辑一篇文章时，被清除的缓存页面数应显著小于整站页面总数。
 *
 * 用法: wp --allow-root eval-file /tmp/at8sa-purge-test.php [related|all]
 *   related = 默认的精准失效策略
 *   all     = 对照组（整站清空），用来证明差异确实来自策略
 */

use AT8\SiteAccelerator\Cache\Backend\BackendFactory;
use AT8\SiteAccelerator\Core\Plugin;
use AT8\SiteAccelerator\Core\Settings;
use AT8\SiteAccelerator\Purge\Purger;

$container = Plugin::instance()->container();
$purger    = $container->get( Purger::class );
$factory   = $container->get( BackendFactory::class );
$settings  = $container->get( Settings::class );

$scope = isset( $args[0] ) ? $args[0] : 'related';

// 对照组：把策略切到 all
if ( 'all' === $scope ) {
	$all = $settings->defaults();
	$all['purge_scope'] = 'all';
	$settings->persist( $settings->sanitize( $all ) );
	$settings->flush_cache();
}

echo "===== 精准失效测量（purge_scope={$scope}）=====\n";

$cached = function () use ( $purger ) {
	$s = $purger->backend_status();
	return (int) $s['cached_pages'];
};

$hit = function ( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 3 ) );
	if ( is_wp_error( $r ) ) {
		return 'ERR';
	}
	$h = wp_remote_retrieve_header( $r, 'x-at8-cache' );
	return $h ? $h : 'none';
};

// ---- 1. 清空起点 ----
$purger->purge_all();
echo '清空后条目: ' . $cached() . "\n";

// ---- 2. 构造待预热 URL 集合 ----
$post_ids = get_posts(
	array(
		'post_type'   => 'post',
		'numberposts' => 60,
		'fields'      => 'ids',
		'post_status' => 'publish',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	)
);

$urls = array( home_url( '/' ) );
foreach ( $post_ids as $pid ) {
	$urls[] = get_permalink( $pid );
}
$urls[] = home_url( '/page/2/' );
$urls[] = home_url( '/page/3/' );

$cats = get_terms(
	array(
		'taxonomy'   => 'category',
		'hide_empty' => true,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $cats ) ) {
	foreach ( array_slice( array_values( $cats ), 0, 8 ) as $tid ) {
		$link = get_term_link( (int) $tid, 'category' );
		if ( ! is_wp_error( $link ) ) {
			$urls[] = $link;
		}
	}
}
$urls = array_values( array_unique( $urls ) );

// ---- 3. 预热 ----
foreach ( $urls as $u ) {
	wp_remote_get( $u, array( 'timeout' => 25, 'redirection' => 3 ) );
}

$before = $cached();
echo '预热 URL 数  : ' . count( $urls ) . "\n";
echo "预热后条目   : {$before}\n";

// ---- 4. 选一篇文章做编辑 ----
$target          = $post_ids[0];
$target_link     = get_permalink( $target );
$target_cats     = wp_get_post_terms( $target, 'category', array( 'fields' => 'ids' ) );
$bystander       = $post_ids[30];
$bystander_link  = get_permalink( $bystander );

echo "目标文章     : #{$target} {$target_link}\n";
echo '  所属分类   : ' . implode( ',', (array) $target_cats ) . "\n";
echo "无关文章     : #{$bystander} {$bystander_link}\n";

// 编辑前两者的命中状态
echo '  编辑前 目标: ' . $hit( $target_link ) . ' / 无关: ' . $hit( $bystander_link ) . "\n";

// ---- 5. 触发编辑（save_post）----
wp_update_post(
	array(
		'ID'         => $target,
		'post_title' => 'AT8 基准测试 ' . gmdate( 'His' ),
	)
);

$after = $cached();
echo "编辑后条目   : {$after}\n";
echo '被清除条目数 : ' . ( $before - $after ) . "\n";
if ( $before > 0 ) {
	printf( "占预热总量   : %.1f%%\n", ( $before - $after ) * 100 / $before );
}

// ---- 6. 验证：目标失效、无关仍命中 ----
echo '  编辑后 目标: ' . $hit( $target_link ) . ' / 无关: ' . $hit( $bystander_link ) . "\n";

if ( 'all' === $scope ) {
	echo "\n（对照组：purge_scope=all，应几乎全部清除）\n";
}

// 复原为默认策略
if ( 'all' === $scope ) {
	$back = $settings->defaults();
	$settings->persist( $settings->sanitize( $back ) );
	$settings->flush_cache();
	echo "已复原 purge_scope=related\n";
}

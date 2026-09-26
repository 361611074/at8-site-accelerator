<?php
/**
 * 设置页模板。
 *
 * 可用变量：`$data`（见 SettingsPage::render()）。
 *
 * 模板内的两条硬规则：
 * - 所有输出必须转义（esc_html / esc_attr / esc_url / wp_kses_post）；
 * - 所有开关一律用同一个渲染函数，保证 name 与设置键严格一致，杜绝拼写漂移。
 *
 * @package AT8\SiteAccelerator
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $data ) || ! is_array( $data ) ) {
	return;
}

$at8sa_settings     = $data['settings'];
$at8sa_backend_name = $data['backend_name'];
$at8sa_stats        = $data['backend_stats'];
$at8sa_dropin       = $data['advanced_cache'];
$at8sa_browser      = $data['browser_cache'];
$at8sa_logger       = $data['logger'];

/**
 * 渲染一行开关。
 *
 * 注意 hidden 字段：checkbox 未勾选时浏览器**不会提交该键**，
 * 而 Settings::sanitize() 对"缺键"的处理是"保留原值"（为了让 REST / 导入这类
 * 局部更新不会误关功能）。所以这里必须补一个 value="0" 的 hidden——
 * 未勾选时提交 0，勾选时后面的 checkbox 覆盖它。少了这一行，用户根本关不掉任何开关。
 *
 * @param string $key   设置键。
 * @param string $label 标题。
 * @param string $hint  说明。
 * @return void
 */
$at8sa_toggle = function ( $key, $label, $hint = '' ) use ( $at8sa_settings ) {
	$checked = ! empty( $at8sa_settings[ $key ] );
	?>
	<div class="at8sa-row">
		<label class="at8sa-toggle">
			<input type="hidden" name="at8sa_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
			<input type="checkbox"
				name="at8sa_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( true, $checked ); ?> />
			<span class="at8sa-toggle-text">
				<?php echo esc_html( $label ); ?>
				<?php if ( '' !== $hint ) : ?>
					<span class="at8sa-toggle-hint"><?php echo esc_html( $hint ); ?></span>
				<?php endif; ?>
			</span>
		</label>
	</div>
	<?php
};

/**
 * 渲染数字输入。
 *
 * @param string $key   设置键。
 * @param string $label 标签。
 * @param int    $min   最小值。
 * @param int    $max   最大值。
 * @param int    $step  步长。
 * @return void
 */
$at8sa_number = function ( $key, $label, $min, $max, $step = 1 ) use ( $at8sa_settings ) {
	?>
	<label class="at8sa-field">
		<span><?php echo esc_html( $label ); ?></span>
		<input type="number"
			name="at8sa_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( (int) $at8sa_settings[ $key ] ); ?>"
			min="<?php echo esc_attr( $min ); ?>"
			max="<?php echo esc_attr( $max ); ?>"
			step="<?php echo esc_attr( $step ); ?>" />
	</label>
	<?php
};

/**
 * 渲染多行文本。
 *
 * @param string $key   设置键。
 * @param string $label 标签。
 * @param string $hint  说明。
 * @return void
 */
$at8sa_textarea = function ( $key, $label, $hint = '' ) use ( $at8sa_settings ) {
	?>
	<div class="at8sa-row">
		<label class="at8sa-field at8sa-field--wide">
			<span><?php echo esc_html( $label ); ?></span>
			<textarea name="at8sa_settings[<?php echo esc_attr( $key ); ?>]" rows="4" spellcheck="false"><?php echo esc_textarea( (string) $at8sa_settings[ $key ] ); ?></textarea>
		</label>
		<?php if ( '' !== $hint ) : ?>
			<p class="at8sa-sub"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
	</div>
	<?php
};

/**
 * 渲染下拉。
 *
 * @param string $key     设置键。
 * @param string $label   标签。
 * @param array  $choices 选项 value => label。
 * @return void
 */
$at8sa_select = function ( $key, $label, array $choices ) use ( $at8sa_settings ) {
	$current = (string) $at8sa_settings[ $key ];
	?>
	<label class="at8sa-field">
		<span><?php echo esc_html( $label ); ?></span>
		<select name="at8sa_settings[<?php echo esc_attr( $key ); ?>]">
			<?php foreach ( $choices as $value => $text ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $current ); ?>>
					<?php echo esc_html( $text ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</label>
	<?php
};

/**
 * 渲染诊断分组。
 *
 * @param array $group 分组数据。
 * @return void
 */
$at8sa_diag_group = function ( array $group ) {
	$pill_labels = array(
		'good'   => __( '正常', 'at8-site-accelerator' ),
		'warn'   => __( '注意', 'at8-site-accelerator' ),
		'info'   => __( '信息', 'at8-site-accelerator' ),
		'danger' => __( '风险', 'at8-site-accelerator' ),
	);
	?>
	<div class="at8sa-diag-group">
		<h3><?php echo esc_html( $group['label'] ); ?></h3>
		<table class="at8sa-diag-table">
			<tbody>
			<?php foreach ( $group['items'] as $item ) : ?>
				<tr>
					<th scope="row">
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! empty( $item['status'] ) && isset( $pill_labels[ $item['status'] ] ) ) : ?>
							<span class="at8sa-pill at8sa-pill--<?php echo esc_attr( $item['status'] ); ?>">
								<?php echo esc_html( $pill_labels[ $item['status'] ] ); ?>
							</span>
						<?php endif; ?>
					</th>
					<td class="at8sa-diag-value">
						<?php echo esc_html( (string) $item['value'] ); ?>
						<?php if ( ! empty( $item['note'] ) ) : ?>
							<div class="at8sa-sub"><?php echo esc_html( $item['note'] ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
};

$at8sa_cache_on = ! empty( $at8sa_settings['page_cache'] );
?>
<div class="wrap at8sa-wrap">
	<h1>
		<?php esc_html_e( 'AT8 Site Accelerator', 'at8-site-accelerator' ); ?>
		<span class="at8sa-version">v<?php echo esc_html( AT8SA_VERSION ); ?></span>
	</h1>
	<p class="at8sa-lead">
		<?php esc_html_e( '整页缓存 + 精准失效 + 浏览器缓存 + HTML 压缩 + 图片懒加载 + WebP 转换 + 数据库瘦身。所有数据都只留在你自己的服务器上。', 'at8-site-accelerator' ); ?>
	</p>

	<?php if ( ! empty( $_GET['at8sa_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="at8sa-status at8sa-status--good"><?php esc_html_e( '设置已重置为默认值，缓存已清空。', 'at8-site-accelerator' ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $data['migrated'] ) ) : ?>
		<div class="at8sa-status at8sa-status--info">
			<?php esc_html_e( '检测到 2.x 的旧设置，已自动迁移。原有开关与数值保持不变，旧选项仍保留在数据库中以便回滚。', 'at8-site-accelerator' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! $at8sa_cache_on ) : ?>
		<div class="at8sa-status at8sa-status--warn">
			<?php esc_html_e( '页面缓存当前已关闭，所有页面都由 PHP 实时渲染。', 'at8-site-accelerator' ); ?>
		</div>
	<?php elseif ( ! $at8sa_dropin->is_installed() ) : ?>
		<div class="at8sa-status at8sa-status--warn">
			<?php esc_html_e( '高级缓存 drop-in 未安装：缓存命中时仍会先启动 WordPress。可在「工具」标签页安装，命中路径的收益会明显提升。', 'at8-site-accelerator' ); ?>
		</div>
	<?php elseif ( ! $at8sa_dropin->is_wp_cache_enabled() ) : ?>
		<div class="at8sa-status at8sa-status--warn">
			<?php esc_html_e( 'drop-in 已就位，但 wp-config.php 中的 WP_CACHE 未启用，drop-in 不会被加载。可在「工具」标签页一键启用。', 'at8-site-accelerator' ); ?>
		</div>
	<?php else : ?>
		<div class="at8sa-status at8sa-status--good">
			<?php
			printf(
				/* translators: 1: backend name, 2: cached page count */
				esc_html__( '高级缓存已生效。当前后端：%1$s，已缓存 %2$s 个页面。', 'at8-site-accelerator' ),
				esc_html( $at8sa_backend_name ),
				esc_html( number_format_i18n( (int) $at8sa_stats['count'] ) )
			);
			?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== (string) $data['conflict_notice'] ) : ?>
		<div class="at8sa-status at8sa-status--warn"><?php echo esc_html( $data['conflict_notice'] ); ?></div>
	<?php endif; ?>

	<?php if ( $at8sa_browser->has_risky_combination() ) : ?>
		<div class="at8sa-status at8sa-status--warn">
			<?php esc_html_e( '风险组合：「移除静态资源 ?ver= 版本号」与「静态资源长缓存」同时开启。主题或插件更新后，访客可能长期使用旧的 CSS/JS。建议关闭其中一项。', 'at8-site-accelerator' ); ?>
		</div>
	<?php endif; ?>

	<nav class="at8sa-tabs" role="tablist">
		<?php foreach ( $data['tabs'] as $at8sa_tab_key => $at8sa_tab_label ) : ?>
			<button type="button"
				class="at8sa-tab"
				role="tab"
				data-tab="<?php echo esc_attr( $at8sa_tab_key ); ?>"
				aria-selected="false"><?php echo esc_html( $at8sa_tab_label ); ?></button>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php">
		<?php settings_fields( \AT8\SiteAccelerator\Core\Settings::GROUP ); ?>

		<!-- ============ 概览 ============ -->
		<section class="at8sa-panel" data-panel="overview" role="tabpanel" hidden>
			<div class="at8sa-stats">
				<div class="at8sa-stat">
					<p class="at8sa-stat-label"><?php esc_html_e( '缓存后端', 'at8-site-accelerator' ); ?></p>
					<p class="at8sa-stat-value"><?php echo esc_html( $at8sa_backend_name ); ?></p>
				</div>
				<div class="at8sa-stat">
					<p class="at8sa-stat-label"><?php esc_html_e( '已缓存页面', 'at8-site-accelerator' ); ?></p>
					<p class="at8sa-stat-value"><?php echo esc_html( number_format_i18n( (int) $at8sa_stats['count'] ) ); ?></p>
				</div>
				<div class="at8sa-stat">
					<p class="at8sa-stat-label"><?php esc_html_e( '缓存占用', 'at8-site-accelerator' ); ?></p>
					<p class="at8sa-stat-value"><?php echo esc_html( size_format( (int) $at8sa_stats['bytes'], 2 ) ); ?></p>
				</div>
				<div class="at8sa-stat">
					<p class="at8sa-stat-label"><?php esc_html_e( 'Redis', 'at8-site-accelerator' ); ?></p>
					<p class="at8sa-stat-value">
						<?php echo $data['redis_reachable'] ? esc_html__( '可达', 'at8-site-accelerator' ) : esc_html__( '不可达', 'at8-site-accelerator' ); ?>
					</p>
				</div>
			</div>

			<div class="at8sa-card">
				<div class="at8sa-card-head">
					<h2><?php esc_html_e( '快速操作', 'at8-site-accelerator' ); ?></h2>
				</div>
				<p class="at8sa-card-note"><?php esc_html_e( '清空整站缓存会同时递增缓存版本盐，确保旧缓存即便删除不干净也不会被访客命中。', 'at8-site-accelerator' ); ?></p>
				<div class="at8sa-actions">
					<button type="button" class="button button-primary" id="at8sa-purge-all"><?php esc_html_e( '清空整站缓存', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-purge-all-msg"></span>
				</div>
			</div>

			<?php foreach ( $data['diagnostics'] as $at8sa_group ) : ?>
				<?php $at8sa_diag_group( $at8sa_group ); ?>
			<?php endforeach; ?>
		</section>

		<!-- ============ 页面缓存 ============ -->
		<section class="at8sa-panel" data-panel="cache" role="tabpanel" hidden>
			<div class="at8sa-card">
				<h2><?php esc_html_e( '① 页面缓存', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '只缓存未登录访客的 GET 请求。后台、AJAX、REST、预览、搜索、404、购物车与结算页一律自动绕过。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'page_cache', __( '启用访客页面缓存', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'advanced_cache', __( '启用 advanced-cache.php 高级缓存', 'at8-site-accelerator' ), __( '让缓存命中在 WordPress 启动前就返回，TTFB 收益最大。需要在「工具」里确保 drop-in 已安装且 WP_CACHE 已启用。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'cache_mobile', __( '为移动端单独缓存', 'at8-site-accelerator' ), __( '开启后桌面与移动端各存一份，避免响应式主题给移动端输出不同结构时串页。会同时发送 Vary: User-Agent。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'cache_logged_in', __( '也为登录用户缓存', 'at8-site-accelerator' ), __( '默认关闭。开启后登录用户会看到彼此相同的页面（含管理栏差异），除非你确定站点没有个性化内容，否则不要开。', 'at8-site-accelerator' ) ); ?>

				<div class="at8sa-row">
					<?php $at8sa_number( 'cache_ttl', __( '缓存有效期（秒）', 'at8-site-accelerator' ), 60, 2592000, 60 ); ?>
					<?php
					$at8sa_select(
						'cache_backend',
						__( '缓存后端', 'at8-site-accelerator' ),
						array(
							'auto'  => __( '自动（Redis 优先，不可用时降级磁盘）', 'at8-site-accelerator' ),
							'disk'  => __( '强制磁盘', 'at8-site-accelerator' ),
							'redis' => __( '强制 Redis', 'at8-site-accelerator' ),
						)
					);
					?>
				</div>

				<?php $at8sa_textarea( 'exclude_urls', __( '排除 URL 关键词（每行一个，命中即不缓存）', 'at8-site-accelerator' ), __( '内置已排除 wp-admin、wp-login、wp-json、xmlrpc.php、preview、admin-ajax、feed、?s= 等。此处只填你额外需要的。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_textarea( 'bypass_cookies', __( '遇到这些 Cookie 时绕过缓存（每行一个，支持前缀*）', 'at8-site-accelerator' ), __( '内置已包含 wordpress_logged_in_、wordpress_sec_、wp-postpass_、woocommerce_* 等。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_textarea( 'ignore_query', __( '忽略的 Query 参数（每行一个，支持前缀*）', 'at8-site-accelerator' ), __( '内置已自动忽略 UTM、fbclid、gclid、msclkid 等营销参数，避免同一页面因追踪参数产生大量碎片缓存。填 * 表示忽略全部 query（谨慎）。', 'at8-site-accelerator' ) ); ?>
			</div>
		</section>

		<!-- ============ 失效与预加载 ============ -->
		<section class="at8sa-panel" data-panel="purge" role="tabpanel" hidden>
			<div class="at8sa-card">
				<h2><?php esc_html_e( '② 缓存失效策略', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '内容更新时，插件会算出受影响的 URL 集合（文章页、首页、文章类型归档、所属分类与父分类、作者归档、分页），只删除这些页面的缓存。', 'at8-site-accelerator' ); ?></p>

				<?php
				$at8sa_select(
					'purge_scope',
					__( '内容保存时的失效范围', 'at8-site-accelerator' ),
					array(
						'related' => __( '仅相关页面（推荐，命中率最高）', 'at8-site-accelerator' ),
						'all'     => __( '整站清空（与 2.x 行为一致）', 'at8-site-accelerator' ),
					)
				);
				?>
				<?php $at8sa_toggle( 'purge_home_on_save', __( '保存文章时同时失效首页', 'at8-site-accelerator' ) ); ?>

				<div class="at8sa-row">
					<p class="at8sa-sub"><?php esc_html_e( '切换主题、启停插件、升级、改菜单、Elementor 保存等结构性变更，无论上面的设置如何，一律整站失效——因为这类改动可能影响任意页面。', 'at8-site-accelerator' ); ?></p>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '③ 前端链接预取', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '访客鼠标悬停或触摸链接时，提前把目标页拉进浏览器缓存。它改善的是"点下去之后的体感"，不会让服务器产生缓存。服务端的批量缓存预热属于 Pro 功能。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'preload_enable', __( '启用链接预取', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'preload_viewport', __( '链接进入视口时预取', 'at8-site-accelerator' ), __( '避免一次性预取全页链接。页面切到后台标签页时会自动停止。', 'at8-site-accelerator' ) ); ?>

				<div class="at8sa-row">
					<?php $at8sa_number( 'hover_delay', __( '悬停延迟（毫秒）', 'at8-site-accelerator' ), 0, 2000, 10 ); ?>
					<?php $at8sa_number( 'touch_delay', __( '触摸延迟（毫秒）', 'at8-site-accelerator' ), 0, 2000, 10 ); ?>
					<?php $at8sa_number( 'max_preloads', __( '单页最多预取数', 'at8-site-accelerator' ), 1, 200, 1 ); ?>
					<?php $at8sa_number( 'max_per_domain', __( '单域名上限', 'at8-site-accelerator' ), 1, 100, 1 ); ?>
					<?php $at8sa_number( 'preload_cooldown', __( '同一链接冷却（秒）', 'at8-site-accelerator' ), 0, 86400, 30 ); ?>
				</div>

				<div class="at8sa-row">
					<span class="at8sa-field at8sa-field--wide">
						<span><?php esc_html_e( '预取策略（可多选）', 'at8-site-accelerator' ); ?></span>
					</span>
					<?php
					$at8sa_strategies = array(
						'prefetch'     => __( 'prefetch —— 预取 HTML（推荐）', 'at8-site-accelerator' ),
						'preconnect'   => __( 'preconnect —— 预建连接', 'at8-site-accelerator' ),
						'dns-prefetch' => __( 'dns-prefetch —— DNS 预解析', 'at8-site-accelerator' ),
						'prerender'    => __( 'prerender —— 预渲染（流量与 CPU 开销大，慎用）', 'at8-site-accelerator' ),
					);
					$at8sa_selected   = is_array( $at8sa_settings['preload_strategy'] ) ? $at8sa_settings['preload_strategy'] : array();
					?>
					<input type="hidden" name="at8sa_settings[preload_strategy][]" value="" />
					<?php
					foreach ( $at8sa_strategies as $at8sa_value => $at8sa_label ) :
						?>
						<label class="at8sa-toggle">
							<input type="checkbox"
								name="at8sa_settings[preload_strategy][]"
								value="<?php echo esc_attr( $at8sa_value ); ?>"
								<?php checked( true, in_array( $at8sa_value, $at8sa_selected, true ) ); ?> />
							<span class="at8sa-toggle-text"><?php echo esc_html( $at8sa_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<?php $at8sa_toggle( 'dns_prefetch', __( '输出本站 DNS 预取与预连接', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'resource_preload', __( '预加载预取脚本本身', 'at8-site-accelerator' ), __( '在 <head> 里输出 <link rel="preload" as="script">，让预取脚本更早可用。旧版叫「HTTP/2 Server Push」，但浏览器已移除该能力，实际生效的一直是资源预加载。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_textarea( 'preconnect_hosts', __( '第三方预连接域名（每行一个）', 'at8-site-accelerator' ), __( '例如 fonts.googleapis.com。只在确实用到时添加，过多的 preconnect 反而会浪费连接数。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'preload_debug', __( '在浏览器控制台输出预取调试日志', 'at8-site-accelerator' ) ); ?>
			</div>
		</section>

		<!-- ============ 优化 ============ -->
		<section class="at8sa-panel" data-panel="optimize" role="tabpanel" hidden>
			<div class="at8sa-card">
				<h2><?php esc_html_e( '④ 浏览器缓存', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( 'PHP 只能给 HTML 文档设置缓存头；CSS/JS/图片由 Web 服务器直接返回，需要服务器规则。到「工具」标签页可以复制对应你服务器的规则片段。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'browser_cache', __( '启用浏览器缓存头', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'browser_cache_html', __( '让 HTML 也参与浏览器长缓存', 'at8-site-accelerator' ), __( '默认关闭。开启后访客在有效期内不会回源，内容更新会有延迟。', 'at8-site-accelerator' ) ); ?>
				<div class="at8sa-row">
					<?php $at8sa_number( 'browser_cache_ttl', __( '静态资源缓存（秒）', 'at8-site-accelerator' ), 3600, 31536000, 3600 ); ?>
					<?php $at8sa_number( 'browser_cache_html_ttl', __( 'HTML 缓存（秒）', 'at8-site-accelerator' ), 0, 2592000, 300 ); ?>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '⑤ HTML 压缩', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '保守实现：只删注释、只在标签之间折叠空白。pre / textarea / script / style / svg 内容原样保留。压缩后体积低于原始 40% 时会自动放弃本次压缩，防止正则误伤。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'html_minify', __( '启用 HTML 压缩', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'html_minify_inline', __( '同时压缩内联 CSS / JS', 'at8-site-accelerator' ), __( '只折叠内联 <code>&lt;style&gt;</code> 里的连续空白，不动内联 JS（JS 的换行影响自动分号插入，风险不成比例）。若你的主题输出的内联 CSS 本就紧凑（WordPress 区块主题通常如此），开启后不会有可观测变化。', 'at8-site-accelerator' ) ); ?>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '⑥ 图片懒加载', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '使用浏览器原生的 loading="lazy"，不引入任何 JavaScript。首屏前两张图会显式标记为 eager 并跳过懒加载，避免恶化 LCP。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'lazyload', __( '启用图片懒加载', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'lazyload_iframes', __( '同时对 iframe 启用懒加载', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'lazyload_skip_first', __( '跳过首屏前 2 张图', 'at8-site-accelerator' ), __( '强烈建议保持开启。给首屏大图加 lazy 是最常见的自伤式优化。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_textarea( 'lazyload_exclude', __( '排除关键词（每行一个，命中则不处理）', 'at8-site-accelerator' ), __( '内置已排除 emoji、wpicons、gravatar、Elementor 占位图等。', 'at8-site-accelerator' ) ); ?>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '⑦ 图片格式', 'at8-site-accelerator' ); ?></h2>
				<?php $at8sa_toggle( 'webp_convert', __( '上传时自动生成 WebP 副本', 'at8-site-accelerator' ), __( '需要服务器配置下发规则，插件在「工具」里提供 nginx 片段。转换后体积反而变大的图片会自动放弃。删除附件时同步清理副本。', 'at8-site-accelerator' ) ); ?>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '⑧ 前端资源精简', 'at8-site-accelerator' ); ?></h2>
				<?php $at8sa_toggle( 'disable_emoji', __( '禁用 Emoji 脚本', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_embeds', __( '禁用 wp-embed', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'remove_wp_generator', __( '移除 WordPress 版本号输出', 'at8-site-accelerator' ), __( '同时移除 Elementor / 主题输出的 generator 元标签。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_jquery_migrate', __( '禁用 jQuery Migrate', 'at8-site-accelerator' ), __( '如遇旧插件报错请关闭。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_dashicons', __( '前台（非登录用户）禁用 Dashicons', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'remove_query_strings', __( '移除静态资源 ?ver= 版本号', 'at8-site-accelerator' ), __( '利于 CDN 与浏览器缓存，但与静态资源长缓存同时开启时会导致更新不生效，后台会给出警告。', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_block_css', __( '前台禁用 Gutenberg 区块样式', 'at8-site-accelerator' ), __( '非区块主题可关；用区块编辑器写的页面建议保留。', 'at8-site-accelerator' ) ); ?>
				<div class="at8sa-row">
					<?php
					$at8sa_select(
						'heartbeat',
						__( 'Heartbeat 心跳频率', 'at8-site-accelerator' ),
						array(
							'default' => __( '默认（15 秒）', 'at8-site-accelerator' ),
							'reduce'  => __( '降低频率（60 秒）', 'at8-site-accelerator' ),
							'disable' => __( '前台禁用', 'at8-site-accelerator' ),
						)
					);
					?>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '⑨ 后台瘦身', 'at8-site-accelerator' ); ?></h2>
				<?php $at8sa_toggle( 'remove_site_health', __( '移除「站点健康」', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'remove_events_news', __( '移除「WordPress 活动与新闻」', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_version_checks', __( '禁止浏览器 / PHP 版本检测提示', 'at8-site-accelerator' ) ); ?>
				<?php $at8sa_toggle( 'disable_large_thumbs', __( '不再生成 medium_large / 1536 / 2048 尺寸', 'at8-site-accelerator' ), __( '只影响新上传的图片，已有缩略图不会被删除。', 'at8-site-accelerator' ) ); ?>
			</div>
		</section>

		<!-- ============ 数据库 ============ -->
		<section class="at8sa-panel" data-panel="database" role="tabpanel" hidden>
			<div class="at8sa-card at8sa-danger-zone">
				<h2><?php esc_html_e( '⑩ 数据库清理', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note">
					<?php esc_html_e( '这里的操作不可撤销，且默认全部关闭。请先点「预览数量」看清将要删除多少条，再勾选执行。回收站与草稿类项目删除后无法通过 WordPress 后台恢复。', 'at8-site-accelerator' ); ?>
				</p>

				<?php
				foreach ( $data['db_preview'] as $at8sa_db_key => $at8sa_db_item ) :
					$at8sa_label = $at8sa_db_item['label'] . '（当前 ' . number_format_i18n( $at8sa_db_item['count'] ) . ' 条）';
					$at8sa_hint  = ! empty( $at8sa_db_item['danger'] )
						? __( '⚠ 删除后不可从后台恢复。', 'at8-site-accelerator' )
						: '';
					$at8sa_toggle( $at8sa_db_key, $at8sa_label, $at8sa_hint );
				endforeach;
				?>

				<div class="at8sa-row">
					<?php
					$at8sa_select(
						'db_schedule',
						__( '自动清理计划', 'at8-site-accelerator' ),
						array(
							'off'    => __( '关闭', 'at8-site-accelerator' ),
							'daily'  => __( '每天一次', 'at8-site-accelerator' ),
							'weekly' => __( '每周一次', 'at8-site-accelerator' ),
						)
					);
					?>
					<span class="at8sa-sub"><?php esc_html_e( '定时任务只执行你已勾选的项目。单次最多处理 5000 行，避免大站点超时。', 'at8-site-accelerator' ); ?></span>
				</div>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-db-preview"><?php esc_html_e( '预览数量', 'at8-site-accelerator' ); ?></button>
					<button type="button" class="button button-secondary" id="at8sa-db-run"><?php esc_html_e( '执行清理', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-db-msg"></span>
				</div>

				<div id="at8sa-db-preview-result"></div>
			</div>
		</section>

		<!-- ============ 兼容与诊断 ============ -->
		<section class="at8sa-panel" data-panel="compat" role="tabpanel" hidden>
			<div class="at8sa-card">
				<div class="at8sa-card-head">
					<h2><?php esc_html_e( '第三方缓存 / 优化插件检测', 'at8-site-accelerator' ); ?></h2>
				</div>
				<p class="at8sa-card-note"><?php esc_html_e( '本插件不会自动停用任何插件。同时启用多个整页缓存会导致"内容不更新"或"样式错乱"，请自行决定保留哪一个。', 'at8-site-accelerator' ); ?></p>

				<?php if ( empty( $data['conflicts'] ) ) : ?>
					<p class="at8sa-empty"><?php esc_html_e( '未发现已知冲突。', 'at8-site-accelerator' ); ?></p>
				<?php else : ?>
					<table class="at8sa-diag-table">
						<tbody>
						<?php foreach ( $data['conflicts'] as $at8sa_conflict ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $at8sa_conflict['name'] ); ?></th>
								<td>
									<?php echo esc_html( $at8sa_conflict['type'] ); ?>
									<span class="at8sa-pill at8sa-pill--<?php echo 'high' === $at8sa_conflict['severity'] ? 'warn' : 'info'; ?>">
										<?php echo 'high' === $at8sa_conflict['severity'] ? esc_html__( '高风险', 'at8-site-accelerator' ) : esc_html__( '低风险', 'at8-site-accelerator' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-rescan-conflicts"><?php esc_html_e( '重新检测', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-conflict-msg"></span>
				</div>
			</div>

			<?php foreach ( $data['diagnostics'] as $at8sa_group ) : ?>
				<?php $at8sa_diag_group( $at8sa_group ); ?>
			<?php endforeach; ?>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '安全模式', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '开启后立即停止所有缓存读写与失效，但保留设置与缓存文件。适合在排查"某功能异常是否由缓存引起"时使用——比停用插件更快、更可逆。', 'at8-site-accelerator' ); ?></p>
				<?php $at8sa_toggle( 'safe_mode', __( '启用安全模式（暂停缓存）', 'at8-site-accelerator' ) ); ?>
			</div>
		</section>

		<!-- ============ 工具 ============ -->
		<section class="at8sa-panel" data-panel="tools" role="tabpanel" hidden>
			<div class="at8sa-card">
				<h2><?php esc_html_e( '高级缓存 drop-in', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( 'drop-in 把缓存命中提前到 WordPress 启动之前。启用 WP_CACHE 会修改 wp-config.php，修改前会自动备份为 wp-config.php.at8sa.bak，写入后会做完整性校验，校验失败立即回滚。', 'at8-site-accelerator' ); ?></p>

				<table class="at8sa-diag-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'advanced-cache.php', 'at8-site-accelerator' ); ?></th>
							<td>
								<?php echo $at8sa_dropin->is_installed() ? esc_html__( '已安装', 'at8-site-accelerator' ) : esc_html__( '未安装', 'at8-site-accelerator' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WP_CACHE 常量', 'at8-site-accelerator' ); ?></th>
							<td>
								<?php echo $at8sa_dropin->is_wp_cache_enabled() ? 'true' : esc_html__( '未定义 / false', 'at8-site-accelerator' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-install-dropin"><?php esc_html_e( '安装 / 更新 drop-in', 'at8-site-accelerator' ); ?></button>
					<button type="button" class="button" id="at8sa-enable-wp-cache"><?php esc_html_e( '启用 WP_CACHE', 'at8-site-accelerator' ); ?></button>
					<button type="button" class="button" id="at8sa-disable-wp-cache"><?php esc_html_e( '停用 WP_CACHE', 'at8-site-accelerator' ); ?></button>
					<button type="button" class="button" id="at8sa-remove-dropin"><?php esc_html_e( '移除 drop-in', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-dropin-msg"></span>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '服务器静态资源规则', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note">
					<?php
					printf(
						/* translators: %s: detected server type */
						esc_html__( '检测到的服务器类型：%s。本插件不会自动改写你的服务器配置；请复制下方片段自行合并（若你已有同类规则，合并即可，不要重复声明）。', 'at8-site-accelerator' ),
						esc_html( $at8sa_browser->server_type() )
					);
					?>
				</p>

				<h3><?php esc_html_e( 'nginx', 'at8-site-accelerator' ); ?></h3>
				<div class="at8sa-code">
					<pre id="at8sa-nginx-rules"><?php echo esc_html( $at8sa_browser->nginx_rules() ); ?></pre>
					<div class="at8sa-code-actions">
						<button type="button" class="button" data-at8sa-copy="#at8sa-nginx-rules"><?php esc_html_e( '复制 nginx 规则', 'at8-site-accelerator' ); ?></button>
					</div>
				</div>

				<h3 style="margin-top:18px;"><?php esc_html_e( 'Apache / LiteSpeed', 'at8-site-accelerator' ); ?></h3>
				<div class="at8sa-code">
					<pre id="at8sa-apache-rules"><?php echo esc_html( $at8sa_browser->apache_rules() ); ?></pre>
					<div class="at8sa-code-actions">
						<button type="button" class="button" data-at8sa-copy="#at8sa-apache-rules"><?php esc_html_e( '复制 Apache 规则', 'at8-site-accelerator' ); ?></button>
						<button type="button" class="button" id="at8sa-write-htaccess"><?php esc_html_e( '写入 .htaccess（会先备份）', 'at8-site-accelerator' ); ?></button>
						<button type="button" class="button" id="at8sa-remove-htaccess"><?php esc_html_e( '移除 .htaccess 规则块', 'at8-site-accelerator' ); ?></button>
					</div>
					<span class="at8sa-inline-msg" id="at8sa-htaccess-msg"></span>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '日志', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '日志只写在本地服务器，默认关闭，绝不会发送到任何远程服务。日志中的密钥形态字符串会被自动脱敏。', 'at8-site-accelerator' ); ?></p>

				<?php $at8sa_toggle( 'log_enabled', __( '启用日志', 'at8-site-accelerator' ) ); ?>
				<div class="at8sa-row">
					<?php
					$at8sa_select(
						'log_level',
						__( '日志级别', 'at8-site-accelerator' ),
						array(
							'error'   => __( '仅错误', 'at8-site-accelerator' ),
							'warning' => __( '错误 + 警告', 'at8-site-accelerator' ),
							'info'    => __( '错误 + 警告 + 信息', 'at8-site-accelerator' ),
							'debug'   => __( '全部（含调试）', 'at8-site-accelerator' ),
						)
					);
					?>
					<span class="at8sa-sub">
						<?php
						printf(
							/* translators: %s: log file path */
							esc_html__( '日志文件：%s（超过 1MB 自动轮转）', 'at8-site-accelerator' ),
							esc_html( $at8sa_logger->file() )
						);
						?>
					</span>
				</div>

				<?php if ( empty( $data['log_lines'] ) ) : ?>
					<p class="at8sa-empty"><?php esc_html_e( '暂无日志。', 'at8-site-accelerator' ); ?></p>
				<?php else : ?>
					<pre class="at8sa-log" id="at8sa-log-output"><?php echo esc_html( implode( "\n", $data['log_lines'] ) ); ?></pre>
				<?php endif; ?>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-clear-log"><?php esc_html_e( '清空日志', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-log-msg"></span>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '单个 URL 清缓存', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '只删除该 URL 的缓存目录（含桌面与移动端两个变体），不影响其它页面。', 'at8-site-accelerator' ); ?></p>
				<div class="at8sa-actions">
					<input type="text" class="regular-text" id="at8sa-purge-url-input" placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) ); ?>" />
					<button type="button" class="button" id="at8sa-purge-url"><?php esc_html_e( '清理该 URL', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-purge-url-msg"></span>
				</div>
			</div>

			<div class="at8sa-card">
				<h2><?php esc_html_e( '设置导入 / 导出', 'at8-site-accelerator' ); ?></h2>
				<p class="at8sa-card-note"><?php esc_html_e( '导出的 JSON 只包含本插件的设置项，不含任何密钥、账号或站点内容。导入时会走与后台表单完全相同的清洗流程。', 'at8-site-accelerator' ); ?></p>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-export"><?php esc_html_e( '导出设置（下载 JSON）', 'at8-site-accelerator' ); ?></button>
					<span class="at8sa-inline-msg" id="at8sa-io-msg"></span>
				</div>

				<div class="at8sa-row">
					<label class="at8sa-field at8sa-field--wide">
						<span><?php esc_html_e( '粘贴导出的 JSON 后点击导入', 'at8-site-accelerator' ); ?></span>
						<textarea id="at8sa-import-input" rows="5" spellcheck="false"></textarea>
					</label>
				</div>

				<div class="at8sa-actions">
					<button type="button" class="button" id="at8sa-import"><?php esc_html_e( '导入设置', 'at8-site-accelerator' ); ?></button>
				</div>

				<pre class="at8sa-log" id="at8sa-export-output" style="display:none;"></pre>
			</div>

			<div class="at8sa-card at8sa-danger-zone">
				<h2><?php esc_html_e( '卸载行为', 'at8-site-accelerator' ); ?></h2>
				<?php $at8sa_toggle( 'keep_data_on_uninstall', __( '卸载插件时保留设置数据', 'at8-site-accelerator' ), __( '默认保留。关闭后卸载会删除本插件的所有选项与临时数据（缓存目录无论开关都会清理）。', 'at8-site-accelerator' ) ); ?>
			</div>
		</section>

		<div class="at8sa-save-bar">
			<?php submit_button( __( '保存设置', 'at8-site-accelerator' ), 'primary', 'submit', false ); ?>
			<span class="at8sa-sub"><?php esc_html_e( '保存设置会重写 drop-in 运行时配置并清空整站缓存，确保新旧配置不混用。', 'at8-site-accelerator' ); ?></span>
			<a class="button" style="margin-left:auto;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=at8sa_reset_settings' ), 'at8sa_reset_settings' ) ); ?>">
				<?php esc_html_e( '重置为默认值', 'at8-site-accelerator' ); ?>
			</a>
		</div>
	</form>
</div>

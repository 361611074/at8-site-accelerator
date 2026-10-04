<?php
/**
 * 管理栏「清空缓存」按钮。
 *
 * 与 2.x 的差异：2.x 用的是「GET 链接 + nonce」，存在被浏览器预取/悬停预加载
 * 意外触发的风险（`wp_nonce_url` 的 URL 一旦被 prefetch，缓存就真的被清了）。
 * 这里改为**纯按钮 + fetch(POST)**，不产生任何可被预取的 GET 链接。
 *
 * @package AT8SA\Admin
 */

namespace AT8SA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class AdminBar
 */
final class AdminBar {

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 99 );
		add_action( 'admin_footer', array( $this, 'render_script' ) );
		add_action( 'wp_footer', array( $this, 'render_script' ) );
	}

	/**
	 * 添加管理栏节点。
	 *
	 * @param \WP_Admin_Bar $bar 管理栏。
	 * @return void
	 */
	public function add_node( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'at8sa-purge',
				'title' => '<span class="ab-icon dashicons-performance"></span>'
					. '<span class="ab-label">' . esc_html__( '清空缓存', 'at8-site-accelerator' ) . '</span>',
				'href'  => '#',
				'meta'  => array(
					'class' => 'at8sa-purge-bar',
					'title' => __( '清空 AT8 Site Accelerator 的整站缓存', 'at8-site-accelerator' ),
				),
			)
		);
	}

	/**
	 * 输出绑定脚本。
	 *
	 * @return void
	 */
	public function render_script() {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
			return;
		}

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( Ajax::NONCE ),
			'i18n'    => array(
				'working' => __( '正在清空缓存…', 'at8-site-accelerator' ),
				'done'    => __( '缓存已清空', 'at8-site-accelerator' ),
				'failed'  => __( '清空失败，请查看日志。', 'at8-site-accelerator' ),
				'confirm' => __( '确定清空整站缓存？', 'at8-site-accelerator' ),
			),
		);
		?>
		<script id="at8sa-adminbar-js">
		(function(){
			var cfg = <?php echo wp_json_encode( $config ); ?>;

			function notify( text, isError ) {
				var box = document.createElement( 'div' );
				box.className = 'notice ' + ( isError ? 'notice-error' : 'notice-success' ) + ' is-dismissible';
				box.setAttribute( 'style', 'position:fixed;top:46px;right:20px;z-index:100000;max-width:340px;padding:8px 12px;' );
				box.innerHTML = '<p style="margin:0;">' + text + '</p>';
				document.body.appendChild( box );
				setTimeout( function(){ if ( box.parentNode ) { box.parentNode.removeChild( box ); } }, 3000 );
			}

			function bind() {
				var node = document.getElementById( 'wp-admin-bar-at8sa-purge' );
				if ( ! node ) { return; }

				var link = node.querySelector( 'a' );
				if ( ! link || link.dataset.at8saBound ) { return; }

				link.dataset.at8saBound = '1';
				link.addEventListener( 'click', function( event ){
					event.preventDefault();

					if ( ! window.confirm( cfg.i18n.confirm ) ) { return; }

					notify( cfg.i18n.working, false );

					var body = new URLSearchParams();
					body.append( 'action', 'at8sa_purge_all' );
					body.append( 'nonce', cfg.nonce );

					window.fetch( cfg.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: body.toString()
					} )
					.then( function( response ){ return response.json(); } )
					.then( function( json ){
						if ( json && json.success ) {
							notify( ( json.data && json.data.message ) || cfg.i18n.done, false );
							setTimeout( function(){ window.location.reload(); }, 600 );
						} else {
							notify( ( json && json.data && json.data.message ) || cfg.i18n.failed, true );
						}
					} )
					.catch( function(){ notify( cfg.i18n.failed, true ); } );
				} );
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', bind );
			} else {
				bind();
			}
		})();
		</script>
		<?php
	}
}

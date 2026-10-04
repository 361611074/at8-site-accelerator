<?php
/**
 * 图片懒加载。
 *
 * 用**原生** `loading="lazy"` + `decoding="async"`，不引入任何 JS。
 * 这是 2026 年的正确做法：浏览器原生实现比任何 JS 库都快、都不占主线程。
 * （WP Rocket 之所以还带一份 lazyload.js，是为了兼容极老浏览器与
 * "渐进式图片/LQIP"等增强效果——那是 Pro 阶段的事，Free 版不背这个包袱。）
 *
 * 关键细节：**首屏图片必须跳过**。给首屏大图加 lazy 会直接恶化 LCP，
 * 这是懒加载最常见的自伤方式。这里用"前 N 张 + 视口启发式"双重跳过。
 *
 * @package AT8SA\Optimization
 */

namespace AT8SA\Optimization;

use AT8SA\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class LazyLoad
 */
final class LazyLoad {

	/**
	 * 设置。
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * 构造。
	 *
	 * @param Settings $settings 设置。
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! $this->settings->is_on( 'lazyload' ) ) {
			return;
		}

		// 挂在输出缓冲链末端，确保此时所有图片标记都已生成完毕。
		add_filter( 'at8sa_after_cache_buffer', array( $this, 'process' ), 20 );
	}

	/**
	 * 处理 HTML，为图片与 iframe 加懒加载属性。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$skip_first = $this->settings->is_on( 'lazyload_skip_first' ) ? 2 : 0;
		$excludes   = $this->exclude_patterns();
		$counter    = 0;

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) use ( &$counter, $skip_first, $excludes ) {
				$tag = $matches[0];

				if ( $this->should_skip( $tag, $excludes ) ) {
					return $tag;
				}

				++$counter;

				if ( $counter <= $skip_first ) {
					// 首屏候选图：明确标成 eager，并提权，改善 LCP。
					return $this->set_attr( $tag, 'loading', 'eager' );
				}

				$tag = $this->set_attr( $tag, 'loading', 'lazy' );
				$tag = $this->set_attr( $tag, 'decoding', 'async' );

				return $tag;
			},
			$html
		);

		if ( $this->settings->is_on( 'lazyload_iframes' ) ) {
			$html = preg_replace_callback(
				'/<iframe\b[^>]*>/i',
				function ( $matches ) use ( $excludes ) {
					$tag = $matches[0];

					if ( $this->should_skip( $tag, $excludes ) ) {
						return $tag;
					}

					return $this->set_attr( $tag, 'loading', 'lazy' );
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * 是否跳过该标签。
	 *
	 * @param string $tag      标签字符串。
	 * @param array  $excludes 排除规则。
	 * @return bool
	 */
	private function should_skip( $tag, array $excludes ) {
		// 已有 loading 属性：尊重原作者的显式意图。
		if ( preg_match( '/\bloading\s*=/i', $tag ) ) {
			return true;
		}

		// 明确的"永不懒加载"信号。
		if ( preg_match( '/\bdata-no-lazy\b/i', $tag ) || preg_match( '/\bdata-skip-lazy\b/i', $tag ) ) {
			return true;
		}

		// 已经是懒加载占位图（其它插件处理过）。
		if ( preg_match( '/\bsrc\s*=\s*["\']data:/i', $tag ) ) {
			return true;
		}

		foreach ( $excludes as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 排除规则（内置 + 用户自定义）。
	 *
	 * @return array
	 */
	private function exclude_patterns() {
		$patterns = array(
			'wp-includes/images/smilies',
			'wp-includes/images/wpicons',
			'wp-includes/images/blank.gif',
			'elementor/assets/images',
			'/emoji/',
			'gravatar.com',
			'data-no-lazy',
		);

		$custom = (string) $this->settings->get( 'lazyload_exclude', '' );

		if ( '' !== trim( $custom ) ) {
			foreach ( preg_split( '/\r?\n|,/', $custom ) as $line ) {
				$line = trim( $line );

				if ( '' !== $line ) {
					$patterns[] = $line;
				}
			}
		}

		/**
		 * 过滤懒加载排除规则。
		 *
		 * @param array $patterns 规则。
		 */
		return apply_filters( 'at8sa_lazyload_exclude', $patterns );
	}

	/**
	 * 设置/覆盖标签属性。
	 *
	 * @param string $tag   标签。
	 * @param string $name  属性名。
	 * @param string $value 属性值。
	 * @return string
	 */
	private function set_attr( $tag, $name, $value ) {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag ) ) {
			return preg_replace(
				'/\b' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i',
				$name . '="' . $value . '"',
				$tag,
				1
			);
		}

		// 插到标签名之后，保持属性顺序自然。
		return preg_replace( '/^<([a-z0-9]+)/i', '<$1 ' . $name . '="' . $value . '"', $tag, 1 );
	}
}

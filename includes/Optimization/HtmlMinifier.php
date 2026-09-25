<?php
/**
 * HTML 压缩器。
 *
 * 设计取向：**保守优先**。整页缓存的收益主要来自"省掉 PHP 渲染"，
 * 压缩只是顺手的边际收益；为了多省 2% 体积而压坏页面是极不划算的交易。
 * 因此这里：
 * - 只删 HTML 注释（保留 IE 条件注释与我们的指纹标记）；
 * - 只在标签之间折叠空白，**不动标签内部的属性值**；
 * - `<pre>` / `<textarea>` / `<script>` / `<style>` 内容原样保留；
 * - 内联 CSS/JS 的压缩默认关闭（`html_minify_inline`），开启后也只做最保守的
 *   空白折叠，绝不做变量名替换之类的"真压缩"（那需要解析器，风险不成比例）。
 *
 * @package AT8\SiteAccelerator\Optimization
 */

namespace AT8\SiteAccelerator\Optimization;

use AT8\SiteAccelerator\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class HtmlMinifier
 */
final class HtmlMinifier {

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
	 * 压缩 HTML。
	 *
	 * @param string $html 原始 HTML。
	 * @return string
	 */
	public function minify( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 1024 ) {
			return $html; // 太短的页面压缩收益为负。
		}

		// 已经是压缩过的（比如其它插件先压过）就跳过，避免二次处理引入风险。
		if ( ! empty( $GLOBALS['at8sa_minify_done'] ) ) {
			return $html;
		}

		$original = $html;
		$html     = $this->strip_comments( $html );

		$placeholders = array();
		$html         = $this->protect_blocks( $html, $placeholders );

		// 标签之间的空白 → 单个空格（不能直接删成空，否则会粘连行内文本）。
		$html = preg_replace( '/>\s+</', '> <', $html );
		$html = preg_replace( '/[ \t]{2,}/', ' ', $html );
		$html = preg_replace( '/\r\n|\r|\n/', ' ', $html );
		$html = preg_replace( '/>\s{2,}</', '> <', $html );

		$html = $this->restore_blocks( $html, $placeholders );

		// 安全阀：压缩后长度不应低于原始的 40%。低于则说明正则吃掉了正常内容，直接放弃。
		if ( strlen( $html ) < (int) ( strlen( $original ) * 0.4 ) ) {
			return $original;
		}

		$GLOBALS['at8sa_minify_done'] = true;

		return $html;
	}

	/**
	 * 删除 HTML 注释，保留条件注释与插件指纹。
	 *
	 * @param string $html HTML。
	 * @return string
	 */
	private function strip_comments( $html ) {
		return preg_replace_callback(
			'/<!--(.*?)-->/s',
			function ( $matches ) {
				$body = $matches[1];

				// IE 条件注释必须保留。
				if ( false !== stripos( $body, '[if' ) || false !== stripos( $body, '<![endif' ) ) {
					return $matches[0];
				}

				// 我们的指纹标记保留，便于线上排查。
				if ( false !== stripos( $body, 'AT8 Site Accelerator' ) ) {
					return $matches[0];
				}

				// 其它插件/主题的标记类注释保留，避免破坏依赖注释的脚本。
				if ( preg_match( '/^\s*(\/|!)/', $body ) ) {
					return $matches[0];
				}

				return '';
			},
			$html
		);
	}

	/**
	 * 把不能动的区块替换成占位符。
	 *
	 * @param string $html         HTML。
	 * @param array  $placeholders 占位符表（引用传递）。
	 * @return string
	 */
	private function protect_blocks( $html, array &$placeholders ) {
		$patterns = array(
			'/<pre\b[^>]*>.*?<\/pre>/is',
			'/<textarea\b[^>]*>.*?<\/textarea>/is',
			'/<script\b[^>]*>.*?<\/script>/is',
			'/<style\b[^>]*>.*?<\/style>/is',
			'/<svg\b[^>]*>.*?<\/svg>/is',
		);

		foreach ( $patterns as $pattern ) {
			$html = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( &$placeholders ) {
					$key = '<!--AT8BLOCK' . count( $placeholders ) . '-->';

					$placeholders[ $key ] = $matches[0];

					return $key;
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * 还原受保护区块。
	 *
	 * @param string $html         HTML。
	 * @param array  $placeholders 占位符表。
	 * @return string
	 */
	private function restore_blocks( $html, array $placeholders ) {
		if ( empty( $placeholders ) ) {
			return $html;
		}

		return strtr( $html, $placeholders );
	}
}

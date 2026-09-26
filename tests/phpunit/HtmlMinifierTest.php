<?php
/**
 * HtmlMinifier 单元测试。
 *
 * 压缩 HTML 是"一旦压坏就整站排版崩"的高风险操作，所以用例的重点不是
 * "能压掉多少字节"，而是**哪些东西绝对不能被碰**：
 * `<pre>` / `<textarea>` / `<script>` / `<style>` / `<svg>` 里的内容、
 * 条件注释、以及压缩比异常时的安全阀。
 *
 * @package AT8\SiteAccelerator\Tests
 */

namespace AT8\SiteAccelerator\Tests;

use AT8\SiteAccelerator\Optimization\HtmlMinifier;

/**
 * Class HtmlMinifierTest
 */
final class HtmlMinifierTest extends TestCase {

	/**
	 * 造一个超过 1024 字节阈值的 HTML 片段。
	 *
	 * @param string $body 主体内容。
	 * @return string
	 */
	private function pad( $body ) {
		// 填充物刻意用多个块级标签 + 换行，模拟真实页面的空白形态。
		$filler = str_repeat( "<div class=\"filler\">\n\t<p>占位内容</p>\n</div>\n", 30 );

		return "<!DOCTYPE html>\n<html>\n<head></head>\n<body>\n" . $body . $filler . "</body>\n</html>";
	}

	/**
	 * 短于阈值的页面原样返回（压缩收益为负）。
	 *
	 * @return void
	 */
	public function test_short_html_is_returned_untouched() {
		$minifier = new HtmlMinifier( $this->make_settings() );
		$html     = "<p>短内容</p>\n\n   <span>x</span>";

		$this->assertLessThan( 1024, strlen( $html ) );
		$this->assertSame( $html, $minifier->minify( $html ) );
	}

	/**
	 * 非字符串输入原样返回，不抛错。
	 *
	 * @return void
	 */
	public function test_non_string_is_returned_untouched() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$this->assertSame( '', $minifier->minify( '' ) );
		$this->assertNull( $minifier->minify( null ) );
	}

	/**
	 * 普通注释被删除，条件注释与指纹注释被保留。
	 *
	 * @return void
	 */
	public function test_strip_comments_keeps_important_ones() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$html = $this->pad(
			"<!-- 普通注释，应该被删 -->"
			. "<!--[if IE]><p>ie</p><![endif]-->"
			. "<!-- AT8 Site Accelerator 缓存指纹 -->"
			. "<!--! 保留型注释 -->"
			. '<p>正文</p>'
		);

		$out = $minifier->minify( $html );

		$this->assertStringNotContainsString( '普通注释', $out, '普通注释应被删除' );
		$this->assertStringContainsString( '[if IE]', $out, '条件注释必须保留' );
		$this->assertStringContainsString( 'AT8 Site Accelerator', $out, '指纹注释必须保留' );
		$this->assertStringContainsString( '保留型注释', $out, '`<!--!` 开头的注释必须保留' );
	}

	/**
	 * `<pre>` / `<textarea>` 内的空白必须原样保留。
	 *
	 * 这是压缩最容易造成可见事故的地方：代码块与用户输入的换行被吃掉后
	 * 无法恢复，而且只有特定页面才看得出来。
	 *
	 * @return void
	 */
	public function test_protected_blocks_keep_their_whitespace() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$pre      = "<pre>line1\n    line2\n\t\tline3</pre>";
		$textarea = "<textarea>  a\n   b  </textarea>";

		$out = $minifier->minify( $this->pad( $pre . $textarea ) );

		$this->assertStringContainsString( $pre, $out, '<pre> 内容必须逐字节保留' );
		$this->assertStringContainsString( $textarea, $out, '<textarea> 内容必须逐字节保留' );
	}

	/**
	 * `<script>` 与 `<style>` 内的空白必须原样保留。
	 *
	 * 删掉 JS 里的换行会改变自动分号插入（ASI）语义，属于"静默改坏功能"。
	 *
	 * @return void
	 */
	public function test_script_and_style_keep_their_whitespace() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$script = "<script>\nvar a = 1\nvar b = 2\nif (a) { b = 3 }\n</script>";
		$style  = "<style>\n.cls {\n    color: red;\n}\n</style>";

		$out = $minifier->minify( $this->pad( $script . $style ) );

		$this->assertStringContainsString( $script, $out, '<script> 内容必须逐字节保留' );
		$this->assertStringContainsString( $style, $out, '<style> 内容必须逐字节保留' );
	}

	/**
	 * `<svg>` 内容保留（内联图标里的空白与路径数据很敏感）。
	 *
	 * @return void
	 */
	public function test_svg_is_protected() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$svg = "<svg viewBox=\"0 0 24 24\">\n  <path d=\"M0 0 L10 10\" />\n</svg>";

		$out = $minifier->minify( $this->pad( $svg ) );

		$this->assertStringContainsString( $svg, $out );
	}

	/**
	 * 标签之间的空白被折叠成单个空格。
	 *
	 * 刻意不折成空串：`</span> <span>` 之间的空格是可见的，删掉会让
	 * "词语 词语" 粘成 "词语词语"。
	 *
	 * @return void
	 */
	public function test_whitespace_between_tags_collapses_to_single_space() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$out = $minifier->minify( $this->pad( "<span>a</span>\n\n\n     <span>b</span>" ) );

		$this->assertStringContainsString( '</span> <span>', $out );
		$this->assertStringNotContainsString( "\n\n\n", $out );
	}

	/**
	 * 压缩成功后打上"已处理"标记，避免二次压缩。
	 *
	 * @return void
	 */
	public function test_marks_done_after_success() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$this->assertEmpty( $GLOBALS['at8sa_minify_done'] ?? null );

		$minifier->minify( $this->pad( '<p>正文</p>' ) );

		$this->assertTrue( $GLOBALS['at8sa_minify_done'] );
	}

	/**
	 * 已处理过的内容再次进入时必须原样返回（幂等）。
	 *
	 * @return void
	 */
	public function test_second_pass_is_a_noop() {
		$minifier = new HtmlMinifier( $this->make_settings() );
		$html     = $this->pad( "<p>正文</p>\n\n\n<!-- 注释 -->" );

		$first = $minifier->minify( $html );
		$again = $minifier->minify( $first );

		$this->assertSame( $first, $again, '第二次调用必须原样返回，不能继续压' );
	}

	/**
	 * 安全阀：压缩后体积低于原始 40% 时，放弃压缩并返回原文。
	 *
	 * 这条守的是"正则吃掉了正常内容"这类灾难——宁可少压一次，
	 * 也不能把页面压成半截。
	 *
	 * @return void
	 */
	public function test_safety_valve_returns_original_on_suspicious_shrink() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		// 构造一个"绝大部分是注释"的页面：删注释后只剩几十字节，
		// 压缩比远低于 40%，安全阀必须拦下。
		$html = '<!DOCTYPE html><html><body><!--' . str_repeat( 'x', 4000 ) . '--><p>正文</p></body></html>';

		$this->assertGreaterThan( 1024, strlen( $html ) );
		$this->assertSame( $html, $minifier->minify( $html ), '安全阀未生效，页面会被压坏' );
	}

	/**
	 * 正常页面确实被压小了，且正文内容仍在。
	 *
	 * @return void
	 */
	public function test_real_page_is_actually_minified() {
		$minifier = new HtmlMinifier( $this->make_settings() );

		$html = $this->pad( '<p>这是正文，压缩后必须还在。</p>' );
		$out  = $minifier->minify( $html );

		$this->assertLessThan( strlen( $html ), strlen( $out ), '压缩后应更短' );
		$this->assertStringContainsString( '这是正文，压缩后必须还在。', $out );
	}

	/**
	 * `html_minify_inline` 关闭时（默认），内联 `<style>` 的缩进不被折叠。
	 *
	 * 这是"死开关"的回归测试：该开关曾经存在但代码从未读取它，
	 * 所以必须证明"关"和"开"确实产生不同结果。
	 *
	 * @return void
	 */
	public function test_inline_css_folding_is_controlled_by_setting() {
		$style = "<style>\n.a {\n    color: red;\n}\n</style>";
		$html  = $this->pad( $style . '<p>正文</p>' );

		$off = new HtmlMinifier( $this->make_settings( array( 'html_minify_inline' => 0 ) ) );
		$this->assertStringContainsString( $style, $off->minify( $html ), '关闭时 <style> 原样保留' );

		unset( $GLOBALS['at8sa_minify_done'] );

		$on = new HtmlMinifier( $this->make_settings( array( 'html_minify_inline' => 1 ) ) );
		$out = $on->minify( $html );

		$this->assertStringNotContainsString( "\n    color: red;", $out, '打开时内联 CSS 的缩进应被折叠' );
		$this->assertStringContainsString( 'color: red;', $out, '折叠后规则本身仍在' );
	}
}

<?php
/**
 * LazyLoad 单元测试。
 *
 * 懒加载的风险是"把不该懒加载的图也懒了"——首屏大图被懒加载会直接
 * 拖垮 LCP，而 `data:` 占位图再懒一次则会让图片永久不显示。
 * 所以用例重点是**跳过规则**是否齐全。
 *
 * @package AT8SA\Tests
 */

namespace AT8SA\Tests;

use AT8SA\Optimization\LazyLoad;

/**
 * Class LazyLoadTest
 */
final class LazyLoadTest extends TestCase {

	/**
	 * 空输入原样返回。
	 *
	 * @return void
	 */
	public function test_empty_input_is_returned_untouched() {
		$lazy = new LazyLoad( $this->make_settings() );

		$this->assertSame( '', $lazy->process( '' ) );
		$this->assertNull( $lazy->process( null ) );
	}

	/**
	 * 默认开启"跳过首屏"时，前两张图标记为 eager，其余为 lazy。
	 *
	 * 为什么要显式写 eager 而不是"什么都不做"：很多主题/浏览器默认就是
	 * `loading="auto"`，显式 eager 才能把 LCP 候选图的优先级提上去。
	 *
	 * @return void
	 */
	public function test_first_images_are_eager_and_rest_are_lazy() {
		$lazy = new LazyLoad( $this->make_settings( array( 'lazyload_skip_first' => 1 ) ) );
		$out  = $lazy->process( '<img src="1.jpg"><img src="2.jpg"><img src="3.jpg">' );

		$this->assertSame( 2, substr_count( $out, 'loading="eager"' ), '前两张应为 eager' );
		$this->assertSame( 1, substr_count( $out, 'loading="lazy"' ), '第三张应为 lazy' );
		$this->assertSame( 1, substr_count( $out, 'decoding="async"' ), '只有懒加载图才需要 decoding=async' );
		$this->assertStringContainsString( 'src="1.jpg"', $out, '原 src 不能被改掉' );
		$this->assertStringContainsString( 'src="3.jpg"', $out, '原 src 不能被改掉' );
	}

	/**
	 * 关闭"跳过首屏"时，全部图片都懒加载。
	 *
	 * @return void
	 */
	public function test_all_images_lazy_when_skip_first_disabled() {
		$lazy = new LazyLoad( $this->make_settings( array( 'lazyload_skip_first' => 0 ) ) );
		$out  = $lazy->process( '<img src="1.jpg"><img src="2.jpg">' );

		$this->assertSame( 2, substr_count( $out, 'loading="lazy"' ) );
		$this->assertSame( 0, substr_count( $out, 'loading="eager"' ) );
	}

	/**
	 * 已有 `loading` 属性的标签一律不动。
	 *
	 * 尊重原作者/其它插件的显式声明。这一条如果失效，会和 WP Rocket、
	 * Perfmatters 这类插件互相打架，出现"同一张图一会儿 lazy 一会儿 eager"。
	 *
	 * @return void
	 */
	public function test_tags_with_existing_loading_are_untouched() {
		$lazy = new LazyLoad( $this->make_settings() );
		$tag  = '<img loading="eager" src="hero.jpg">';

		$this->assertSame( $tag, $lazy->process( $tag ) );
	}

	/**
	 * `data-no-lazy` / `data-skip-lazy` 明确要求不处理。
	 *
	 * @return void
	 */
	public function test_explicit_opt_out_attributes_are_respected() {
		$lazy = new LazyLoad( $this->make_settings() );

		$this->assertSame(
			'<img data-no-lazy src="a.jpg">',
			$lazy->process( '<img data-no-lazy src="a.jpg">' )
		);
		$this->assertSame(
			'<img data-skip-lazy src="b.jpg">',
			$lazy->process( '<img data-skip-lazy src="b.jpg">' )
		);
	}

	/**
	 * 已经是 `data:` 占位图的标签不动。
	 *
	 * 这类标签通常来自另一个懒加载插件：再处理一次会让 `data-src` 永远
	 * 不被替换，图片彻底不显示。
	 *
	 * @return void
	 */
	public function test_data_uri_placeholders_are_untouched() {
		$lazy = new LazyLoad( $this->make_settings() );
		$tag  = '<img src="data:image/gif;base64,R0lGOD" data-src="real.jpg">';

		$this->assertSame( $tag, $lazy->process( $tag ) );
	}

	/**
	 * 内置排除规则生效（表情符号 / Gravatar / 主题图标）。
	 *
	 * @param string $tag 图片标签。
	 * @return void
	 * @dataProvider provide_excluded_tags
	 */
	public function test_builtin_exclusions_are_skipped( $tag ) {
		$lazy = new LazyLoad( $this->make_settings( array( 'lazyload_skip_first' => 0 ) ) );

		$this->assertSame( $tag, $lazy->process( $tag ), "该标签不应被处理：{$tag}" );
	}

	/**
	 * 内置排除用例。
	 *
	 * @return array
	 */
	public static function provide_excluded_tags() {
		return array(
			'表情符号'    => array( '<img src="/wp-includes/images/smilies/icon_smile.gif">' ),
			'编辑器图标'  => array( '<img src="/wp-includes/images/wpicons.png">' ),
			'空白占位图'  => array( '<img src="/wp-includes/images/blank.gif">' ),
			'Gravatar'    => array( '<img src="https://secure.gravatar.com/avatar/abc">' ),
			'Elementor'   => array( '<img src="/wp-content/plugins/elementor/assets/images/x.png">' ),
		);
	}

	/**
	 * 用户自定义排除规则生效（多行 / 逗号分隔都支持）。
	 *
	 * @return void
	 */
	public function test_custom_exclusions_are_applied() {
		$lazy = new LazyLoad(
			$this->make_settings(
				array(
					'lazyload_skip_first' => 0,
					'lazyload_exclude'    => "/hero/\ncdn.example.test",
				)
			)
		);

		$this->assertSame(
			'<img src="/hero/big.jpg">',
			$lazy->process( '<img src="/hero/big.jpg">' )
		);
		$this->assertSame(
			'<img src="https://cdn.example.test/a.jpg">',
			$lazy->process( '<img src="https://cdn.example.test/a.jpg">' )
		);
	}

	/**
	 * iframe 懒加载受开关控制。
	 *
	 * @return void
	 */
	public function test_iframe_lazyload_is_controlled_by_setting() {
		$tag = '<iframe src="https://example.test/embed"></iframe>';

		$on = new LazyLoad( $this->make_settings( array( 'lazyload_iframes' => 1 ) ) );
		$this->assertStringContainsString( 'loading="lazy"', $on->process( $tag ) );

		$off = new LazyLoad( $this->make_settings( array( 'lazyload_iframes' => 0 ) ) );
		$this->assertSame( $tag, $off->process( $tag ), '关闭时 iframe 不动' );
	}

	/**
	 * 未命中任何规则的图片确实被改了（对照组）。
	 *
	 * @return void
	 */
	public function test_ordinary_image_gets_processed() {
		$lazy = new LazyLoad( $this->make_settings( array( 'lazyload_skip_first' => 0 ) ) );
		$out  = $lazy->process( '<img src="/uploads/photo.jpg">' );

		$this->assertStringContainsString( 'loading="lazy"', $out );
		$this->assertStringContainsString( '/uploads/photo.jpg', $out, '原 src 不能被改掉' );
	}

	/**
	 * 非 img/iframe 标签不受影响。
	 *
	 * @return void
	 */
	public function test_non_media_tags_are_untouched() {
		$lazy = new LazyLoad( $this->make_settings() );
		$html = '<picture><source srcset="a.webp"><div class="img-like"></div></picture>';

		$this->assertSame( $html, $lazy->process( $html ) );
	}
}

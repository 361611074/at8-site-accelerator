<?php
/**
 * PHP 8.0+ 的 GD 图像句柄类（静态分析用桩）。
 *
 * 为什么要这个文件：
 *
 * 本插件的分析基线是 PHP 7.4（`phpstan.neon.dist` 里 `phpVersion: 70400`），
 * 而 7.4 的 GD 函数返回的是 `resource`；从 PHP 8.0 起才改成 `GdImage` 对象。
 * 于是 `Webp::load()` 上那句"同时描述两种运行时"的
 * `@return resource|\GdImage|false` 在 7.4 基线下会因为 `GdImage` 未声明而报
 * `class.notFound`。
 *
 * 真机上插件是跑在 PHP 8.3 上的（测试站就是），所以把 docblock 降级成
 * `resource|false` 反而是错的。这里补一个类桩，让 docblock 保持准确。
 *
 * 注意：这不是产品代码，不参与打包（见 .gitattributes 的 export-ignore）。
 *
 * @package AT8\SiteAccelerator\Tests\PHPStan
 */

// 全局命名空间：GD 的类就在根命名空间下。
class GdImage {
}

=== AT8 Site Accelerator ===
Contributors: at8fun
Tags: cache, page cache, redis, lazy load, webp
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

轻量级整页缓存 + 精准失效 + 智能预加载 + 浏览器缓存 + HTML 压缩 + 图片懒加载 + WebP 自动转换 + 数据库瘦身，多合一站点加速。

== Description ==

AT8 Site Accelerator 把"让 WordPress 变快"这件事拆成八个彼此独立、可单独关闭的模块，
每一处都可能单独出问题，所以每一处都单独做了取舍说明。

= 为什么是"精准失效"而不是"整站清空" =

大多数缓存插件在"保存文章"时的做法是把整站缓存删掉。这在流量稍大的站点上意味着：
一次编辑 = 全站缓存清零 = 下一批访客全部打到数据库。**缓存插件反而成了压力源。**

本插件在保存文章时只失效真正受影响的那几个 URL：文章本身、首页、相关归档、
所属分类/标签/作者归档、以及它们的翻页。其余页面的缓存原封不动。

= 缓存命中路径 =

启用"高级缓存"后插件会写入 `wp-content/advanced-cache.php`。
命中时在 WordPress 完成初始化**之前**直接输出缓存并结束请求——
不查数据库、不加载主题、不加载其他插件。

= 后端选择 =

* **Redis**：使用内置的纯 PHP RESP 客户端，不需要 `phpredis` 扩展。多站点共用一台 Redis 时按站点盐隔离，**从不使用 FLUSHDB**（那会连别人的站点一起清掉）。
* **磁盘**：目录布局即 URL 结构（`cache/at8-site-accelerator/<域名>/<路径>/index.html`），因此"失效一个 URL"退化成"删一个目录"。
* **自动**：优先 Redis，不可达时自动降级磁盘，并对探测结果做 1 小时缓存，避免每个请求都吃一次连接超时。

= 模块一览 =

**① 页面缓存** —— 整页缓存、TTL、移动端独立变体、登录用户是否缓存、URL/Cookie/查询参数绕过规则。

**② 失效策略** —— 精准失效（默认）或整站清空；保存文章时是否同时清首页。

**③ 智能预加载** —— 访客鼠标悬停或触摸链接时预取目标页面，让"点击"变成"瞬开"。尊重 `Save-Data` 与慢速网络，页面不可见时自动停止。

**④ 浏览器缓存** —— 为静态资源发送长缓存响应头，并提供 nginx / Apache 规则片段供你复制到服务器配置（插件不会擅自改你的 `.htaccess`）。

**⑤ HTML 压缩** —— 移除注释与多余空白。`pre` / `textarea` / `script` / `style` / `svg` 内容原样保留，IE 条件注释保留。带安全阀：压缩后体积若低于原始的 40%，判定为异常并放弃压缩。

**⑥ 图片懒加载** —— 使用浏览器原生 `loading="lazy"`，不引入任何 JavaScript。首屏前两张图会被显式标记为 `loading="eager"` 以避免拖慢 LCP。

**⑦ 前端资源精简** —— emoji 脚本、embeds、generator 标签、jQuery Migrate、Dashicons、区块编辑器样式、静态资源查询串、Heartbeat 频率。

**⑧ 图片** —— 上传 JPEG/PNG 时自动生成 WebP 副本（保留 PNG 透明通道）。若转换后体积反而变大则丢弃副本。

**⑨ 数据库瘦身** —— 修订版本、自动草稿、回收站文章、垃圾/回收站评论、过期瞬态、表优化。**默认全部关闭**：清理是破坏性操作，必须由你显式勾选。执行前可先预览每项的条数。

**⑩ 后台精简** —— 站点健康、活动与新闻、版本检查、超大缩略图。

**⑪ 诊断与安全** —— 环境体检报告（不评分，只给事实）、冲突检测、结构化日志（默认关闭，写入前自动脱敏长十六进制串与 Token）、安全模式（一键全局停缓存）。

= 兼容性 =

* **Elementor** —— 三层防护：写入前检查引用的 `post-*.css` 是否真实存在、缓存版本盐、以及响应结束后异步重建样式，避免访客命中"样式 404"的陈旧页面。
* **WooCommerce** —— 购物车、结算、我的账户、订单相关端点一律不缓存；库存变化时精准失效对应商品页。
* **其他缓存插件** —— 会主动检测并提示冲突（WP Rocket、LiteSpeed Cache、W3 Total Cache、WP Super Cache、Autoptimize、FlyingPress、Perfmatters、Cache Enabler、Swift Performance、Nginx Helper、Hummingbird）。

= 不会做的事 =

* 不会自动修改 WordPress 核心文件。
* 不会擅自覆盖你的 `.htaccess`（只提供规则片段，由你决定是否写入）。
* 不会在停用时删除你的设置或缓存。
* 不会在卸载时删除你的数据（除非你显式关闭"卸载时保留数据"）。
* 不使用 `FLUSHDB`，不使用 `eval`，不调用任何 shell 命令。

== Installation ==

1. 上传 `at8-site-accelerator` 目录到 `/wp-content/plugins/`。
2. 在"插件"页面启用。
3. 打开左侧菜单「AT8 加速」，按提示完成 WP_CACHE 与 drop-in 安装。

= Frequently Asked Questions =

= 缓存没有生效？ =

到「诊断」标签页看第一项。最常见的三种原因：`wp-content` 目录不可写、`wp-config.php` 中的 `WP_CACHE` 未开启、或者同服务器上还有另一个缓存插件的 `advanced-cache.php` 在抢同一个 drop-in 位置。

= 为什么保存文章后缓存没有全清？ =

这是设计如此，见上文"为什么是精准失效"。如果你确实需要整站清空，到「失效」标签页把策略改成"整站清空"。

= 用了 Redis 会不会把别的站点数据清掉？ =

不会。所有键都带站点盐前缀（基于本站 `COOKIEHASH` 与缓存版本号），失效时只删自己前缀下的键，代码里不存在 `FLUSHDB`。

= 支持多站点吗？ =

支持。每个站点有独立的缓存目录与独立的 Redis 键前缀。

== Changelog ==

= 3.0.1 =
* 修复：部分站点的 `wp-config.php` 因随机密钥（salt）字符串里含有 `{` 或 `}`，导致「一键启用 WP_CACHE」被误判为写入失败并自动回滚，高级缓存始终无法生效。现在改为按 PHP 词法分析统计真实代码中的括号，不再把字符串内容算进来。
* 修复：`wp-config.php` 写入校验增加「可逆性」检查——写入后的文件去掉插件那一行必须能精确还原原文，防止误改站点配置。
* 修复：重复点击「启用 WP_CACHE」不再误报「无法自动改写」。
* 修复：单元测试在装有 Redis 的机器上会假失败（失效器断言的是磁盘文件，但自动模式会选 Redis 后端）。现已固定后端，测试结果不再依赖宿主机环境。

= 3.0.0 =
* 全新重构：模块化架构（Cache / Purge / Optimization / Compatibility / Diagnostics / Admin / REST 七层）。
* 新增：Redis 与磁盘双后端，自动降级。
* 新增：按 URL 精准失效，取代整站清空。
* 新增：REST API（`at8sa/v1`）。
* 新增：诊断报告、冲突检测、结构化日志、安全模式。
* 新增：原生懒加载、WebP 自动转换、数据库瘦身。
* 兼容 2.x 的全部设置项，升级后自动迁移，旧设置不会被删除。

完整的技术变更清单（含每一个修复项的原因）见仓库根目录的 `CHANGELOG.md`。

== Upgrade Notice ==

= 3.0.1 =
建议所有 3.0.0 用户升级。若你在 3.0.0 上点过「启用 WP_CACHE」却提示需要手动添加，本次升级后重试即可自动完成。升级不会改动你的设置与缓存目录。

= 3.0.0 =
从 2.x 升级时设置会自动迁移，无需手工操作。升级后建议到「诊断」标签页确认一次环境状态。

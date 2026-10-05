# 兼容性说明

> 对应开发计划书 §66（Elementor）、§67（WooCommerce）、§68（缓存插件冲突）。

## 一、运行环境

| 项目 | 最低 | 已测 | 说明 |
| --- | --- | --- | --- |
| PHP | 7.4 | 7.3（更严格）、CI 7.4–8.3 | 运行时二次校验，不满足则拒绝加载 |
| WordPress | 5.8 | CI 语法级 | 运行时二次校验 `$wp_version` |
| MySQL / MariaDB | 5.6 / 10.1 | — | 仅用于 `SHOW TABLE STATUS` 与清理 SQL |
| 扩展 | 无强制依赖 | — | Redis 用纯 PHP 客户端；WebP 需 GD 或 Imagick（缺失则静默跳过） |
| 服务器 | nginx / Apache / LiteSpeed | — | 规则片段分别提供 |

---

## 二、Elementor

### 问题

Elementor 会把每篇文章的 CSS 单独生成为
`wp-content/uploads/elementor/css/post-<id>.css`。
如果在样式文件生成**之前**就把页面缓存下来，访客会命中一个引用 404 样式表的陈旧页面——
表现为"页面布局全乱"，且清缓存后立刻恢复（所以极难定位）。

### 三层防护

**第一层 — 写入护栏**（`CacheEngine::has_missing_elementor_css()`）

落盘前扫描 HTML 中所有 `<link rel="stylesheet" href="...elementor/css/post-N.css">`：

- 只检查**本站 uploads 目录下**的（CDN / 外链一律放行）；
- 若引用的文件**此刻不存在**，放弃本次缓存并记录 warning。

**第二层 — 缓存版本盐**

Elementor 保存页面时会触发 `elementor/editor/after_save` 等钩子，
插件递增缓存版本盐，使旧键立即不可达。

**第三层 — 响应结束后异步重建**

`register_shutdown_function` + `fastcgi_finish_request()`（PHP-FPM 下）：
先把响应发给用户，再在后台重建该篇的 CSS。**只重建单篇**，
用 try/catch 包裹，绝不让样式重建失败影响页面响应。

> 为什么不用 `wp_schedule_single_event`：那会引入一次后台请求，
> 在低配主机上可能与前台请求争抢 PHP-FPM 进程池，反而造成 502。

### 编辑器安全

Elementor 编辑器保存请求（`is_editor_save_request()`）与
`elementor-preview` 查询参数一律绕过缓存。

---

## 三、WooCommerce

### 永不缓存的页面

通过定义 `DONOTCACHEPAGE` / `DONOTCACHEOBJECT` 标记，并在 `should_cache_response()` 中复查：

- 购物车（`is_cart()`）
- 结算（`is_checkout()`）
- 我的账户（`is_account_page()`）
- 任意 WooCommerce 端点（`is_wc_endpoint_url()`）

同时 `RequestGuard` 的路径黑名单包含 `/cart`、`/checkout`、`/my-account`、`/add-to-cart`，
Cookie 黑名单包含 `woocommerce_items_in_cart`、`woocommerce_cart_hash`、
`wp_woocommerce_session_*`（`*` 为前缀匹配，真实 Cookie 名带 `<COOKIEHASH>` 后缀）
——**drop-in 阶段就能拦住**，不必等到 WordPress 加载完。

### 精准失效

| 事件 | 失效范围 |
| --- | --- |
| 库存变化（`woocommerce_product_set_stock`、`woocommerce_variation_set_stock`） | 该商品页 + 所属分类 |
| 订单状态变化 | 受影响商品页 + 商店页 |
| 商品保存 | 该商品页 + 商店页 + 分类归档 |

### 已知限制

- 需要"按用户显示不同价格"的插件（B2B 定价、会员价）与整页缓存天然冲突。
  这类站点应关闭 `page_cache`，只使用其他优化模块。
- 不使用 WooCommerce 的站点，这些钩子根本不会被触发，零开销。

---

## 四、第三方缓存插件

### 冲突检测（`CachePluginDetector`）

覆盖 11 款：WP Rocket、LiteSpeed Cache、W3 Total Cache、WP Super Cache、
Autoptimize、FlyingPress、Perfmatters、Cache Enabler、Swift Performance、
Nginx Helper、Hummingbird。

检测三个维度：

1. 是否在 `active_plugins` 中；
2. 是否在网络范围内激活（多站点）；
3. `wp-content/advanced-cache.php` 是否已被**别的插件**占用。

结果缓存 1 小时（transient）。检测到高风险冲突时：

- 后台显示醒目提示，说明具体冲突项；
- 建议开启**安全模式**（一键停缓存，但其他优化模块继续工作）；
- 但**不自动停用任何插件**——那超出插件该有的权限边界。

### 为什么不做"自动共存"

同时运行两个整页缓存插件在技术上是无解的：两者都要写同一个
`wp-content/advanced-cache.php`，后写的覆盖先写的。任何声称"自动共存"的方案
本质上都是"偷偷停用对方"。本插件的选择是把事实告诉用户，让用户决定。

---

## 五、其他常见插件

| 插件类型 | 状态 | 说明 |
| --- | --- | --- |
| 图片优化（Imagify、ShortPixel、EWWW） | 兼容 | WebP 模块可单独关闭，避免重复转换 |
| 前端优化（Autoptimize、Perfmatters） | 兼容 | 与 HTML 压缩、前端精简有功能重叠，建议只开一侧 |
| 安全插件（Wordfence、iThemes） | 兼容 | 注意安全插件的"登录页"路径是否在绕过名单内 |
| 多语言（WPML、Polylang） | 兼容 | 语言切换通常走 URL 前缀或查询串，缓存键天然区分；Cookie 方式需手动加入 `bypass_cookies` |
| 会员/订阅（MemberPress 等） | 需配置 | 按用户展示不同内容时必须关闭 `page_cache`，或把会话 Cookie 加入 `bypass_cookies` |
| 表单（Contact Form 7 等） | 兼容 | nonce 随页面缓存固化会导致提交失败，建议把表单页加入 `exclude_urls` |
| 页面构建器（Beaver、Bricks） | 兼容 | 无 Elementor 那类外置 CSS 就绪问题 |
| 对象缓存（Redis Object Cache） | 兼容 | 与页面缓存是不同层，可同时使用 |
| CDN（Cloudflare 等） | 兼容 | 注意 CDN 自身的缓存规则优先于本插件 |

### 表单与 nonce 的注意事项

WordPress 的 nonce 有 12–24 小时有效期，且与用户会话绑定。
**缓存页面里的 nonce 会被固化**，导致未登录访客提交表单时校验失败。

处理建议：

1. 表单页加入 `exclude_urls`（最稳）；
2. 或使用不依赖 nonce 的表单插件；
3. 或把 `cache_ttl` 设得短于 nonce 有效期。

本插件不自动做 nonce 替换——那需要改写 HTML 里的 JS 变量，
出错概率高于收益。

---

## 六、多站点（Multisite）

- 每个站点有独立的缓存目录（`cache/at8-site-accelerator/<host>/…`）；
- Redis 键前缀基于各站自有命名空间（`at8sa_blog<子站ID>_<home_url 派生值>`），
  **同服务器多站点共用一台 Redis 不会互相覆盖**；
- 网络激活时，冲突检测会检查全网络范围的插件列表；
- 缓存清理只影响当前站点。

未做：跨站点统一清理（需要网络管理界面，属于 Pro 范围）。

---

## 七、主机环境

| 环境 | 状态 | 说明 |
| --- | --- | --- |
| Apache + mod_php | 完整支持 | `.htaccess` 规则片段可用 |
| nginx + PHP-FPM | 完整支持 | 提供 `fastcgi_finish_request()` 加速后台任务 |
| LiteSpeed | 完整支持 | 与 LiteSpeed Cache 插件冲突时需二选一 |
| Windows / IIS | 支持 | 路径判定已修复跨平台问题；IIS 需自行配置 URL 重写 |
| 共享主机 | 支持 | 无扩展依赖、无 Composer 依赖 |
| Docker / 容器 | 支持 | 缓存目录需挂载持久卷 |

### 需要用户注意的服务器配置

1. `wp-content` 目录必须可写（用于安装 drop-in）；
2. `wp-config.php` 可写时才能自动开启 `WP_CACHE`（不可写则给出手动步骤）；
3. 缓存目录建议加 Web 服务器规则禁止目录列表（插件已放 `index.php` 守卫）；
4. nginx 用户建议配置 `try_files` 直接返回缓存文件，绕过 PHP（规则片段在「工具」标签页）。

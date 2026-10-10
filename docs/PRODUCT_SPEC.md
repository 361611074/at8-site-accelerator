# 产品规格说明（Free 版 3.0.0）

## 一、产品定位

**一句话**：让 WordPress 站点的缓存策略从"整站清空"升级为"按 URL 精准失效"，
同时把常见的性能优化项做成可独立开关的模块。

**目标用户**：

- 中小型内容站 / 企业官网 / WooCommerce 小店的站长；
- 已经用过某款缓存插件，被"编辑一篇文章就要重建整站缓存"折磨过；
- 用的是共享主机或低配 VPS，没有独立运维。

**不服务的场景**：

- 需要 CDN 边缘缓存、图片 CDN 自动改写（那是 Pro / 第三方 CDN 的领域）；
- 需要 Critical CSS 自动生成（Pro）；
- 需要多服务器缓存同步（Free 版单机语义）。

**核心差异点**（相对同类免费插件）：

| 差异点 | 本插件 | 常见做法 |
| --- | --- | --- |
| 失效粒度 | 按 URL 精准失效 | 整站清空 |
| Redis 依赖 | 纯 PHP 客户端，无需扩展 | 需要 `phpredis` |
| 多站点共用 Redis | 站点盐前缀隔离，从不 `FLUSHDB` | 部分插件会 `FLUSHDB` |
| 命中路径 | drop-in 早期 `exit` | 部分插件在 `template_redirect` 才生效 |
| 诊断 | 给事实，不评分 | 给一个"95 分"诱导改配置 |
| 卸载 | 默认保留数据 | 直接删表删目录 |

---

## 二、功能清单

### ① 页面缓存

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `page_cache` | 开 | 整页缓存总开关 |
| `advanced_cache` | 开 | 安装 `advanced-cache.php` drop-in（命中路径零 WordPress 开销） |
| `cache_backend` | `auto` | `auto` / `disk` / `redis` |
| `cache_ttl` | 3600 | 60–2592000 秒 |
| `cache_mobile` | 开 | 移动端独立缓存变体（按 UA 判定） |
| `exclude_urls` | 空 | 额外绕过的 URL 片段（每行一个） |
| `bypass_cookies` | 空 | 额外绕过的 Cookie 前缀（支持 `prefix*`） |
| `ignore_query` | 空 | 额外忽略的查询参数（支持 `prefix_*` 与 `*`） |

内置绕过规则：`/wp-admin`、`/wp-login.php`、`/wp-cron.php`、`/wp-json`、
`/xmlrpc.php`、`/wp-signup.php`、`/wp-activate.php`、`/admin-ajax.php`、
`/wp-comments-post.php`、`preview=true`、`elementor-preview`、`customize.php`、
`/feed`、`/cart`、`/checkout`、`/my-account`、`/add-to-cart`、`?s=`、`&s=`。

内置绕过 Cookie（`*` 结尾 = 前缀匹配，否则要求 Cookie 名完全一致）：
`wp-postpass_*`、`comment_author_*`、`wp_woocommerce_session_*`、
`woocommerce_items_in_cart`、`woocommerce_cart_hash`、`edd_items_in_cart`。

登录态 Cookie（`wordpress_logged_in_*` / `wordpress_sec_*`）**刻意不在此列表内**，
统一由 `has_auth_cookie()` 单一归属管理，命中即无条件绕过缓存。

> **`cache_logged_in` 已于 3.0.5 彻底移除**（设置项、后台 UI、默认值、布尔白名单
> 全部删除，仅 `Config` 落盘时仍硬钉为 `0` 以压制老站数据库里的历史残留值）。
> 原因：缓存键不含用户维度，登录用户共享缓存必然越权串号。
> 现在的规则是**无条件**的 —— `is_user_logged_in()` 为真即绕过，没有任何开关可以关掉。

内置忽略查询参数：`utm_*`、`fbclid`、`gclid`、`gclsrc`、`dclid`、`msclkid`、
`mc_*`、`igshid`、`twclid`、`yclid`、`_ga`、`_gl`、`wbraid`、`gbraid`、
`mc_cid`、`mc_eid`、`ref_src`。

### ② 失效策略

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `purge_scope` | `related` | `related` 精准失效 / `all` 整站清空 |
| `purge_home_on_save` | 开 | 保存文章时是否同时失效首页 |

精准失效覆盖：文章本身、首页、文章类型归档、分类/标签/自定义分类归档、
作者归档、以上每项的翻页。

单次失效 URL 上限 300，超出自动升级为整站清空。

### ③ 智能预加载（前端链接预取）

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `preload_enable` | 开 | 总开关 |
| `hover_delay` | 50 | 悬停多久后预取（毫秒） |
| `touch_delay` | 100 | 触摸多久后预取（毫秒） |
| `preload_viewport` | 开 | 视口内链接预热 |
| `preload_strategy` | prefetch + preconnect + dns-prefetch | 多选 |
| `max_preloads` | 20 | 单页预取上限 |
| `max_per_domain` | 10 | 单域名预取上限 |
| `preload_cooldown` | 300 | 同一链接冷却秒数 |
| `dns_prefetch` | 开 | 输出本站 DNS 预取与预连接 |
| `preconnect_hosts` | fonts.googleapis.com / fonts.gstatic.com | 第三方预连接域名 |
| `preload_debug` | 关 | 浏览器控制台输出调试日志 |

安全阀：尊重 `Save-Data` 与 `slow-2g`；页面不可见时断开 IntersectionObserver；
`prerender` 默认不启用（流量与 CPU 开销大）。

> **命名澄清**：本模块是**前端链接预取**（访客悬停时预取下一页）。
> 与"缓存预热"（服务器主动生成所有页面的缓存）是两件事，后者属于 Pro。

### ④ 浏览器缓存

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `browser_cache` | 开 | 生成静态资源缓存规则（nginx / Apache 片段） |
| `browser_cache_ttl` | 31536000 | 静态资源缓存时长（秒） |

**HTML 公共浏览器缓存已随 3.0.6.3 整体移除**（WordPress.org 二轮审核终裁）：
3.0.6 的"发送时刻资格 Gate"方案被否决——`send_headers` 钩子无法看见主题/插件
之后才添加的 `Set-Cookie`、`Cache-Control: private/no-store`、`Vary: Cookie`
或才定义的 `DONOTCACHEPAGE`。因此 Free 版不再为 HTML 发送任何公共缓存头：

- `browser_cache_html` / `browser_cache_html_ttl` 两个设置键已删除；
  旧数据库行里遗留的值会被 `Settings::all()` 原样带出，但运行时无任何读取方（被忽略）。
- 整页缓存命中（运行时引擎与 drop-in 两条路径）改发
  `Cache-Control: no-cache, must-revalidate, max-age=0`，且**不覆盖**其它代码
  已发送的 Cache-Control（不放宽 no-store / private）。
- HTML 的新鲜度完全由本插件整页缓存机制在服务端控制。

提供 nginx / Apache 规则片段供复制，**不自动覆盖** `.htaccess`；
写入 `.htaccess` 时也不再生成 `.htaccess.at8sa.bak` 明文备份（改为内存回滚）。

危险组合提示：`remove_query_strings` 与长缓存同时开启时会给出警告
（移除查询串后，旧版资源 URL 与新版指向同一文件）。

### ⑤ HTML 压缩

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `html_minify` | 开 | 移除注释与多余空白 |
| `html_minify_inline` | 关 | 是否同时压缩内联 CSS/JS |

安全阀：内容 < 1024 字节跳过；压缩后体积低于原始的 40% 判定为异常并放弃；
`pre` / `textarea` / `script` / `style` / `svg` 内容原样保留；IE 条件注释保留。

### ⑥ 图片懒加载

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `lazyload` | 开 | 总开关 |
| `lazyload_iframes` | 开 | iframe 也懒加载 |
| `lazyload_skip_first` | 开 | 首屏前两张标 `eager` 改善 LCP |
| `lazyload_exclude` | 空 | 排除规则（每行一个） |

使用浏览器原生 `loading="lazy"` + `decoding="async"`，**不引入任何 JavaScript**。
已有 `loading` 属性、`data-no-lazy`、`data-skip-lazy`、`src="data:"` 的图片自动跳过。

### ⑦ 前端资源精简

| 设置项 | 默认 |
| --- | --- |
| `disable_emoji` | 开 |
| `disable_embeds` | 开 |
| `remove_wp_generator` | 开 |
| `disable_jquery_migrate` | 关 |
| `disable_dashicons` | 开 |
| `remove_query_strings` | 开 |
| `heartbeat` | `reduce`（`default` / `reduce` / `disable`） |
| `disable_block_css` | 关 |

> `disable_jquery_migrate` 与 `disable_block_css` 默认关闭：
> 前者可能让依赖 jQuery 1.x 行为的旧插件出问题，后者会让区块编辑器的前端样式失效。

### ⑧ 图片

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `webp_convert` | 开 | 上传 JPEG/PNG 时生成 WebP 副本 |

质量 82；PNG 保留 alpha 通道；转换后体积变大则删除副本（不劣化）；
删除附件时同步删除副本；GD 与 Imagick 都不可用时静默跳过。

### ⑨ 数据库瘦身

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `db_revisions` | 关 | 文章修订版本 |
| `db_auto_drafts` | 关 | 自动草稿 |
| `db_trashed_posts` | 关 | 回收站文章（强制删除） |
| `db_spam_comments` | 关 | 垃圾评论 |
| `db_trashed_comments` | 关 | 回收站评论 |
| `db_transients` | 关 | 过期瞬态 |
| `db_optimize` | 关 | 优化数据表 |
| `db_schedule` | `off` | `off` / `daily` / `weekly` |

**默认全部关闭**：清理是破坏性操作，必须由用户显式勾选。
执行前提供预览，显示每项将删除的条数。单批上限 5000 条。

### ⑩ 后台精简

| 设置项 | 默认 |
| --- | --- |
| `remove_site_health` | 开 |
| `remove_events_news` | 开 |
| `disable_version_checks` | 开 |
| `disable_large_thumbs` | 开 |

### ⑪ 诊断与安全

| 设置项 | 默认 | 说明 |
| --- | --- | --- |
| `log_enabled` | 关 | 结构化日志 |
| `log_level` | `error` | `error` / `warning` / `info` / `debug` |
| `safe_mode` | 关 | 一键全局停缓存 |
| `keep_data_on_uninstall` | 开 | 卸载时保留数据 |

诊断报告 8 组：缓存、对象缓存、PHP、WordPress、服务器、HTTPS、数据库、冲突检测。
**不做评分。**

---

## 三、界面结构

左侧菜单「AT8 加速」（`dashicons-performance`，位置 81），
插件列表页有"设置"快捷链接。

7 个标签页，共用一个 `<form>`（一次保存全部生效，切标签不丢改动）：

| 标签 | 内容 |
| --- | --- |
| 概览 | 后端状态、缓存页数、缓存体积、缓存版本、快捷操作 |
| 缓存 | ①页面缓存 ②失效策略 |
| 预加载 | ③智能预加载 |
| 优化 | ④浏览器缓存 ⑤HTML 压缩 ⑥懒加载 ⑦前端精简 ⑧图片 |
| 数据库 | ⑨数据库瘦身（含预览表格） |
| 兼容 | 冲突检测结果与建议 |
| 工具 | 诊断报告、日志查看、导入/导出设置、nginx/Apache 规则片段、重置 |

管理栏提供"清空缓存"按钮（`<button>` + `fetch(POST)`）。

---

## 四、REST API

命名空间 `at8sa/v1`，全部要求 `manage_options`。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `DELETE` | `/cache` | 整站清空 |
| `POST` | `/cache/url` | 按 URL 失效（`urls` 数组） |
| `GET` | `/cache/status` | 后端状态、缓存页数、体积、版本 |
| `GET` | `/settings` | 读取设置 |
| `POST` | `/settings` | 写入设置（走 `Settings::sanitize()`） |
| `GET` | `/diagnostics` | 诊断报告 |

---

## 五、数据存储

| 名称 | 类型 | 说明 |
| --- | --- | --- |
| `at8sa_settings` | option | 全部设置（数组） |
| `at8sa_version` | option | 当前版本 |
| `at8sa_cache_version` | option | 缓存版本盐（失效时递增） |
| `at8sa_migrated_from_legacy` | option | 迁移标记 |
| `at8sa_activation_result` | option | 上次激活结果 |
| `at8sa_conflict_scan` | transient | 冲突扫描结果（1 小时） |
| `at8sa_redis_probe` | transient | Redis 可达性（可达 1 小时 / 不可达 1 分钟） |
| `site_accelerator_settings` | option | **2.x 旧设置，只读保留以便回滚，插件不再写入** |

| 目录 | 说明 |
| --- | --- |
| `wp-content/cache/at8-site-accelerator/` | 缓存根 |
| `wp-content/cache/at8-site-accelerator/config/` | drop-in 运行时配置 |
| `wp-content/advanced-cache.php` | drop-in（带归属标记） |

---

## 六、卸载行为

默认 `keep_data_on_uninstall = 1`：

- **不删除**任何 option；
- **不删除**缓存目录；
- **不删除** `wp-config.php` 中的 `WP_CACHE`；
- **只删除**带 `AT8 Site Accelerator` 归属标记的 `advanced-cache.php`。

用户显式关闭该开关后，卸载才会清理 option 与缓存目录。

停用（Deactivate）**永远不删数据**，只摘 drop-in 与取消定时任务。

# 更新日志

本文件记录**技术向**的完整变更。面向用户的简短说明在 `readme.txt` 的 `== Changelog ==`。

版本号规则：十进制封十进一（`1.2.9` → `1.3.0`），不存在 `1.2.10` 这种写法。

---

## 3.0.0 — 首个 Free 正式版

### 架构

* 按开发计划书 §44 重构目录：`Cache` / `Purge` / `Optimization` / `Compatibility` / `Diagnostics` / `Admin` / `REST` / `Core` / `Support` 九层。
* 引入极简 DI 容器（`Core\Container`），惰性单例，不引入 `league/container` 等外部依赖。
* PSR-4 风格自动加载（`spl_autoload_register`），无 Composer 依赖。
* 全部 42 个 PHP 类在 PHP 7.3 上通过语法检查与实例化冒烟测试（比目标基线 7.4 更严格）。

### 缓存

* **双后端**：`RedisBackend`（纯 PHP RESP 客户端，不依赖 `phpredis` 扩展）与 `DiskBackend`（目录即 URL）。
* **自动降级**：`cache_backend=auto` 时优先 Redis，不可达则降级磁盘；探测结果用 transient 缓存（可达 1 小时 / 不可达 1 分钟），避免每请求一次 `fsockopen` 超时。
* **目录布局**：`cache/at8-site-accelerator/<host>/<path>/index.html`，移动端变体在 `__m/index.html`，带查询串的在 `q-<hash>/index.html`。
* **缓存版本盐**：`site_token()|v<版本号>`。整站失效时先递增版本号再 flush，旧键立即不可达——比单纯删除更硬的安全网。
* **原子写**：临时文件 + `rename`，避免并发读到半个文件。
* **drop-in 命中路径**：`templates/advanced-cache.php` 在 WordPress 初始化前直接输出缓存并 `exit`。

### 失效

* **按 URL 精准失效**取代整站清空（`purge_scope=related` 为默认）。
* `purge_url` / `purge_post` / `purge_home` / `purge_archive` / `purge_taxonomy` / `purge_author`。
* 保存文章时联动失效：文章本身、首页、相关归档、所属分类/标签/作者、翻页。
* 单次失效 URL 数上限 300，超出则自动升级为整站清空（避免一次保存触发上千次删除）。

### 优化

* HTML 压缩：块级元素内容保护（`pre`/`textarea`/`script`/`style`/`svg`）、IE 条件注释保留、压缩率低于 40% 时放弃。
* 原生懒加载：`loading="lazy"` + `decoding="async"`，首屏前两张标 `eager`，零 JavaScript。
* 浏览器缓存：HTML 响应头 + nginx/Apache 规则片段（不擅自改写 `.htaccess`）。
* 数据库瘦身：7 类清理项，默认全关，支持先预览条数。
* WebP 自动转换：上传即转，PNG 保留 alpha，体积变大则丢弃副本。
* 链接预取：每链接独立计时器，尊重 `Save-Data` 与慢速网络，默认不启用 `prerender`。

### 兼容

* Elementor：写入护栏 + 缓存版本盐 + 响应结束后异步重建样式（`fastcgi_finish_request` 优先）。
* WooCommerce：动态页面标记 `DONOTCACHEPAGE`，库存/订单状态变化精准失效。
* 第三方缓存冲突检测：覆盖 11 款主流缓存插件，检测多站点激活与外来 `advanced-cache.php`。

### 安全

* 全部管理动作：nonce + `current_user_can('manage_options')` 双重守卫。
* REST 路由全部声明 `permission_callback`。
* 路径穿越防护：`realpath` 归一化 + 缓存根前缀比较，`..`、控制字符、URL 编码穿越全部拦截。
* `wp-config.php` 改写：备份 → 写入 → 完整性校验 → 失败自动回滚。只增删带专属标记的那一行。
* 日志脱敏：长十六进制串、`sk_`/`pk_` 前缀、Bearer Token，以及含 `cookie`/`password`/`secret`/`token`/`license`/`key` 的数组键。
* **不使用** `FLUSHDB`、`eval`、`extract`、`shell_exec`、`system`、`exec`。

### 兼容性

* 完整兼容 2.x 的全部设置项，升级后自动迁移；迁移幂等，旧选项保留以便回滚。

### 修复（相对开发过程中的自查）

* `CachePath::path_segments()` 调用了不存在的 `wp_parse_url_compat()` → 改为同类内 `parse_url_compat()`。
* `CachePath::redis_key()` 缓存键含协议，导致 http/https 混用时命中率腰斩 → 移除协议段。
* `CacheEngine::store()` 返回未压缩原文、却把压缩后内容写盘，造成首访与二访页面不一致 → 改为返回处理后内容。
* `Purger::purge_urls()` 每次调用都重写运行时配置 → 移到 `purge_all()`。
* `Filesystem::is_inside_cache_root()` 在 Windows 上用 `DIRECTORY_SEPARATOR` 拼接前缀，而 `normalize()` 输出正斜杠，导致判定恒为假 → 统一用 `/`。
* `RequestGuard::should_bypass()` 未判 `safe_mode`，drop-in 命中路径会绕过安全模式 → 补判。
* `RequestGuard` 的排除路径写成 `/preview=true`，匹配不到真实的 `/?preview=true` 预览 URL → 改为 `preview=true`。
* `Settings::sanitize()` 把"缺键"一律当成 0，导致 REST/导入这类局部更新会静默关掉用户没碰过的功能 → 改为缺键保留原值，并在设置页为每个开关补 `value="0"` 的 hidden 字段。
* `DiskBackend::delete_url()` 用 `rrmdir` 删整个目录，会连带清掉同路径下 `q-*/` 里的查询串变体 → 改为只删该 URL 自己的文件。
* `Filesystem::put_contents()` 在每个缓存目录里都放 `index.php` 守卫（1000 个页面 = 1000 个多余文件，且让"删完文件顺手删空目录"永远失败）→ 守卫只放在缓存根与 `config/`。
* drop-in 自行实现了一份主机名归一化正则，与 `CachePath::normalize_host()` 可能漂移 → 改为复用同一个方法。
* 移除无消费方的死代码：`AT8SA_MIN_CACHE_ROOT`、`AT8SA_CACHE_ROOT_URL`、`http2_push` 设置项（HTTP/2 Server Push 已被浏览器移除支持）。
* 补上 WordPress/ PHP 运行时版本门槛（插件头只在后台安装时被校验，手工部署会绕过）。

### 测试

* 198 项冒烟断言，覆盖容器、设置、缓存键、请求准入、后端、失效、运行时配置、drop-in、压缩、懒加载、浏览器缓存、兼容检测、诊断、日志脱敏、文件系统边界、模板渲染、启动流程、生命周期、卸载脚本、安全静态检查。
* 新增 `tests/unit/dropin-hit.php`：在**独立子进程**里真实执行 drop-in，验证命中、移动端变体、未命中放行、POST 放行、预览放行、安全模式放行、配置缺失放行、插件目录缺失静默退化共 8 个场景。
* 测试自带环境复位，可重复运行且结果稳定。

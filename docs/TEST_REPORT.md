# 测试报告

**当前版本：`3.0.6.3`**（实测日期 2026-10-10，闸门与 CI 同版本工具链）

> 本报告分两部分：
> **第〇节**是 3.0.6.3 的实测结果；第一～九节是测试策略与覆盖范围的长期说明，
> 其中带"3.0.1 期间"等字样的**真机记录为历史存档**（当时的版本行为，
> 例如第七节提到的 `Cache-Control: public` 已在 3.0.6.3 移除，不应据此判断当前行为）。

## 〇、3.0.6.3 实测结果（2026-10-10）

测试环境：Windows 本机开发环境 + php83（`C:/wbtools/php83`）、
PHPStan 2.3.1 + phpstan-wordpress v2.0.4（与 CI `composer update` 结果同版本）、
PHPUnit 9.6.38；另在本地 WordPress 验收站做真机 HTTP 取证。

| 闸门 | 命令 | 结果 |
| --- | --- | --- |
| 代码规范 | `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary` | **0 错误 / 0 警告**（42 个文件） |
| 静态分析 | `vendor/bin/phpstan analyse --memory-limit=2G` | **0 错误**（level 5，豁免仅 1 条：Webp GdImage 基线矛盾） |
| 冒烟测试 | `php tests/unit/smoke.php` | **358 通过 / 0 失败** |
| 二轮集成验收 | `php tests/unit/round2-integration.php` | **76 通过 / 0 失败** |
| 单元测试 | `vendor/bin/phpunit --bootstrap tests/phpunit/bootstrap.php` | **229 用例 / 1054 断言，0 失败**（6 个 Redis 用例本机显式跳过；CI Redis 任务断言跳过数为 0） |
| 打包自检 | `php tools/build-zip.php` | 白名单收录 + 回读自检通过，产物见第八节 |
| GitHub Actions | commit `6cb4afd`（tag `v3.0.6.3`） | **22 项检查全绿**（PHP 7.4–8.3 矩阵、Redis 冒烟/单元、PHPUnit 7.4/8.3、PHPStan、PHPCS、打包） |

### 3.0.6.3 专项验证（本轮整改点）

整改内容：**彻底移除 HTML 公共浏览器缓存**。

| 验证项 | 手段 | 结果 |
| --- | --- | --- |
| `BrowserCache` 全部 HTML 缓存 gate 方法已删除 | `HtmlPublicCacheRemovedTest` + smoke 移除不变式 | ✅ |
| `send_headers` 钩子不再注册（含旧 DB 值 `browser_cache_html=1` 时） | 直写桩选项表模拟历史 DB 行后 `boot()` | ✅ |
| 整页缓存命中响应头 = `no-cache, must-revalidate, max-age=0`（引擎路径） | PHPUnit 断言 | ✅ |
| drop-in 命中路径同样 `no-cache` | smoke 子进程 drop-in 夹具 + 真机取证 | ✅ |
| 真机 HTTP 取证：库中残留 `browser_cache_html=1` | MISS 无缓存头 → HIT 稳定 `no-cache, must-revalidate, max-age=0` | ✅ |
| 静态资源缓存规则片段未被误伤（nginx `css|js` / Apache `ExpiresByType`） | smoke 21-5 + `HtmlPublicCacheRemovedTest` | ✅ |
| `Cache-Control: public` 在 ZIP 非注释代码中零命中 | 对 3.0.6.3 发行包全量扫描（42 个 PHP 文件） | ✅ |

### 3.0.6.3 期间由静态分析抓到的问题（已修）

| # | 问题 | 修复 |
| --- | --- | --- |
| 1 | smoke.php 中 `$GLOBALS` 计数器/数组用字面量直写，PHPStan 推成 `int(0)`/`array{}`，汇总段被判恒假 | 经 `@var` 类型标注变量中转初始化 |
| 2 | `method_exists()` 断言"方法已删除"被判 `impossibleType` | 改用 `get_class_methods()` 运行时方法表 |
| 3 | `merge_rules()` 第二参传 `null` 与 `@param array|string` 不符 | 改传 `''`（同一非数组分支） |
| 4 | phpstan-wordpress v2.0.4 已建模 `wp_delete_file()` 副作用，Logger 的旧豁免变为"未匹配错误" | 删除豁免（本地工具链升级到与 CI 同版本后验证） |

---

## 一、历史版本结论（3.0.1 · 2026-09-26 存档）

> 以下数字为 3.0.1 时的实测结果，仅作历史依据；当前版本以第〇节为准。

四道闸门全部为**阻断式**，任何一道变红就不允许发版。

| 闸门 | 命令 | 结果 |
| --- | --- | --- |
| 代码规范 | `vendor/bin/phpcs --report=summary` | **0 错误 / 0 警告**（42 个文件） |
| 静态分析 | `vendor/bin/phpstan analyse --memory-limit=2G` | **0 错误**（level 5，豁免仅 1 条） |
| 冒烟测试 | `php tests/unit/smoke.php` | **248 通过 / 0 失败**（26 组，0 跳过） |
| 单元测试 | `vendor/bin/phpunit` | **208 用例 / 550 断言，全通过** |
| 语法检查 | `find . -name '*.php' \| xargs -n1 php -l` | 无输出（全部通过） |
| 打包自检 | `php tools/build-zip.php` | 49 文件 / 153.0 KB，无开发文件泄漏 |

连续运行两次结果一致（测试自带环境复位，可重复）。

真机端到端另有一轮 HTTP 功能验证（见第七节），42 项断言全部通过。

---

## 二、测试策略

### 四层分工，互不替代

| 层 | 手段 | 抓什么 |
| --- | --- | --- |
| 1 | `php -l` 全部 PHP / `node --check` 全部 JS | 语法错误 |
| 2 | `tests/unit/smoke.php`（248 项） | **整条链路还活着**：38 个类能否实例化、安装/停用/卸载/drop-in 命中路径能否跑通 |
| 3 | `tests/phpunit/`（208 用例） | **逻辑对不对**：单个类的行为、边界、数据提供器矩阵 |
| 4 | `tests/unit/dropin-hit.php`（子进程） | drop-in 真实执行结果 |

第 2 层与第 3 层刻意不重复。冒烟测试的价值在"广"——它把每个类都 `new` 一遍并调用关键方法，
抓的是"某个类根本加载不起来"这类 `E_ERROR`（在 PHP 里直接白屏）。
单元测试的价值在"深"——失败信息精确到方法，覆盖边界值与组合矩阵。

两者都要有。3.0.1 期间两边各抓到了对方完全没覆盖的缺陷：

* 冒烟测试抓不到、PHPUnit 抓到的：内置绕过 Cookie 表里前缀漏写 `*`，
  导致 `wp-postpass_` 永远匹配不上，**密码保护页面会被缓存给未输密码的访客**。
* PHPUnit 抓不到、真机 HTTP 验证抓到的：设置同步只在后台生效，
  WP-CLI / Cron 改设置完全不生效（见第四节第 13 条）。

### 为什么可以在没有 WordPress 的环境里跑

`tests/unit/wp-stubs.php` 提供约 900 行 WordPress 函数桩
（选项、瞬态、钩子、转义、URL、条件函数、后台 UI、`$wpdb`），
然后真实地 `new` 出每一个类并调用关键方法。

**桩必须忠实，否则测试会给出比真实环境更乐观的结论。** 3.0.1 修正了桩的两处不忠实之处：

| 桩 | 原先 | 问题 | 现在 |
| --- | --- | --- | --- |
| `do_action()` | 空实现，`return null` | "钩子挂没挂上"在测试里**恒为真**，依赖钩子的缺陷完全测不出来 | 真实按优先级回调已注册的钩子，并按 `accepted_args` 截断参数 |
| `update_option()` | 无条件写库、不触发钩子 | 依赖 `update_option_{$option}` 副作用的代码永远"看起来是对的" | 与 WP 一致：值没变时返回 `false` 且不触发 `update_option_{$option}` / `updated_option` |

这两处正是漏掉"修复 7"（设置同步只在后台生效）的直接原因。

### 测试环境的 Redis 库号

`tests/unit/wp-stubs.php` 里把 `AT8SA_REDIS_DB` 固定为 **15**。
插件默认用 2 号库——测试若不覆盖这个常量，在**开发者本机**执行测试
就会把线上缓存清掉（一次 `purge_all()` 就够）。15 号库是测试保留库，
每个用例前后清扫，且绝不做 `FLUSHDB`。

### drop-in 为什么要单开子进程

`advanced-cache.php` 命中时会 `exit`，在同一个进程里跑会把测试框架一起带走。
所以 `smoke.php` 用 `proc_open` 拉起 `dropin-hit.php`，父进程只看 stdout 与退出码。

**踩过的坑**：Windows 上 `proc_open` 会经过 `cmd.exe`，命令串按本地代码页转换。
本项目的路径含中文（用户名）与空格，`cmd.exe` 直接报
`The filename, directory name, or volume label syntax is incorrect.`；
传 `cwd` 也一样（同样要过 ANSI 转换）。

解决方案：把 bootstrap 脚本**从 stdin 管道喂进去**，命令行上只剩 `php.exe` 的路径，
而 PHP 自己的文件 API 在 Windows 上是按 UTF-8 处理路径的（7.1+），中文路径完全正常。

---

## 三、冒烟测试覆盖范围（26 组 / 248 项）

| 组 | 断言数 | 覆盖内容 |
| --- | ---: | --- |
| 容器 | 3 | 登记解析、单例、未登记返回 null |
| 设置 | 23 | 默认值完整性、布尔键覆盖、清洗（钳制/枚举/白名单/去标签）、2.x 迁移幂等、`Config::write()` 在无 `HTTP_HOST` 时退化到 `home_url()` |
| 缓存键与路径安全 | 18 | UTM 剥离、参数顺序归一化、通配规则、**6 组路径穿越载荷**、主机归一化、Redis 键不含协议 |
| 请求准入 | 22 | GET/POST、11 条黑名单路径、登录 Cookie、**8 类绕过 Cookie 前缀回归**、安全模式、UA 判定、超全局净化入口 |
| 磁盘后端 | 16 | 读写、TTL、移动端变体、查询串变体、精准删除、站外 URL 不删、统计、目录守卫 |
| 失效器 | 8 | 精准失效、站外 URL 不触发、版本盐递增、整站清空保留 config（固定 `cache_backend=disk`，不依赖宿主机是否有 Redis） |
| 后端一致性 | 5 | `Config::runtime()['backend']` 与 `factory->active_name()` 必须一致；`bump_cache_version()` 后旧实例立即作废 |
| **设置变更同步（架构守卫）** | 4 | **监听器必须挂在 `Core\SettingsSync`（由 `boot_shared` 启动），不得出现在任何后台类里**；兜底逻辑必须走 `Config::needs_refresh()` 而不是自己拼主机名 |
| **运行时配置过期检测** | 3 | 配置带设置指纹；刚写完不算过期；**绕过 `update_option()` 直接改库后判定为过期** |
| 运行时配置与 drop-in | 19 | 必要键、不含密钥字段、不含站点内容、写入回读、drop-in 安装/识别/卸载、归属标记 |
| wp-config.php / WP_CACHE 开关 | 13 | 含大括号 salt 的回归用例、可逆性三方向（插入/删除/替换）、幂等、停用精确还原原文 |
| HTML 压缩 | 11 | 过短跳过、体积变小、`pre`/`script`/`textarea` 内容保留、IE 条件注释保留、内联 CSS 折叠（引号内不折叠、JS 换行保留） |
| 懒加载 | 9 | 首屏 eager、后续 lazy、`decoding`、已有属性不覆盖、`data-no-lazy` 尊重、`data:` 跳过、iframe、关闭跳过首屏后的行为 |
| 链接预取 | 9 | 策略开关、视口触发、上限与冷却、同域限制 |
| 浏览器缓存 | 6 | nginx/Apache 规则、标记块配对、TTL 区间、服务器类型识别 |
| 兼容检测 | 10 | 冲突扫描、结构完整性、flush |
| 诊断 | 11 | 8 个分组齐全、不含密码/密钥/评分字段 |
| 缓存引擎 | 6 | boot、store 返回类型与指纹、GET 写入成功、空响应不写、非 GET 不写 |
| 日志脱敏 | 6 | 长十六进制串、Bearer token、Cookie 字段、`[redacted]` 占位、清空 |
| 文件系统边界 | 6 | 根内/根外/系统路径/穿越路径判定、越界删除被拒、越界目录未被删 |
| 设置页模板渲染 | 8 | 无致命错误、表单、7 个标签页、7 个面板、无 PHP 标签泄漏、值已转义、字段名全部指向已登记设置键 |
| 完整启动流程 | 3 | 前台启动、后台启动、钩子注册数量 |
| 生命周期 | 7 | 激活无错误、写版本、建目录、建 config、停用无错误、drop-in 已移除、设置仍保留 |
| 卸载脚本 | 4 | 拒绝直接访问、尊重保留数据开关、只删自己的 drop-in、不使用 FLUSHDB |
| 安全静态检查 | 7 | ABSPATH 守卫、无 eval/FLUSHDB/extract、无硬编码密钥、无命令执行函数、REST 权限回调 |
| drop-in 命中路径 | 10 | 见下节 |

### drop-in 子进程测试（10 项）

| 场景 | 期望 | 结果 |
| --- | --- | --- |
| `hit` | 无致命错误、输出缓存体、不再走 WordPress | ✅ |
| `mobile` | 移动 UA 读 `__m/index.html` 而非桌面文件 | ✅ |
| `miss` | 交还控制权给 WordPress | ✅ |
| `post` | POST 请求即使有缓存文件也放行 | ✅ |
| `preview` | `/?p=1&preview=true` 即使根路径有缓存也放行 | ✅ |
| `safe_mode` | 安全模式下放行 | ✅ |
| `no_config` | 配置文件缺失时放行 | ✅ |
| `no_plugin` | 插件目录缺失时静默退化、无错误输出 | ✅ |

> `preview` 场景刻意把缓存文件写在**根路径**：如果 `RequestGuard` 漏判 `preview=true`，
> drop-in 就会把首页缓存吐给预览请求——这正是最典型的"预览看到旧内容"事故。

---

## 四、单元测试覆盖范围（12 个类 / 208 用例 / 550 断言）

| 测试类 | 用例 | 覆盖内容 |
| --- | ---: | --- |
| `CachePath` | 38 | URL 归一化、查询串剥离与哈希变体、磁盘目录布局、移动端变体、Redis 键格式、主机归一化、路径穿越防护 |
| `RequestGuard` | 36 | 绕过条件矩阵（数据提供器）、黑名单路径、Cookie 规则（前缀/精确名）、安全模式、超全局净化 |
| `Settings` | 26 | 默认值、清洗（钳制/枚举/白名单）、缺键保留、迁移幂等、布尔键一致性 |
| `BrowserCache` | 21 | 服务器识别、规则生成、标记块配对、TTL 区间、HTML 不缓存开关 |
| `Filesystem` | 19 | 根内/根外判定、原子写、目录守卫、递归删除边界 |
| `DiskBackend` | 16 | 读写、TTL、变体隔离、精准删除、统计 |
| `LazyLoad` | 15 | 首屏/后续、已有属性、排除规则、iframe、`data:` 跳过 |
| `HtmlMinifier` | 12 | 体积缩减、`pre`/`script`/`textarea` 保留、注释保留、内联 CSS 折叠 |
| `SettingsSync` | 7 | 钩子注册（含 `accepted_args`）、配置落盘、**绕过实例缓存后仍读到新值**、**`update_option()` 端到端触发同步**、值未变不触发、清理旧条目 |
| `ConfigStaleness` | 6 | 缺失/刚写完/设置变更/无指纹字段四种判定、指纹稳定性与区分度、写与读的主机名一致性 |
| `RedisBackend` | 6 | 读写往返、索引集合维护、flush 清理当前盐、**孤儿索引集合清扫**、**跨站点隔离**、空盐安全退避 |
| `Purger` | 6 | **`purge_all()` 返回真实条目数**、清空后端、版本恰好递增一次、重写运行时配置、精准失效范围、站外 URL 不涉及 |

`RedisBackend` 的 6 个用例在 Redis 不可达时 `markTestSkipped` 显式跳过。
CI 的 `test-redis` 任务不仅带 `services: redis`，还额外断言**跳过数为 0**——
否则这些用例会静默 skip，覆盖形同虚设。

---

## 五、静态分析（PHPStan level 5）

首跑 **49 个错误**，逐条分类处置，最终 0 错误。分类如下：

| 类别 | 数量 | 处置 |
| --- | ---: | --- |
| 真代码缺陷 | 10 | 已被 PHPDoc 收窄的冗余判空、确定存在的数组偏移上的 `isset()`、死代码 |
| 真文档缺陷 | 9 | `WP_Post` / `WC_Product` 未写反斜杠被解析成插件自己的命名空间；`@param array` 与实际 `mixed` 不符；`@var` 未标 `|null` 导致判空被判死 |
| 改为消费依赖属性 | 4 | `CachePluginDetector::$settings`、`ElementorCompat`/`WooCommerceCompat`/`Webp` 的 `$logger` 改为真实使用（同时提升可观测性） |
| 真实潜在 Bug | 2 | `is_cart()` 存在不代表 `is_checkout()` 存在；`rebuild_post_css()` 用 `self::` 调非静态方法 |
| 测试自身缺陷 | 7 | 3 处恒真/空洞断言 + 4 处类型推断问题 |
| 配置局限 | 1 | `GdImage` 在 7.4 基线下不存在（用 `scanFiles` 而非 `stubFiles` 提供） |

"恒真/恒假"类噪音用 `treatPhpDocTypesAsCertain: false` 从**根上**关掉，
而不是逐条 `ignoreErrors`。这不是在关检查，而是承认
**PHPDoc 对 WordPress 插件是契约、不是运行时保证**——
数据大量来自 `apply_filters`、数据库脏数据与任意客户端，防御性判空必须留着。

`ignoreErrors` 全项目只有 1 条，且写明了理由（见 `phpstan.neon.dist`）。

---

## 六、测试过程中发现并修复的问题

这一节是测试的价值所在。以下问题全部是**跑测试或真机验证才暴露的**，
静态检查和人工阅读都没发现。

### 冒烟测试阶段（3.0.0 期间）

| # | 问题 | 后果 | 修复 |
| --- | --- | --- | --- |
| 1 | `Filesystem::is_inside_cache_root()` 用 `DIRECTORY_SEPARATOR` 拼前缀，而 `normalize()` 输出正斜杠 | Windows 上白名单判定恒为假，缓存无法删除 | 统一用 `/` |
| 2 | `RequestGuard::should_bypass()` 未判 `safe_mode` | 开启安全模式后 drop-in 仍在吐缓存，用户以为已停缓存 | 补判 `safe_mode` |
| 3 | 排除路径写成 `/preview=true` | 匹配不到真实的 `/?preview=true`，**预览页会被缓存** | 改为 `preview=true` |
| 4 | `Settings::sanitize()` 把缺键一律当 0 | REST/导入等局部更新会静默关掉用户没碰过的功能 | 缺键保留原值 + 设置页补 hidden 字段 |
| 5 | `DiskBackend::delete_url()` 用 `rrmdir` 删整个目录 | 删 `/hello/` 会连带清掉 `/hello/?page=2` 的缓存，精准失效退化成范围失效 | 只删该 URL 自己的文件 |
| 6 | `put_contents()` 在每个缓存目录放 `index.php` | 1000 页面 = 1000 个多余文件，且"删完顺手删空目录"永远失败 | 守卫只放缓存根与 `config/` |
| 7 | `CacheEngine::store()` 未做请求级准入复查 | 公开的 ob 回调被直接调用时可能把 POST/登录态写进共享缓存 | 补 `RequestGuard::should_bypass()` |
| 8 | 静态检查在 Windows 上把 `includes\REST\...` 当路径 | 按 `REST/` 过滤匹配不到，REST 权限检查静默假绿 | 路径统一归一化为正斜杠 |
| 9 | 静态检查直接对全文 grep `FLUSHDB` | 把"绝不用 FLUSHDB"的注释当成实际调用 | 扫描前用 `token_get_all()` 剥注释 |
| 10 | 测试不清理缓存目录 | 第二轮运行时读到上轮残留，`miss` 场景假命中 | 测试自带环境复位 |
| 11 | 桩缺 `esc_html_e()` / `ARRAY_A` | 模板渲染直接 Fatal，整轮测试中断 | 补齐桩 |
| 12 | 桩 `submit_button()` 里 `unset($wrap)` 后又用 `$wrap` | 每次渲染产生 Notice | 移除误删 |

**第 3 条是这一阶段最大的收获**：一个 `/` 的差别，
导致"预览文章看到的是缓存的旧版本"这个用户投诉量极高的经典 bug。

### 补 PHPUnit / PHPStan / 真机验证阶段（3.0.1 期间）

| # | 问题 | 后果 | 发现方式 | 修复 |
| --- | --- | --- | --- | --- |
| 13 | 内置绕过 Cookie 表里 `wp-postpass_`、`comment_author_`、`wp_woocommerce_session_` 写成**裸前缀**，而匹配函数只在规则以 `*` 结尾时才做前缀匹配 | **密码保护页面会被缓存并端给未输密码的访客**（安全缺陷） | PHPUnit `RequestGuardTest` 的数据提供器矩阵 | 补齐 `*` |
| 14 | 上一条修好后连带暴露：`wordpress_logged_in_*` 同时被 `has_auth_cookie()` 与绕过表管理 | `cache_logged_in=1` 永远被拦下，**开关是死的** | PHPUnit 回归用例 | 从绕过表移除，改为**单一归属**（登录态只由 `has_auth_cookie()` 管） |
| 15 | 设置同步链路的三个动作挂在 `Admin\SettingsPage::boot()`，而它只在 `is_admin()` 时启动 | **WP-CLI / WP-Cron / 其它插件改设置完全不生效**。真机受控实验：连改 6 次 `cache_backend`，运行时配置始终是旧值 | 真机 HTTP 验证 | 提取为 `Core\SettingsSync`，挂到 `boot_shared()` |
| 16 | `Plugin::ensure_runtime_config()` 只在配置文件**缺失**时重写，且自己写了一份与 `Config::write()` **不等价**的主机名归一化正则 | 绕过 `update_option()` 的写入永不生效；"写 A 文件、查 B 文件" | 代码审查 + 真机实验 | 新增 `Config::needs_refresh()` 指纹自愈；主机名归一化统一到 `Config::config_host()` |
| 17 | `purge_all()` 先 `bump_cache_version()` 再 `make()`，flush 打在新盐命名空间上 | 旧盐的索引集合（`SADD` 建的、**无 TTL**）被永久孤立，Redis 内存无限堆积。测试机上残留 `v10`~`v36` 共 **21 个** | 真机 `redis-cli --scan` + 代码推演 | 改为**先 flush 当前盐、再换盐**；并在 `RedisBackend::flush()` 额外清扫历史孤儿 |
| 18 | 同一条 `purge_all()` 的副作用：`stats()` 在新盐上统计 | "失效条目数"恒为 0，用户点"清缓存"永远看到 0 条 | 真机验证 | 随第 17 条一并修复，真机返回值从 `0` 变为真实条目数 |
| 19 | drop-in 与插件侧对同一个响应头 `X-AT8-Cache-Backend` 输出不同大小写（`redis` vs `Redis`） | 同一诊断字段两种取值，排查时容易误判 | 真机 HTTP 验证 | drop-in 模板改为与 `BackendInterface::name()` 对齐 |
| 20 | WordPress 桩的 `do_action()` 是空实现、`update_option()` 不触发钩子 | "钩子挂没挂上"在测试里恒为真，**是漏掉第 15 条的直接原因** | 复盘第 15 条时定位 | 两处桩改为忠实实现 |
| 21 | 测试环境的 Redis 库号未覆盖，默认落在插件的 2 号库 | 在开发者本机跑测试会清掉**线上缓存** | 真机验证时意识到 | `wp-stubs.php` 固定 `AT8SA_REDIS_DB = 15` |

---

## 七、真机端到端验证（3.0.1 · 2026-09-26 历史存档，42 项 / 全部通过）

> **历史记录**：本轮验证的环境是当时版本（3.0.1）。其中"Redis 后端主路径"组
> 当时观察到 HTML 命中带 `Cache-Control: public, max-age=3600` —— 该行为
> 已在 **3.0.6.3 彻底移除**（现为 `no-cache, must-revalidate, max-age=0`，
> 见第〇节专项验证），其余结论（缓存命中、Cookie 拦截、精准失效等）仍然有效。

环境：`https://wordpress.xmm.fan/`（WordPress + nginx + Redis，PHP 8.3.33）

| 组 | 项数 | 关键结论 |
| --- | ---: | --- |
| 设置变更 → 运行时配置同步 | 5 | `cache_backend` 在 `disk`/`redis`/`auto` 间切换**每次立即生效**（修复前 6 次全不生效）；`cache_ttl` 同步 |
| Redis 后端主路径 | 8 | `MISS-SAVED → HIT`；落盘 1 个数据键；HIT 后键数不变；只留 1 个索引集合；`Cache-Control: public, max-age=3600` |
| Cookie 语义 | 9 | 8 类绕过 Cookie 全部 `BYPASS`（含登录态、密码保护、评论者、WooCommerce 购物车、EDD）；无关 Cookie 不误伤 |
| `cache_logged_in` 开关 | 3 | `=1` 时登录态可缓存（原先死开关），但**密码保护页面依然拦截** |
| 请求方法 / 后台 / 查询串 | 5 | POST、`wp-admin`、`wp-login.php`、`wp-json` 全部 `BYPASS`；查询串生成独立变体 |
| 磁盘后端 | 8 | `MISS-SAVED → HIT`；布局 `<host>/__root/index.html`；disk 后端下 Cookie 拦截同样生效 |
| 整站失效 | 4 | `purge_all()` 返回真实条目数（修复前恒为 0）；失效后 Redis 数据键 0 个、**孤儿索引集合 0 个** |

可复现脚本见仓库根目录的验证流程说明；`tools/build-zip.php` 的自检确保发布包里不含测试与开发文件。

---

## 八、未覆盖的部分（诚实说明）

| 项目 | 原因 | 计划 |
| --- | --- | --- |
| 多站点（Multisite） | 需要完整 WP 多站点环境 | 人工验证 + 后续自动化 |
| 浏览器端行为（懒加载、预取） | 需要真实浏览器 | Playwright 端到端 |
| 高并发下的缓存击穿 | 需要压测工具 | 见 `PERFORMANCE_BENCHMARK.md` 的测量方案 |
| 各版本 WordPress 全矩阵 | 真机只装了当前版本 | 由 CI 的 PHP 矩阵 + 人工抽查覆盖 |
| 第三方缓存插件共存 | 需要逐一安装验证 | `CachePluginDetector` 已做静态识别与提示，实际共存待逐个站点验证 |
| 支付 / 授权链路（Pro） | Phase 4 起才开发 | 见 `COMMERCE_OPEN_QUESTIONS.md` |

---

## 九、如何运行

```bash
# 四道闸门（与 CI 完全一致）
vendor/bin/phpcs --report=summary
vendor/bin/phpstan analyse --memory-limit=2G --no-progress
php tests/unit/smoke.php
vendor/bin/phpunit --configuration phpunit.xml.dist

# 单独跑 drop-in 夹具
php tests/unit/dropin-hit.php hit
php tests/unit/dropin-hit.php miss

# 翻译模板同步检查（CI 会跑，本地也可跑）
php tools/make-pot.php && git diff --stat languages/

# 打包
php tools/build-zip.php
```

冒烟测试不依赖 Composer、不依赖网络；PHPUnit 与 PHPStan 需要
`composer update` 安装开发依赖（`vendor/` 刻意不入版本控制，分发时不带）。
Redis 相关用例在 Redis 不可达时显式跳过，其余全部可离线运行。

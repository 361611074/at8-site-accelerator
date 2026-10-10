# 发布检查清单

> 对应开发计划书 §87（发布产物）、§133（Phase 交付物）。
> 每次发布新版本前逐项勾选，**不允许跳过**。

版本：`3.0.6.3`
类型：WordPress.org 审核二轮整改版（彻底移除 HTML 公共浏览器缓存）

> 3.0.5 及更早版本的检查记录为**历史存档**，见文末"历史记录"；
> 本版本（3.0.6.3）的验收结果以"3.0.6.3 实测记录"一节为准。

---

## 零、发布顺序（先看这一节，3.0.4 在这里翻过车）

**唯一正确的顺序：**

```
1. 改代码 → 提交 → 推 main
2. 等 GitHub Actions 全绿（Actions 页看，全部 success）
3. 全绿之后才打 tag
4. 打 tag 触发打包，ZIP 自动挂到 Release
5. 核对 Release 附件可下载
```

**绝对不要先打 tag。** tag 是不可变引用，一旦分发出去就不能改（改就是破坏性操作）。
3.0.4 就是先打了 tag 才push，结果：

- CI 红了（PHPStan 6 错 + PHPCS 2 警），但 tag 已经指向有问题的提交；
- 打包作业被 `needs` 依赖链挡掉，一直 skipped，**Release 里没有任何 ZIP 附件**；
- 补救方式是强推 tag 到修复提交，而不是删 tag 重打。

**已经先打了 tag 怎么办**：不要删 tag。用 Actions 页面上的
**Run workflow → 填入 `tag` 输入框**（例如 `v3.0.4`）手动重跑，
该入口会按 tag 检出代码、重新打包、并覆盖 Release 附件。

### 推完之后必须做的事

- [ ] **打开 Actions 页面确认这次推送的运行结果是全绿**，不是"推了就当完事"
- [ ] 如果有红项：先修→ 重新推送 → 再等绿 → tag 才有意义
- [ ] 核对Release 页面上确实有 `.zip` 附件

---

## 一、代码质量（四道闸门，全部阻断式）

- [x] 全部 PHP 文件通过 `php -l`
- [x] 全部 JS 文件通过 `node --check`
- [x] `php tests/unit/smoke.php` → **358 通过 / 0 失败**（3.0.6.3，2026-10-10 实测）
- [x] `php tests/unit/round2-integration.php` → **76 通过 / 0 失败**（3.0.6.3，2026-10-10 实测）
- [x] `vendor/bin/phpunit` → **229 用例 / 1054 断言，全通过**（6 个 Redis 用例在本机显式跳过，CI 的 Redis 任务断言跳过数为 0）
- [x] `vendor/bin/phpstan analyse` → **0 错误**（level 5，phpstan 2.3.1 + phpstan-wordpress v2.0.4，豁免仅 1 条且附理由）
- [x] `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary` → **0 错误 / 0 警告**（42 个文件）
- [x] 连续运行两次结果一致（测试自带环境复位）
- [x] 所有 `use` 引用可解析（无指向不存在类的 import）
- [x] 无未使用的类 / 常量 / 设置项（本轮已清理 `ServiceProviderInterface`、`AT8SA_MIN_CACHE_ROOT`、`AT8SA_CACHE_ROOT_URL`、`http2_push`，以及 `SettingsPage` 的 8 个未使用 import）
- [x] 每个设置项都有实际消费方（无"点了没反应"的开关）
- [x] CSS 中无 `!important`
- [x] 前端 JS 不依赖 jQuery（`admin.js`、`preloader.js` 均为原生）
- [x] CI 的 `package` 任务依赖全部质量闸门（`test` / `test-redis` / `phpunit` / `phpstan`）

## 二、安全

- [x] 所有 PHP 文件有 `ABSPATH` 守卫
- [x] 无 `eval()` / `extract()` / `FLUSHDB`
- [x] 无命令执行类函数（`shell_exec` / `passthru` / `proc_open` / `popen` / `system` / `exec`）
- [x] 无硬编码密钥（4 类模式扫描通过）
- [x] 全部 REST 路由声明 `permission_callback`
- [x] 全部 admin-ajax 动作有 nonce + capability 双重校验
- [x] 路径穿越载荷全部被拦截（6 组）
- [x] 诊断输出不含密码 / 密钥 / 评分字段
- [x] 日志脱敏生效（长十六进制串 / Bearer / Cookie 字段）
- [x] 卸载脚本只删带归属标记的 drop-in
- [x] 缓存目录有 `index.php` 守卫
- [x] 完整审计报告：`docs/SECURITY_AUDIT.md`

## 三、兼容性

- [x] 2.x 设置全部保留并有对应消费方
- [x] 2.x → 3.0 迁移幂等（二次调用不重复执行）
- [x] 旧 option 保留以便回滚
- [x] 停用不删数据
- [x] 卸载默认不删数据
- [x] Elementor 三层防护已实现
- [x] WooCommerce 动态页标记 + 库存失效已实现
- [x] 11 款缓存插件冲突检测已实现
- [x] Windows 路径处理已验证（本轮修复跨平台判定 bug）

## 四、国际化

- [x] 所有用户可见文案使用 `__()` / `esc_html__()` / `esc_html_e()` 等
- [x] Text Domain 统一为 `at8-site-accelerator`
- [x] `languages/at8-site-accelerator.pot` 已生成（316 条）
- [x] `.pot` 与源码同步（CI 会自动校验，过期即失败）
- [x] `.pot` 中的文件引用使用正斜杠（跨平台）

## 五、文档

- [x] `readme.txt`（WordPress.org 格式，含 Description / Installation / FAQ / Changelog / Upgrade Notice）
- [x] `CHANGELOG.md`（技术向完整变更，含每条修复的原因）
- [x] `LICENSE`（GPL-2.0 全文）
- [x] `docs/PRODUCT_SPEC.md`
- [x] `docs/ARCHITECTURE.md`
- [x] `docs/FREE_PRO_MATRIX.md`
- [x] `docs/SECURITY_AUDIT.md`
- [x] `docs/COMPATIBILITY.md`
- [x] `docs/TEST_REPORT.md`
- [x] `docs/PERFORMANCE_BENCHMARK.md`
- [x] `docs/RELEASE_CHECKLIST.md`（本文件）
- [x] `docs/PHASE_REPORT.md`

## 六、版本号一致性

发布前必须五处一致，缺一处就会出现"用户装的版本和你说的是两个版本"：

- [x] 插件头 `Version: 3.0.6.3`
- [x] `define( 'AT8SA_VERSION', '3.0.6.3' )`
- [x] `readme.txt` 的 `Stable tag: 3.0.6.3`
- [x] `CHANGELOG.md` 最新条目为 `3.0.6.3`
- [x] `languages/at8-site-accelerator.pot` 的 `Project-Id-Version`
- [x] tag 名与版本号对应（`v3.0.6.3`）

> 版本号规则：十进制封十进一（`1.2.9` → `1.3.0`），不存在 `1.2.10`。

> `tools/build-zip.php` 的版本号是从插件头正则读取的，不是独立维护的常量——
> 所以只要插件头改了，产物文件名自动跟随，不存在"打包版本对不上"的可能。

## 七、仓库卫生

- [x] `.gitignore` 已配置（排除测试产物、打包产物、密钥、编辑器文件）
- [x] `tests/unit/fake-wp/` 不入库（每次运行自动重建）
- [x] `dist/` 不入库
- [x] `vendor/` 与 `composer.lock` 不入库（分发时不带开发依赖）
- [x] 无 `.env` / 凭证文件入库
- [x] CI 已激活：`.github/workflows/ci.yml`
      （PHP 7.4–8.3 矩阵 + Redis 冒烟/单元 + PHPUnit 矩阵 + PHPStan + PHPCS + 打包）
- [x] CI 打包作业支持 `workflow_dispatch` 手动触发（可指定 tag 重打包）
- [x] CI 打包产物自动挂到对应 Release，无需人工上传
- [x] `package` 作业是唯一持有 `contents: write` 的作业，其余维持只读
- [x] `phpcs.xml.dist` 已配置，豁免项均有理由说明
- [x] 测试用 Redis 库号固定为 15（`tests/unit/wp-stubs.php`），不会碰真实站点的 2 号库

## 八、打包产物

- [x] `php tools/build-zip.php` 执行成功
- [x] ZIP 内层结构为 `at8-site-accelerator/...`（WordPress 可直接安装）
- [x] ZIP 白名单式收录，不含 `tests/` `docs/` `tools/` `.github/` `dist/` `.wordpress-org/`
- [x] 打包脚本内置回读自检（发现禁入路径即失败退出）
- [x] CI 侧再叠一道目录段精确匹配自检（能区分 `docs/` 与 `DocsHelper.php`）
- [x] ZIP 版本号与插件头版本号一致
- [x] **产物已上传到 GitHub Release 附件**（不在仓库里，去 Release 页下载）

> 产物实际内容（3.0.6.3，2026-10-10 实测）：49 个文件 / 215.4 KB，顶层 9 项——
> `includes/`(38) `assets/`(3) `templates/`(2) `languages/`(1)
> 加`at8-site-accelerator.php`、`uninstall.php`、`readme.txt`、`CHANGELOG.md`、`LICENSE`。
> 五处版本号已在 ZIP 内核验一致（插件头 / AT8SA_VERSION / Stable tag），
> 非 PHP 注释代码对 `Cache-Control: public`、wp-config 临时备份文件名零命中。
>
> 注意别把**仓库根目录**误当成打包产物：仓库里有 `.github`、`docs`、`tests`、
> `tools`、`README.md`（仓库说明）这些，它们不该进发行包。
> 两者的可靠区分点：发行包里只有 `readme.txt`，没有 `README.md`。

## 九、发布前人工复核（必须在真实站点做）

以下项目**无法**在开发环境自动验证，发布前必须人工过一遍。

### 复核记录（2026-09-26 · `https://wordpress.xmm.fan/`）

环境：WordPress 7.1.2 / PHP 8.3.33 / nginx + HTTP/2 / 4 核 3.9G VPS /
主题 Twenty Twenty-Five / 501 篇文章 / Redis 后端。

| # | 项目 | 结果 | 证据 |
| --- | --- | --- | --- |
| 1 | 真实站点启用无白屏 | ✅ | `HTTP/2 200`，首页正常渲染 |
| 2 | `advanced-cache.php` 已生成且带归属标记 | ✅ | 4974 字节，`grep -c "AT8 Site Accelerator"` = 1 |
| 3 | `wp-config.php` 中 `WP_CACHE` 已开启 | ✅ | 第 98 行 `define( 'WP_CACHE', true );`，`php -l` 无语法错误，原文件已备份为 `wp-config.php.at8sa.bak` |
| 4 | 二次访问 `X-AT8-Cache: HIT` | ✅ | 第 1 次 `MISS-SAVED` → 第 2、3 次 `HIT` |
| 5 | 编辑 1 篇只清相关 URL | ✅ | 预热 71 条 → 清除 4 条（5.6%）；目标文章 `MISS-SAVED`，无关文章仍 `HIT`。对照组 `purge_scope=all` 清 71/71 |
| 6 | Elementor 编辑保存后样式正常 | ⬜ **未验证** | 测试站未安装 Elementor |
| 7 | WooCommerce 加购 / 结算正常 | ⬜ **未验证** | 测试站未安装 WooCommerce |
| 8 | 安全模式下全部不命中 | ✅ | 开启后连续 3 次均为 `X-AT8-Cache: BYPASS` |
| 9 | 停用后设置与缓存目录保留 | ✅ | 设置条目数不变；`wp-content/cache/at8-site-accelerator` 仍在；drop-in 已移除；`WP_CACHE` 保留（无害） |
| 10 | 重新启用后恢复工作 | ✅ | drop-in 重建，设置完整保留，`MISS-SAVED → HIT → HIT` 正常 |
| 11 | PHP 8.x 真实站点 | ✅ | PHP 8.3.33 |

**未验证项（6 / 7）的处理**：`ElementorCompat` 与 WooCommerce 相关逻辑只做"检测到就绕过"，
不改写业务行为，代码路径已被 248 项冒烟断言与 `RequestGuardTest` 的 Cookie 矩阵覆盖；
但仍建议在上游用户的 Elementor / WooCommerce 站点上补一次真机确认后再发正式版。

### HTTP 功能验证（2026-09-26 · 42 项 / 全部通过）

第二轮真机验证专门针对 3.0.1 修动的链路，逐项断言（脚本可复现）：

| 组 | 项数 | 关键结论 |
| --- | ---: | --- |
| 设置变更 → 运行时配置同步 | 5 | `cache_backend` 在 `disk`/`redis`/`auto` 间切换**每次立即生效**；`cache_ttl` 同步 |
| Redis 后端主路径 | 8 | `MISS-SAVED → HIT`；落盘 1 个数据键；HIT 后键数不变；只留 1 个索引集合 |
| Cookie 语义 | 9 | 8 类绕过 Cookie 全部 `BYPASS`；无关 Cookie 不误伤 |
| `cache_logged_in` 开关 | 3 | `=1` 时登录态可缓存，但**密码保护页面依然拦截** |
| 请求方法 / 后台 / 查询串 | 5 | POST、`wp-admin`、`wp-login.php`、`wp-json` 全部 `BYPASS`；查询串生成独立变体 |
| 磁盘后端 | 8 | `MISS-SAVED → HIT`；布局 `<host>/__root/index.html` |
| 整站失效 | 4 | `purge_all()` 返回真实条目数；失效后 Redis 数据键 0 个、孤儿索引集合 0 个 |

### 复核时顺带发现并修复的问题

第一轮（3.0.0 → 3.0.1）：

- `wp-config.php` 的括号配平校验把 salt 字符串里的大括号也算进去 → 校验恒失败 → `WP_CACHE` 永远开不起来（详见 `docs/PERFORMANCE_BENCHMARK.md` 7.8）。
- 缓存目录属主是 root 而 PHP-FPM 是 `www` → 磁盘后端静默写不进去，表现为"缓存没生效"但不报错。

第二轮（补 PHPUnit / PHPStan / HTTP 验证时）：

- **密码保护页面会被缓存**：内置绕过 Cookie 表里的前缀漏写 `*`，`wp-postpass_` 永远匹配不上。
  由 PHPUnit 的数据提供器矩阵抓到（冒烟测试没覆盖到）。
- **`cache_logged_in` 是死开关**：修好上一条后连带暴露——`wordpress_logged_in_*` 被两处逻辑重复管理。
  改为单一归属。
- **WP-CLI / Cron 改设置完全不生效**：同步链路挂在 `is_admin()` 之后的后台类里。
  真机受控实验连改 6 次 `cache_backend` 全部无效。已提取为 `Core\SettingsSync`。
- **Redis 索引集合无限堆积**：`purge_all()` 先换盐再 flush，旧盐的索引集合（无 TTL）被永久孤立。
  测试机上残留 21 个。已调整顺序并加清扫。
- **`purge_all()` 的条目数恒为 0**：同上一条的副作用，日志里的"失效条目数"完全失真。
- **WordPress 桩不忠实**：`do_action()` 空实现、`update_option()` 不触发钩子——
  这是漏掉上面第 3 条的直接原因，已修正。

### 3.0.6.3 实测记录（2026-10-10）

本轮整改内容：按 wp.org 审核二轮意见**彻底移除 HTML 公共浏览器缓存**——
`BrowserCache` 删除全部 HTML 缓存 gate 方法；整页缓存命中路径响应头改为
`no-cache, must-revalidate, max-age=0`（磁盘与 drop-in 两条路径）；设置页删除
HTML 缓存开关；旧 DB 值运行时忽略（数据保留以便回滚）。

新增/替换测试：

| 用例 | 内容 | 结果 |
| --- | --- | --- |
| `HtmlPublicCacheRemovedTest`（PHPUnit，5 用例 / 148 断言） | gate 方法不存在、`send_headers` 钩子未注册、命中路径无 `Cache-Control: public`、共享绕过规则幸存、静态资源规则片段完好 | ✅ 全过 |
| `smoke.php` 21-3…21-5 移除不变式 | 含直写桩选项表模拟历史 DB 行（`browser_cache_html=1`）验证运行时忽略 | ✅ 358/0 |
| `round2-integration.php` | 76 项行为断言 | ✅ 76/0 |
| PHPUnit 全量 | 229 用例 / 1054 断言 | ✅ 0 失败 |
| PHPStan level 5 | phpstan 2.3.1 + phpstan-wordpress v2.0.4（与 CI 同版本） | ✅ 0 错误 |
| PHPCS | `--standard=phpcs.xml.dist`，42 文件 | ✅ 0/0 |
| 真机 HTTP 取证（本地验收站，库中残留 `browser_cache_html=1`） | MISS 无缓存头 → HIT 稳定 `no-cache, must-revalidate, max-age=0` | ✅ |

Plugin Check 与最终 ZIP 核验结果见下文十一节与八节。

## 十、发布后

### 3.0.6.3 发布记录（2026-10-10）

- [x] 推 `main` 并确认 Actions 全绿（commit `09a23fa`，22 项检查全绿）
- [x] 打 git tag `v3.0.6.3`（**在 main 全绿之后**才打，遵守第零节顺序）
- [x] GitHub Release 自动挂载 ZIP（`at8-site-accelerator-3.0.6.3.zip`，
      已下载与本地 `dist/` 构建逐文件 CRC 比对——49 个文件内容完全一致）
- [x] `readme.txt` 的 `Tested up to` = **7.1**，实跑依据见下（7.1.2 实测，沿用 3.0.5 时的验证结论，WP 版本未变）

  <!-- 下面两行是给 tests/unit/smoke.php 读的机器可读标记，改这两个值必须同步改依据 -->
  <!-- AT8SA_TESTED_UP_TO: 7.1 -->
  <!-- AT8SA_WP_LATEST_SEEN: 7.1.2 -->

  ### 依据一：7.1 是已发布的稳定版线，不是 beta / RC

  wp.org 官方接口实测（2026-10-06 取数）：

  ```text
  GET https://api.wordpress.org/core/stable-check/1.0/
      → 7.1.2 的 status = "latest"        ← 官方认定的当前活跃版本

  GET https://api.wordpress.org/core/version-check/1.7/
      → "current": "7.1.2"
      → response: "upgrade" / "autoupdate"
      → download: https://downloads.wordpress.org/release/wordpress-7.1.2.zip
  ```

  注意下载路径是 `/release/`（正式发布通道），不是 `/beta/` 或 `/nightly/`，
  且该 URL 实返回 HTTP 200 / 37 MB。所以 7.1 已经是一条**已发布**的版本线，
  当前 HEAD 是 7.1.2。

  按 wp.org FAQ —— `Tested up to` 不得高于当前 RC，无 RC 时不得高于当前活跃版本。
  7.1 ≤ 7.1.2，合规。**不是**"为了好看填未来版本"。

  ### 依据二：确实在它上面跑过

  本机 WordPress 测试台已从 7.1 就地升级到 **7.1.2**（用官方 `/release/` 包覆盖
  `wp-admin` / `wp-includes` + 根目录文件，`wp-config.php` 未动），
  `wp core version` = **7.1.2**，`wp core update-db` 报告 db 已是最新（61833）。
  环境：PHP 8.2 + SQLite。

  在其上实跑了 Plugin Check 与「激活 → 停用 → 重新激活 → 卸载」全流程，结果见第十一节。

  ### 维护规则

  若将来 WP 升到 7.2 / 8.0 而没真跑过，这个字段**必须改回去**，不能跟着填。
  冒烟测试里有断言把 `Tested up to` 与本文件记录的值绑定，防止两边漂移。

- [x] 记录发布日志到 `docs/PHASE_REPORT.md`（3.0.6 / 3.0.6.1 / 3.0.6.2 / 3.0.6.3 变更见 `CHANGELOG.md`）

### 历史记录（3.0.5 · 2026-09-26 存档）

- [x] 推 `main` 并确认 Actions 全绿
- [x] 打 git tag `v3.0.5`
- [x] 创建 GitHub Release（ZIP 由 CI 自动挂载）

## 十一、wp.org 上架前必须补的验证

以下**无法**在本仓库内自动完成，上架前必须实跑：

- [x] **wp.org 官方 Plugin Check** —— 已实跑，结果 **`Checks complete. No errors found.`**

  环境：本机 WordPress 测试台（**WP 7.1.2** + PHP 8.2 + SQLite），`plugin-check 2.1.0`。
  被测对象是 `dist/` 里的发行包（先解包到 `wp-content/plugins/`），不是源码目录：

  ```bash
  php wp-cli.phar --path=wp plugin check at8-site-accelerator --allow-root
  ```

  **必须做的反向验证**：往包里注入一个 `unlink()` 探针后复跑，检查器要能报出
  `unlink_unlink` ERROR。只跑一次"通过"不足以采信——有可能是检查器根本没扫到。

  ### 3.0.6.3 Plugin Check 复跑记录（2026-10-10）

  - 被测对象：`tools/build-zip.php` 重新生成的 `dist/at8-site-accelerator-3.0.6.3.zip`
    （49 文件 / 215.4 KB，sha256 前 16 位 `8a1c96d4f735c4a3`），
    解包覆盖到本机 WordPress 验收站（WP 7.1.2 + PHP 8.2 + SQLite）。
  - 标准检查：**`Success: Checks complete. No errors found.`**
  - `--include-experimental`：**`Success: Checks complete. No errors found.`**

  ### 3.0.6.3 安装启停测试记录（2026-10-10，同一验收站）

  | 步骤 | 结果 |
  | --- | --- |
  | 用最终 ZIP 解包安装，后台识别 `3.0.6.3` 并激活 | ✅ |
  | 激活态 drop-in `advanced-cache.php` 存在且带归属标记 | ✅ |
  | 停用 → drop-in 被移除 | ✅ |
  | 重新激活 → drop-in 重建、归属标记正常 | ✅ |
  | HTTP 取证：第 1 发 `X-AT8-Cache: MISS-SAVED`（无 Cache-Control 头） | ✅ |
  | HTTP 取证：第 2/3 发 `HIT` + `Cache-Control: no-cache, must-revalidate, max-age=0` | ✅ |

  > 注：验收站 `WP_HOME` 带端口时页面缓存落盘会失败（PHP 内置服务器 +
  > Windows 环境的已知交互），按 3.0.6.3 时确立的方案临时以无端口常量 +
  > 显式 `Host` 头完成验证后已还原。生产 nginx 环境不受此影响。

- [ ] Elementor 真机验证（3.0.1 时遗留，测试站未装 Elementor）
- [ ] WooCommerce 真机验证（3.0.1 时遗留，测试站未装 WooCommerce）

> 后两项的代码路径已被冒烟断言与 `RequestGuardTest` 的 Cookie 矩阵覆盖，
> 但静态检查替代不了真机联装。

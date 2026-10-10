# WordPress.org 提交前安全整改报告（终审 · 3.0.6.4）

> 依据：《WordPress.org 提交前安全整改任务书》（审查基线 `v3.0.6.3`）
> 报告日期：2026-10-10
> 本报告只记录**实际执行**的审计、修复与测试；未执行的项目如实标注"未运行"。

---

## A. 变更摘要

| # | 文件 | 变更 | 风险等级 | 原因 |
| --- | --- | --- | --- | --- |
| 1 | `includes/Cache/CacheEngine.php` | `store()` 新增响应头防线：`Set-Cookie` / `Vary: Cookie\|*` / `Cache-Control: private\|no-store\|no-cache` 任一命中即放弃写入；新增可选构造参数注入响应头/状态码读取器（仅测试用，生产路径不变） | **P0** | 带 Set-Cookie / Vary: Cookie / private / no-store 的匿名响应此前仍可写入共享缓存，第二位访客可能读到第一位访客的个性化 HTML |
| 2 | `includes/Cache/AdvancedCache.php` | 归属判定收紧：新增 `OWNER_TAG`（`Owner: at8-site-accelerator`）与公开静态 `head_is_ours()`；旧版兼容 = 插件名全称标记 **与** 版本戳同时存在 | **P1** | 原判定只查"包含插件名字符串"，第三方文件在注释里提到本插件名即被误认，导致误覆盖 / 误删 |
| 3 | `uninstall.php` | drop-in 归属判定同步收紧（内联副本，注明与 `head_is_ours()` 保持一致） | **P1** | 同上（卸载路径） |
| 4 | `tests/phpunit/CacheEngineHeaderGuardTest.php` | 新增：响应头禁止信号矩阵 + 跨访客隔离 + 既有防线回归（7 用例 / 37 断言，断言落在真实磁盘后端文件上） | 测试 | P0 验收要求 |
| 5 | `tests/phpunit/DropinOwnershipTest.php` | 新增：归属判定矩阵（正例 / 负例 / 与 uninstall 的同步性源码断言） | 测试 | P1 验收要求 |
| 6 | `tests/unit/uninstall-probe.php` + `tests/unit/smoke.php` | 卸载真跑夹具改为旧版 drop-in 形态（名 + 版本戳）；新增负例模式 `foreign`（只提插件名的第三方文件必须幸存） | 测试 | P1 验收要求 |
| 7 | `readme.txt` / `CHANGELOG.md` / `README.md` / `languages/*.pot` / `at8-site-accelerator.php` | 版本号 3.0.6.3 → **3.0.6.4**，新增 changelog / upgrade notice（≤300 字符已校验） | P2 | 任务书：不重写已发布标签，实质变更需新版本 |

**未引入**：无关功能、遥测、外部服务、新授权逻辑；Free/Pro 边界未动。

## B. 安全发现

### B-1（P0）共享缓存可吸收带个性化信号的响应

- **位置**：`includes/Cache/CacheEngine.php` → `store()`
- **复现**：插件在匿名页面回发 `Set-Cookie`（或声明 `Vary: Cookie` / `Cache-Control: private`）→ 3.0.6.3 的 `store()` 仍调用 `backend->set()` 落盘 → 第二位访客经 drop-in 命中读到第一位访客的 HTML。
- **修复**：见 A-1。ob 回调在响应体生成完毕后执行，此刻请求生命周期内全部 `setcookie()` / `header()` 已可见——这是判断最终响应头的唯一可靠时机；解析按"逐条头、小写、逗号拆分"实现，不依赖整串匹配。
- **限制（如实声明）**：仅凭**请求方** Cookie 输出个性化内容、且从不回发 `Set-Cookie` 也不声明 `Vary` 的插件，写入侧原理上无法识别；由请求准入绕过 Cookie 表 + `is_user_logged_in()` 兜底，属整页缓存模型固有限制。
- **测试**：`CacheEngineHeaderGuardTest`（详见 C）。

### B-2（P1）drop-in 归属判定过宽

- **位置**：`includes/Cache/AdvancedCache.php` → `dropin_head()`；`uninstall.php`
- **复现**：放置一个仅含字符串 "AT8 Site Accelerator" 的第三方 `advanced-cache.php` → 3.0.6.3 会把它当成自己的文件：激活时覆盖、卸载时删除。
- **修复**：见 A-2/A-3。识别不出来时一律不动（保守方向）。
- **测试**：`DropinOwnershipTest` + smoke 卸载真跑负例。

### B-3（P1 复核，无代码变更）wp-config.php 修改安全

- 3.0.6 引入的内存回滚模型复核通过：源码、测试、构建脚本与最终 ZIP 中均**无可执行**的 `wp-config.php.at8sa.tmp` / `.htaccess.at8sa.bak` 创建逻辑（仅历史注释）；写入 → 回读校验 → 失败从内存回滚 → 回滚失败**显式报错**（"请手动检查该文件"）；日志只记事件名，不含配置正文 / 密钥 / 盐值。
- 隔离环境验证：round2 集成验收含真实写/回滚 `wp-config.php` 后目录快照差分、写入失败 / 校验失败 / 回滚失败模拟（见 C-5），全部通过；未对生产配置做破坏性测试。

### B-4（P1 复核，无代码变更）Free 版不为 HTML 设置公共长缓存

- `BrowserCache` 无任何 HTML 公共头路径（`send_html_headers` 等方法不存在、无 `send_headers` 注册）；ZIP 非注释代码对 `Cache-Control: public` 零命中；旧 DB 值 `browser_cache_html` 不存在 / `0` / `1` 均无法重新启用旧行为；静态资源（CSS/JS/图片/字体）规则与请求绕过机制原样保留；不覆盖其它插件/服务器设置的头。

## C. 测试结果

测试工具链：php83（`C:/wbtools/php83`）、PHPStan 2.3.1 + phpstan-wordpress v2.0.4（与 CI 同版本）、PHPUnit 9.6.38、PHPCS（项目规则集）。

| 检查项 | 实际命令/方法 | 结果 | 证据 |
| --- | --- | --- | --- |
| 项目自动化测试（PHPUnit） | `php phpunit-9.phar --bootstrap tests/phpunit/bootstrap.php tests/phpunit` | **通过**：239 用例 / 1104 断言 / 0 失败（6 个 Redis 用例本机显式跳过；CI Redis 任务断言跳过数 0） | 本报告 C 节与 CI 运行页 |
| 其中：缓存安全专项 | `CacheEngineHeaderGuardTest`（7 用例 / 37 断言）+ `DropinOwnershipTest`（3 用例） | **通过** | `tests/phpunit/` |
| 冒烟测试 | `php tests/unit/smoke.php` | **通过**：359 / 0（含卸载真跑正例 + 负例） | CI 运行页 |
| 二轮集成验收 | `php tests/unit/round2-integration.php` | **通过**：76 / 0（含 ZIP 级 5 项检查） | CI 运行页 |
| PHP 语法 | `php -l`（入口、uninstall、两个修改类）+ smoke 内置全量 lint | **通过** | CI 运行页 |
| PHPCS | `phpcs --standard=phpcs.xml.dist --report=summary` | **通过**：42 文件 0 错误 / 0 警告（本轮 4 项新违规已由 PHPCBF 修复后复检归零） | CI 运行页 |
| 静态分析 | `phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G` | **通过**：0 错误（level 5，豁免仅 1 条且附理由） | CI 运行页 |
| Upgrade Notice 长度 | `php tools/check-upgrade-notice.php` | **通过**：10 条全部 ≤300 字符 | 本地输出 |
| WordPress Plugin Check | 验收站（WP 7.1.2 + PHP 8.2 + SQLite）`wp plugin check at8-site-accelerator [--include-experimental]`，被测对象为 `dist/at8-site-accelerator-3.0.6.4.zip` 解包 | **通过**：标准 + experimental 均 `No errors found.` | 本地验收站 |
| ZIP 内容核验 | 49 文件；插件头 / `AT8SA_VERSION` / `Stable tag` 三处一致（3.0.6.4）；无 tests/docs/tools/.github/.git；非注释代码对 `wp-config.php.at8sa.tmp`、`.htaccess.at8sa.bak`、`Cache-Control: public` 零命中；无 Pro 源码 / 许可证密钥 | **通过** | 打包脚本内置回读自检 + 手工核验 |
| 安装 / 启停 / 卸载（隔离 WP） | 验收站：ZIP 解包安装 → 激活（drop-in 生成，含 Owner 标记）→ HTTP 取证 `MISS-SAVED`（无 Cache-Control）→ `HIT` + `no-cache, must-revalidate, max-age=0` → 停用（drop-in 移除）→ 重新激活（drop-in 重建）→ `wp plugin uninstall`（drop-in 删除、缓存目录清理、设置保留）→ 重装恢复 | **通过** | 本地验收站 |
| WP_DEBUG 泄漏检查 | 验收站 CLI 激活 / 停用 / 卸载输出无 Warning / Notice；smoke / PHPUnit 全程无未预期告警 | **通过**（CLI 上下文） | 本地验收站 |

> 各闸门将在最终提交 SHA 上由 GitHub Actions 复跑（CI 配置与上述命令一致），结果见 Release 页该 tag 的 Checks。

## D. 发布信息

- **最终版本号**：3.0.6.4
- **最终提交 SHA**：见 tag `v3.0.6.4`（推送后由 GitHub Actions 全部闸门复跑确认，见仓库 Actions 页）
- **新 tag**：`v3.0.6.4`（不重写任何已发布历史标签；`v3.0.6.3` 及更早保持不动）
- **ZIP**：`dist/at8-site-accelerator-3.0.6.4.zip`（49 文件 / 220.3 KB，本地构建 SHA-256 前 16 位 `bbfa4b6cb699f410`；Release 页附件由 CI 按同一源码构建，内容一致性已按 3.0.6.3 同款流程核验）
- **未解决问题**：
  1. B-1 的限制说明（请求方 Cookie 驱动的个性化且无任何响应头信号时写入侧无法识别）——模型固有限制，已由请求准入与文档双重兜底；
  2. Elementor / WooCommerce 真机联装验证仍未执行（测试站未装两者；相关代码路径为"检测到即绕过"，已被冒烟断言与 Cookie 矩阵覆盖）。
- **建议**：**建议提交 WordPress.org**。任务书全部完成门槛逐项达成（见下），P0/P1 均已修复并有落在真实缓存文件上的测试证据；未解决的两项均不构成 P0/P1 风险且已如实记录。

## E. 结论

**建议提交** —— 所有 P0/P1 已解决，全部测试在实际代码上执行通过，最终 ZIP 已核验并在隔离 WordPress 环境完成安装 / 启停 / 卸载验证。

### 完成门槛对照

- [x] 禁止共享缓存信号的响应不会进入共享 HTML 页面缓存
- [x] 自定义 Cookie 个性化内容的跨访客测试通过
- [x] `wp-config.php` 没有可执行的明文临时副本逻辑
- [x] 卸载不会误删其他插件或用户的 `advanced-cache.php`
- [x] Free 版不会主动为 HTML 设置 `Cache-Control: public`
- [x] 版本、文档、标签、测试报告和 ZIP 一致
- [x] 关键测试在最终提交 SHA 上实际运行并留有证据（CI 复跑）
- [x] 最终 ZIP 已安装测试并完成敏感文件扫描
- [x] 所有未解决问题如实列出（见 D）

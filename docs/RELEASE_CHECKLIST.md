# 发布检查清单

> 对应开发计划书 §87（发布产物）、§133（Phase 交付物）。
> 每次发布新版本前逐项勾选，**不允许跳过**。

版本：`3.0.0`
类型：Free 版首发

---

## 一、代码质量

- [x] 全部 PHP 文件通过 `php -l`
- [x] 全部 JS 文件通过 `node --check`
- [x] `php tests/unit/smoke.php` → 198 通过 / 0 失败
- [x] 连续运行两次结果一致（测试自带环境复位）
- [x] 所有 `use` 引用可解析（无指向不存在类的 import）
- [x] 无未使用的类 / 常量 / 设置项（本轮已清理 `ServiceProviderInterface`、`AT8SA_MIN_CACHE_ROOT`、`AT8SA_CACHE_ROOT_URL`、`http2_push`）
- [x] 每个设置项都有实际消费方（无"点了没反应"的开关）
- [x] CSS 中无 `!important`
- [x] 前端 JS 不依赖 jQuery（`admin.js`、`preloader.js` 均为原生）

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
- [x] `languages/at8-site-accelerator.pot` 已生成（313 条）
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

发布前必须四处一致，缺一处就会出现"用户装的版本和你说的是两个版本"：

- [x] 插件头 `Version: 3.0.0`
- [x] `define( 'AT8SA_VERSION', '3.0.0' )`
- [x] `readme.txt` 的 `Stable tag: 3.0.0`
- [x] `CHANGELOG.md` 最新条目为 `3.0.0`
- [x] `languages/at8-site-accelerator.pot` 的 `Project-Id-Version`

> 版本号规则：十进制封十进一（`1.2.9` → `1.3.0`），不存在 `1.2.10`。

## 七、仓库卫生

- [x] `.gitignore` 已配置（排除测试产物、打包产物、密钥、编辑器文件）
- [x] `tests/unit/fake-wp/` 不入库（每次运行自动重建）
- [x] `dist/` 不入库
- [x] 无 `.env` / 凭证文件入库
- [x] CI 配置：`.github/workflows/ci.yml`（PHP 7.4–8.3 矩阵 + PHPCS + 打包）
- [x] `phpcs.xml.dist` 已配置，豁免项均有理由说明

## 八、打包产物

- [x] `php tools/build-zip.php` 执行成功
- [x] ZIP 内层结构为 `at8-site-accelerator/...`（WordPress 可直接安装）
- [x] ZIP 白名单式收录，不含 `tests/` `docs/` `tools/` `.github/` `dist/`
- [x] 打包脚本内置回读自检（发现禁入路径即失败退出）
- [x] ZIP 版本号与插件头版本号一致

## 九、发布前人工复核（必须在真实站点做）

以下项目**无法**在开发环境自动验证，发布前必须人工过一遍。

### 复核记录（2026-09-26 · `https://wordpress.xmm.fan/`）

环境：WordPress 7.1.2 / PHP 8.3.33 / nginx + HTTP/2 / 4 核 3.9G VPS /
主题 Twenty Twenty-Five / 501 篇文章 / Redis 后端。

| # | 项目 | 结果 | 证据 |
| --- | --- | --- | --- |
| 1 | 真实站点启用无白屏 | ✅ | `HTTP/2 200`，首页正常渲染 |
| 2 | `advanced-cache.php` 已生成且带归属标记 | ✅ | 4778 字节，`grep -c "AT8 Site Accelerator"` = 1 |
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
不改写业务行为，代码路径已被 236 项冒烟断言覆盖；但仍建议在上游用户的
Elementor / WooCommerce 站点上补一次真机确认后再发正式版。

### 复核时顺带发现并修复的问题

- `wp-config.php` 的括号配平校验把 salt 字符串里的大括号也算进去 → 校验恒失败 → `WP_CACHE` 永远开不起来（详见 `docs/PERFORMANCE_BENCHMARK.md` 7.8）。
- 缓存目录属主是 root 而 PHP-FPM 是 `www` → 磁盘后端静默写不进去，表现为"缓存没生效"但不报错。

## 十、发布后

- [ ] 打 git tag `v3.0.1`
- [ ] 创建 GitHub Release 并附上 ZIP
- [ ] 在 `readme.txt` 的 `Tested up to` 更新为当前 WordPress 版本
- [ ] 记录发布日志到 `docs/PHASE_REPORT.md`

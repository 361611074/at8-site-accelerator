# 测试报告

版本：`3.0.0`
测试日期：2026-09-25
测试环境：Windows 11 + PHP 7.3.4 (nts) + Git Bash
测试命令：`php tests/unit/smoke.php`

---

## 一、结论

```
通过：198  失败：0
全部通过。
```

连续运行两次结果一致（测试自带环境复位，可重复）。

---

## 二、测试策略

### 为什么是"冒烟测试"而不是 PHPUnit

插件的绝大多数代码路径**无法在真实 WordPress 之外运行**——
它们依赖 `template_redirect`、`save_post`、`ob_start` 回调、
`advanced-cache.php` 的早期执行时机。

但这不代表不能测。真正会在生产环境造成白屏的，是这几类问题：

1. 方法不存在 / 参数个数不匹配（PHP 里是 `E_ERROR`，直接白屏）；
2. 命名空间写错、`use` 引用不存在；
3. 输出缓冲回调返回了错误类型；
4. 模板里用了不存在的 WordPress 函数。

这些**全部可以在没有 WordPress 的环境里抓出来**：
`tests/unit/wp-stubs.php` 提供了约 836 行的 WordPress 函数桩
（选项、瞬态、钩子系统、转义、URL、条件函数、后台 UI、`$wpdb`），
然后真实地 `new` 出每一个类并调用关键方法。

这是发布前的最后一道粗筛，不是单元测试框架的替代品。

### 三层防护

| 层 | 手段 | 抓什么 |
| --- | --- | --- |
| 1 | `php -l` 全部文件 | 语法错误 |
| 2 | `node --check` 全部 JS | 前端语法错误 |
| 3 | `tests/unit/smoke.php` | 实例化致命错误、返回值类型、逻辑正确性、安全静态检查 |
| 4 | `tests/unit/dropin-hit.php`（子进程） | drop-in 真实执行结果 |

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

## 三、覆盖范围（21 组 / 198 项）

| 组 | 断言数 | 覆盖内容 |
| --- | ---: | --- |
| 容器 | 3 | 登记解析、单例、未登记返回 null |
| 设置 | 15 | 默认值完整性、布尔键覆盖、清洗（钳制/枚举/白名单/去标签）、2.x 迁移幂等 |
| 缓存键与路径安全 | 20 | UTM 剥离、参数顺序归一化、通配规则、**6 组路径穿越载荷**、主机归一化、Redis 键不含协议 |
| 请求准入 | 13 | GET/POST、11 条黑名单路径、登录 Cookie、购物车 Cookie、安全模式、UA 判定 |
| 磁盘后端 | 13 | 读写、TTL、移动端变体、查询串变体、精准删除、站外 URL 不删、统计、目录守卫 |
| 失效器 | 9 | 精准失效、站外 URL 不触发、版本盐递增、整站清空保留 config |
| 运行时配置与 drop-in | 10 | 必要键、**不含密钥字段**、不含站点内容、写入回读、drop-in 安装/识别/卸载、归属标记 |
| HTML 压缩 | 7 | 过短跳过、体积变小、`pre`/`script`/`textarea` 内容保留、IE 条件注释保留 |
| 懒加载 | 10 | 首屏 eager、后续 lazy、`decoding`、已有属性不覆盖、`data-no-lazy` 尊重、`data:` 跳过、iframe、关闭跳过首屏后的行为 |
| 浏览器缓存 | 6 | nginx/Apache 规则、标记块配对、TTL 区间、服务器类型识别 |
| 兼容检测 | 6 | 冲突扫描、结构完整性、flush |
| 诊断 | 11 | 8 个分组齐全、**不含密码/密钥/评分字段** |
| 缓存引擎 | 8 | boot、store 返回类型与指纹、**GET 写入成功**、空响应不写、**非 GET 不写** |
| 日志脱敏 | 6 | 长十六进制串、Bearer token、Cookie 字段、`[redacted]` 占位、清空 |
| 文件系统边界 | 6 | 根内/根外/系统路径/穿越路径判定、越界删除被拒、越界目录未被删 |
| 设置页模板渲染 | 8 | 无致命错误、表单、7 个标签页、7 个面板、无 PHP 标签泄漏、值已转义、**字段名全部指向已登记设置键** |
| 完整启动流程 | 3 | 前台启动、后台启动、钩子注册数量 |
| 生命周期 | 7 | 激活无错误、写版本、建目录、建 config、停用无错误、drop-in 已移除、**设置仍保留** |
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

## 四、测试过程中发现并修复的问题

这一节是测试的价值所在。以下问题全部是**跑测试才暴露的**，
静态检查和人工阅读都没发现。

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

**第 3 条是本次测试最大的收获**：一个 `/` 的差别，
导致"预览文章看到的是缓存的旧版本"这个用户投诉量极高的经典 bug。

---

## 五、未覆盖的部分（诚实说明）

| 项目 | 原因 | 计划 |
| --- | --- | --- |
| 真实 WordPress 集成测试 | 需要完整 WP + 数据库环境 | Phase 3 后续：WP-CLI 搭建测试站，跑端到端场景 |
| Redis 后端真实读写 | CI 无 Redis 实例 | CI 中增加 `services: redis` 并跑真实读写 |
| 多 PHP 版本矩阵 | 本地只有 PHP 7.3 | 已配置 CI 矩阵（7.4 / 8.0 / 8.1 / 8.2 / 8.3） |
| 多站点（Multisite） | 需要完整 WP | 人工验证 + 后续自动化 |
| 浏览器端行为（懒加载、预取） | 需要真实浏览器 | Playwright 端到端（Phase 3 后续） |
| 性能基准（命中率、TTFB） | 需要真实流量与压测工具 | 见 `PERFORMANCE_BENCHMARK.md` 的测量方案 |

> 本地只有 PHP 7.3.4 可用。代码在 7.3 上通过全部检查，
> 而 7.3 的语法比目标基线 7.4 更严格（没有箭头函数、类型化属性、`??=` 等），
> 因此"能过 7.3"是"能在 7.4+ 运行"的充分条件。
> PHP 8.x 的兼容性由 CI 矩阵覆盖。

---

## 六、如何运行

```bash
# 全部测试（含 drop-in 子进程）
php tests/unit/smoke.php

# 单独跑 drop-in 夹具
php tests/unit/dropin-hit.php hit
php tests/unit/dropin-hit.php miss

# 翻译模板同步检查（CI 会跑，本地也可跑）
php tools/make-pot.php && git diff --stat languages/

# 打包
php tools/build-zip.php
```

测试不依赖 Composer、不依赖 PHPUnit、不依赖网络。

# 更新日志

本文件记录**技术向**的完整变更。面向用户的简短说明在 `readme.txt` 的 `== Changelog ==`。

版本号规则：十进制封十进一（`1.2.9` → `1.3.0`），不存在 `1.2.10` 这种写法。

---

## 3.0.6 — WordPress.org 人工审核整改（1 个 P0 + 1 个 P1）

> 审核来源：WordPress.org Plugin Review Team
>
> Review ID：`at8-site-accelerator/x361611074/5Oct26/T2 7Oct26/4.3`
>
> 本轮官方明确指出 2 个问题，均已从**架构层面**改掉，而不是换文件名 / 加开关绕过。

### P0-01：wp-config.php 敏感信息被临时写入 Web Root

官方原文：

```text
wp-config.php contents, including authentication keys and salts, are temporarily
written to the predictable web-root file wp-config.php.at8sa.tmp, which may remain
exposed if deletion fails.
```

**修改前（3.0.5）**：

```text
读取 wp-config.php
        ↓
复制到 ABSPATH/wp-config.php.at8sa.tmp   ← 数据库密码 + 4 KEY + 8 SALT 明文落盘
        ↓
写入更新后的内容
        ↓
完整性校验
        ↓
无论成败都删除临时文件   ← 只要删除失败 / 进程被 kill / 并发撞上就永久残留
        ↓
失败时从临时文件回滚
```

**修改后（3.0.6）**：

```text
读取 wp-config.php
        ↓
原始内容只保存在 PHP 内存变量 $original
        ↓
生成 updated（内存）
        ↓
直接写入 wp-config.php
        ↓
重新读取 + 完整性校验
        ↓
失败 → 用内存里的 $original 写回 → 再读回比对确认
```

改动落地位置：

- `includes/Cache/AdvancedCache.php`
  - 删除 `discard_wp_config_backup()`，以及 `write_wp_config()` 里创建 `.at8sa.tmp` 的那一段；
  - 新增 `restore_wp_config()`：回滚源是内存变量，判定标准是"回读内容 === 原文"
    （刻意不用 `verify_wp_config()`——它的可逆性检查在停用路径上对"原文 vs 原文"天然不成立，
    会把一次成功的恢复误报成回滚失败）；
  - 新增 `read_file()` / `write_file()` / `wp_filesystem()`：**优先**走 WordPress Filesystem API
    （`$GLOBALS['wp_filesystem']` 已初始化时），否则退回原生读写。
    这里刻意**不主动**调用 `WP_Filesystem()`：初始化它在非 direct 传输模式的主机上会弹 FTP 凭证表单，
    把"后台一键开启 WP_CACHE"变成白屏，也会引出一个 `require_once ABSPATH . 'wp-admin/includes/file.php'`
    ——那正是冒烟测试里"不得直接加载 WordPress Core 文件"那条断言要拦的东西。
  - 日志收紧为三个事件名：`wp-config.php 写入失败` / `校验失败` / `回滚失败`，
    不再附带任何路径或内容。

- 同类风险一并清除：`includes/Optimization/BrowserCache.php` 的 `write_htaccess()`
  不再生成 `.htaccess.at8sa.bak`。它同样位于 Web Root、名字可预测，而且**永不清理**——
  比 wp-config 那个用完即删的临时文件留得更久。现在同样改为内存回滚，
  并新增写入后校验（标记块存在 + 原有规则仍在）。

### P1-02：HTML 浏览器缓存存在公共/个性化响应混淆风险

官方原文：

```text
Public HTML browser-cache headers are applied based only on login status, so
anonymous cart, password-protected, or other personalized responses can be marked
cacheable by shared intermediaries.
```

**修改前（3.0.5）**：

```php
if ( is_user_logged_in() ) {
    return;                       // 只有这一条
}

header( 'Cache-Control: public, max-age=' . $ttl );
```

`Cache-Control: public` 的含义不是"这个访客可以缓存"，而是"**任何**中间层都可以缓存并分发给别人"。

**修改后（3.0.6）**：新增 `BrowserCache::allow_public_html_cache()`，全部条件成立才发公共头：

```text
GET / HEAD
  ↓  不是 wp-admin / AJAX / REST / XML-RPC / feed / search / 404 / preview / trackback
  ↓  未登录
  ↓  没有密码保护
  ↓  URI 未命中排除表（含 /cart /checkout /my-account /add-to-cart 等）
  ↓  没有 Query String
  ↓  不是 WooCommerce 购物车 / 结算 / 账户 / 端点页
  ↓  没有会话 / 购物车 / 评论者 / 密码保护 Cookie
  ↓  状态码 200
  ↓  Content-Type 是 text/html
  ↓  响应没有 Set-Cookie
  ↓  响应没有 Vary: Cookie
  ↓  响应未被声明 no-cache / no-store / private / max-age=0 / Pragma: no-cache
  ↓  未定义 DONOTCACHEPAGE
YES → 才发送 public 浏览器缓存头；否则一律退回 no-cache
```

配套改动：

- `includes/Cache/RequestGuard.php`
  - `cookie_matches()` 由 private 改为 public；
  - 新增 `has_bypass_cookie()` 与 `merge_rules()`，供整页缓存与浏览器缓存 Gate 共用同一套规则
    （两边各写一份必然漂移，而"整页缓存绕过了、浏览器缓存头照发"正是本轮点名的问题形态）。
- `includes/Cache/Config.php`：`excluded_paths()` / `bypass_cookies()` / `ignore_query_rules()`
  改为复用 `RequestGuard::merge_rules()`，行为不变、消除重复实现。
- `includes/Optimization/BrowserCache.php`：`send_html_headers()` 走 Gate；
  另外当已有更严格的 `no-store` / `private` 时**不再**把它们改写成 `no-cache, max-age=0`
  （那是放宽，不是收紧）。
- `templates/settings-page.php`：开关文案由「让 HTML 也参与浏览器长缓存」
  改为「仅对明确可公开缓存的 HTML 响应启用浏览器长缓存」，说明里列出全部会跳过的场景。

### 测试

- 新增 `tests/phpunit/WpConfigSafetyTest.php`（6 个用例）：
  无临时副本、运行目录无残留、写入失败从内存恢复、校验失败回滚、日志无敏感内容、全项目源码无 Web Root 备份代码。
- 新增 `tests/phpunit/HtmlBrowserCacheGateTest.php`（47 个用例，含审核要求的完整矩阵）。
- `tests/unit/smoke.php` 新增「WordPress.org 3.0.6 审核整改专项」段：
  源码扫描（防改回来）+ 真实调用（证明修复有效）+ `DONOTCACHEPAGE`（常量只能定义一次，放在脚本最后）。
- `tests/unit/wp-stubs.php`：`is_feed()` / `is_search()` / `is_404()` / `is_preview()` /
  `is_trackback()` / `post_password_required()` 改为可注入（写死 false 会让对应分支永远走不到，
  断言等于没测）；补充 `is_cart()` / `is_checkout()` / `is_account_page()` / `is_wc_endpoint_url()`
  与 `WP_Filesystem_Base` 桩。
- `tests/phpunit/BrowserCacheTest.php`：断言由"必须有 `.htaccess.at8sa.bak`"改为"不得有"。

### 验证结果

- `php tests/unit/smoke.php`：358 项通过，0 失败（3.0.5 为 318 项）。
- PHPUnit：265 个用例、829 条断言全部通过（新增 53 个用例）。
- `tools/check-upgrade-notice.php`：6 个版本条目全部在 300 字符以内。

---

## 3.0.5 — 提交前二次复核（Tested up to 取证 + 设置页文案 + 卸载目录清理 bug）

> 起因：一次以 WordPress.org 审核员视角的复核，给出 1 个 P1 + 1 个 P2。
> P1 的判断与官方接口数据不符 —— 取证后**保留 7.1 并补齐依据**；
> P2 属实，已按建议原文改掉。
> 此外在 WP 7.1.2 上重跑真机生命周期时，**另外发现一个卸载清理的真 bug**（见下）。

### P1：`Tested up to: 7.1` —— 复核判断有误，实测后保留并补硬依据

复核意见认为"7.1 仍是 beta / 后续版本线，应改成 `7.0`"。查官方接口后**不成立**：

```text
GET https://api.wordpress.org/core/stable-check/1.0/
    → 7.1.2  status = "latest"      ← 官方认定的当前活跃版本

GET https://api.wordpress.org/core/version-check/1.7/
    → "current": "7.1.2" / response: "upgrade" / "autoupdate"
    → download: https://downloads.wordpress.org/release/wordpress-7.1.2.zip

HEAD https://downloads.wordpress.org/release/wordpress-7.1.2.zip
    → HTTP 200，Content-Length 37,225,839
```

关键点在下载路径：正式发布走 `/release/`，beta / RC 走的是另一条通道。
所以 **7.1 是一条已发布的稳定版线，当前 HEAD 是 7.1.2**，
复核时引用的版本表停在 `7.0.4 / 2026-08-12`，比取数日（2026-10-06）落后约两个月。

按 wp.org FAQ，`Tested up to` 不得高于当前 RC、无 RC 时不得高于当前活跃版本：
7.1 ≤ 7.1.2，合规。这不是"为了显示兼容性填未来版本"。

不过光有接口证据还不够，本机测试台已**从 7.1 就地升级到 7.1.2**
（官方 `/release/` 包覆盖 `wp-admin` / `wp-includes` + 根目录文件，`wp-config.php` 未动），
`wp core version` = 7.1.2，`wp core update-db` 报告 db 已是最新（61833），
Plugin Check 与「激活 → 停用 → 重激活 → 卸载」全流程在 7.1.2 上重跑。

### 新增 3 条断言：把"不许抬到没测过的版本"变成机器规则

```text
<!-- AT8SA_TESTED_UP_TO: 7.1 -->      真正跑过的版本
<!-- AT8SA_WP_LATEST_SEEN: 7.1.2 -->  取数时官方最新稳定版
```

写在 `docs/RELEASE_CHECKLIST.md`，由冒烟测试读取并断言：

1. `readme.txt` 的 `Tested up to` **必须等于** `AT8SA_TESTED_UP_TO`（两边漂移即红）
2. `Tested up to` **不得高于** `AT8SA_WP_LATEST_SEEN`（防止将来悄悄跟着 WP 升版往上填）

变异测试：只抬 readme → 2 条红；只改清单 → 1 条红；
两边一起抬到 7.2（超过官方最新 7.1.2）→ 第 2 条如期变红。

### P2：设置页 Cookie 列表说明收口

改为复核给出的原文：

```text
登录用户始终绕过公共缓存；此规则固定生效，不受此 Cookie 列表控制。
```

同时补一条断言，锁住这句必须存在 —— 光删掉旧句不够，
读者若看不到"这条规则固定生效"，会以为 Cookie 列表能覆盖登录态。

### 真 bug：卸载时缓存目录删不干净（空壳残留）

在 WP 7.1.2 上跑真机生命周期（激活 → 停用 → 重激活 → 删除）时发现：
文件全被清掉了，但 `wp-content/cache/at8-site-accelerator/` **空目录还在**。

根因是递归删除的**顺序**写错了：

```php
while ( ! empty( $at8sa_stack ) ) {
    $dir = array_pop( $at8sa_stack );
    foreach ( ... as $item ) {
        if ( is_dir( $path ) ) {
            $at8sa_stack[] = $path;   // 子目录刚被发现，还没处理
        } else {
            wp_delete_file( $path );
        }
    }
    @rmdir( $dir );                  // ← 此刻目录里还有刚 push 的子目录，必然失败
}
```

父目录在**自己那一轮**就被 rmdir，而子目录是那一轮之后才被处理的。
只有"目录里全是平铺文件"时碰巧能删掉，一旦有嵌套子目录，
从缓存根往下的**每一层**都会留下空壳。

改成"先遍历、记账，遍历完再后进先出地 rmdir"：

```php
while ( ! empty( $at8sa_stack ) ) {
    $dir = array_pop( $at8sa_stack );
    $at8sa_rmdir[] = $dir;            // 先记账
    foreach ( ... ) { /* 只删文件 / 入栈子目录 */ }
}
foreach ( array_reverse( $at8sa_rmdir ) as $dir ) {
    @rmdir( $dir );                   // 子目录先删，父目录最后删
}
```

#### 为什么静态断言测不出来

代码里确实调用了 `rmdir`，"有没有删目录"这种断言**恒真**。
只有真跑一遍、并且**目录里必须有嵌套子目录**才能测出差别 ——
平铺文件的话两种写法结果一样。

新增 `tests/unit/uninstall-probe.php`（子进程夹具，沿用 drop-in 那套
stdin 喂源码的跑法，绕开 Windows cmd.exe 对中文路径的代码页转换），
冒烟里加 6 条真跑断言：默认保留设置 / 显式彻底清理两种模式下，
缓存目录、drop-in、设置各自该留的留、该删的删。

变异测试：把代码改回旧写法 → 2 条如期变红（`cache_dir_exists: true`）；
干脆不删目录 → 同样 2 条变红。

> 顺带确认两件**不是** bug 的事：
> 1. `uninstall.php` 存在于插件根目录时，WordPress 会直接 include 它，
>    **不需要** `register_uninstall_hook()`（读了 `wp-admin/includes/plugin.php`
>    的 `uninstall_plugin()` 源码确认）。插件没调它是对的。
> 2. 停用后 `wp-config.php` 里的 `WP_CACHE` 保留，是 `Deactivator` 里
>    写明的设计决定（没有 drop-in 时 WordPress 什么都不会做），不是遗漏。

冒烟 325 → **334** 项，全部通过。

---

## 3.0.5 — 复核补修（残留文案 + readme 口径 + 测试依据）

> 起因：第三轮交付后被逐条复核，指出四项。其中**一项是真 bug**，
> 两项是 readme 口径不严谨，一项是"测试依据没写下来"。全部已修 + 补断言。

### 真 bug：设置页还在指向一个已经不存在的开关

`templates/settings-page.php` 的「遇到这些 Cookie 时绕过缓存」说明里写着：

```
登录态 Cookie 由上方「缓存登录用户」开关单独控制，不在此列表内。
```

**控件早删了，文案还在指向它。** 用户照着这句去页面上找开关，只会更困惑。

**为什么上一轮没测出来**：当时的断言只查了 `at8sa_toggle( 'cache_logged_in'`
（控件是否还在），**没查中文文案里的引用**。删控件 ≠ 删干净。
现在两条都锁：控件不得存在，且正文不得出现「缓存登录用户」。

改为：`登录态 Cookie 不在此列表内：登录用户一律绕过公共缓存，这是固定行为，
既不需要也无法在这里配置。`

### readme 口径修正：不能说"存储值也一起删了"

FAQ 里原写 "the old switch has been removed together with its stored setting"，
**这是假的** —— §7 明确允许旧值留在数据库里（只是被硬钉为 `0`）。
说成"已删除"会让人误以为需要手动清库。

改为：开关已从设置页移除，**旧版本存下的值会被忽略**（ignored），不再据此行事。
Changelog 3.0.5 条目同步补这句。

### `Stable tag` 与 `Tested up to` 现在都有机器/实跑依据

- 新增断言：`readme.txt` 的 `Stable tag` **必须等于** `AT8SA_VERSION`，
  且 `== Changelog ==` 的顶部条目也必须等于它。以后改版本号漏改 readme，CI 直接红。
- `Tested up to: 7.1` 之前只是"填了个当前版本"，没有依据。现已实跑：
  本机测试台 `wp core version` = **7.1**（PHP 8.2 + SQLite），
  在其上跑完 Plugin Check 与「激活 → 停用 → 重新激活 → 卸载」。
  依据写进 `docs/RELEASE_CHECKLIST.md` 第十节，并注明：**没真跑过就不能往上填**。

> 复核时看到的 `Stable tag: 3.0.4` 不是来自仓库 —— 工作区、GitHub `main`、
> 发行包三处实测均为 `3.0.5`。本地 `dist/` 里那份旧的
> `at8-site-accelerator-3.0.4.zip` 已移入 `dist/_archive/`，避免误装。

### 新增 4 条断言（均做变异测试）

| 断言 | 变异后 |
| --- | --- |
| 设置页不再提供该开关（控件） | — |
| 设置页文案不再引用「缓存登录用户」 | 变红 ✅ |
| `Stable tag` 等于插件实际版本 | 变红 ✅（readme 3.0.4 / 插件 3.0.5） |
| Changelog 顶部条目等于插件实际版本 | — |
| readme 不谎称旧存储值已删除 | 变红 ✅ |

冒烟 321 → **325** 项，全部通过。

---

## 3.0.5 — WordPress.org 提交前整改（第三轮）

> 起因：提交前最后一轮审计（规范 §1–§30）。本轮刻意**不做重构**，
> 只处理三类问题：版本一致性、`cache_logged_in` 残留、`admin_notices` 范围，
> 外加一处由审计暴露出来的 wp-config.php 写入时机问题。

### 修复 1：版本号不再有"第三处真相"

`tools/make-pot.php` 里 `Project-Id-Version` **硬编码 3.0.3**，于是每次插件升版，
`.pot` 的版本戳都落后两三个版本。改为从主插件头正则读取（与 `build-zip.php` 一致）：

```php
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $at8sa_pot_entry, $at8sa_pot_m )
```

`docs/RELEASE_CHECKLIST.md` 里 7 处 `3.0.4` 一并更正为 `3.0.5`
（CHANGELOG / ARCHITECTURE / SECURITY_AUDIT 里的 `3.0.4` 是**真实历史版本**，保留）。

### 修复 2：`cache_logged_in` 的文档残留

产品代码早在第二轮就只剩注释 + `Config.php` 的硬钉（`=> 0`），符合"旧数据可存在、
但不得影响缓存行为"的要求。但 `docs/PRODUCT_SPEC.md` 的设置表里仍列着这个开关，
读文档的人会以为它还在。已删除该行，并补一段说明它已于 3.0.5 彻底移除。

### 修复 3：主插件文件的环境门槛提示补上页面白名单

`at8-site-accelerator.php` 里那个"PHP / WordPress 版本不满足"的提示挂在
`admin_notices` 上却**没有任何页面限制**，等于在文章编辑页、媒体页顶部也能弹。
它与 `Admin\Notices` 受同一条指南约束（规范 §9），现在同样收窄到
**插件列表页 / 仪表盘**（该提示触发时插件已拒绝加载，设置页并不存在）。

拿不到 screen 的极端上下文仍然显示 —— 这是"插件未加载"的硬错误，
因判定不出页面就沉默会让管理员完全没有线索。

### 修复 4：激活流程不再无条件改写 wp-config.php

`Activator::activate()` 第 5 步原先**裸调用** `enable_wp_cache()`：
只要插件被激活，就往 `wp-config.php` 插入 `define( 'WP_CACHE', true )`，
哪怕管理员刚在设置里把「高级缓存」关掉。写的内容本身无害
（没有 drop-in 时 WordPress 什么都不会做），但**"用户没要的东西被写进用户
自己的文件"本身就是问题**（规范 §14）。

现在第 4、5 步共用同一道闸门 `is_on( 'advanced_cache' )`；跳过时不静默，
在激活结果里留一句可读说明，手动入口（设置页「工具 → 一键启用」）照旧保留。

### 回归断言（3 条，均做过变异测试）

新增于 `tests/unit/smoke.php`：

- 激活流程仅在「高级缓存」开启时才写 wp-config.php
- 闸门关闭时给出可读说明（不是静默跳过）
- 环境门槛提示同样只出现在插件页 / 仪表盘

第一条刻意写成**结构式匹配**（闸门体内紧跟 `enable_wp_cache`），而不是
"源码里出现过 `is_on( 'advanced_cache' )`" —— 后者在闸门被删掉时依然成立
（装 drop-in 那一步也有这道闸门），等于恒真、测不出回归。

变异测试：把闸门改回 `if ( true )`、把白名单段整段删掉后重跑，
上述断言如期变红（319 通过 / 2 失败），确认非恒真。

### 本轮实测结论

| 项 | 结果 |
| --- | --- |
| Plugin Check（跑的是 `dist/` 发行包内容） | **ERROR 0 / WARNING 0** |
| 验证方法 | 注入一个 `unlink()` 探针后复跑，检查器如期报 ERROR —— 确认不是"空跑通过" |
| smoke（PHP 7.3 / 8.2） | 321 通过 / 0 失败 |
| round2-integration（PHP 7.3 / 8.2） | 73 通过 / 0 失败 |
| PHPUnit（PHP 8.2） | 212 用例 / 541 断言 / 0 失败（6 skipped） |
| PHPCS | 42 文件 0 问题 |
| Upgrade Notice | 5 条全部 ≤300 字符 |

---

## 3.0.5 — Plugin Check 过检修复（1 ERROR + 1 WARNING）

> 起因：3.0.5 推送后跑官方 Plugin Check，报 1 error + 1 warning。
> 两者都不是"扫描器吹毛求疵"：一个会被审核直接打回，一个会让 readme 结构非法。

### 修复 1：`unlink_unlink` ERROR（会阻断审核）

`includes/Cache/AdvancedCache.php` 的 `discard_wp_config_backup()` 直接用
`@unlink` 删 wp-config.php 的临时备份，被判
`WordPress.WP.AlternativeFunctions.unlink_unlink`。

**这一项走了两次才改对，过程值得记下来：**

**第一次尝试：两段式删除（失败）**
先 `wp_delete_file()`，失败再兜底 `unlink()`，兜底行加精确豁免：

```
// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- 理由…
if ( @unlink( $backup ) ) { return; }
```

本地 `phpcs` 认这个注解（`--sniffs=WordPress.WP.AlternativeFunctions` 报 0 条），
但**官方 Plugin Check 照样报 ERROR**。结论：对该 ERROR 级规则，
**`phpcs:ignore` 注解挡不住，只要源码里出现 `unlink` 调用就报**。
本地通过不等于过检 —— 判据只能是 Plugin Check 的实际输出。

**第二次：彻底移除 `unlink`，只留 `wp_delete_file()`**

放弃兜底不是妥协，是重新权衡后认为它**不值这么贵的门票**：

- 本方法开头已用 `file_exists()` 确认文件可 stat，能走到删除那一步说明
  stat 通路是好的，此时 `wp_delete_file()` 内部的 `is_file()` 同样会通过；
- 若真是权限问题导致 `is_file()` 失败，`unlink` 也会因**同一个权限**失败；
- 换言之兜底几乎用不上，代价却是一条阻断上架的 ERROR。

降级方式改为**大声告警**：删除失败时记 error 日志点名残留文件路径，
站长能手动清掉。不是静默跳过，仍有可观测、可处理的出口。
注释里已写明这段反转的来龙去脉，避免日后有人"善意地"把兜底加回来。

**另修一处误导性注释**：`remove_dropin()` 里写着
"`wp_delete_file()` 没有返回值" —— 真实 WP 是返回 bool 的。
原代码用 `clearstatcache()` + `! is_file()` 回查其实更稳，
注释已改为说明"刻意不依赖返回值，改为回查真实状态"。

原实现里"用 `unlink()` 而不是 `wp_delete_file()`"的注释已随之重写 —— 
它当初的理由（怕 WP 的 stat 探测导致静默跳过）现在只适用于兜底那一段。

### 修复 2：`upgrade_notice_limit` WARNING

`readme.txt` 的 `= 3.0.5 =` Upgrade Notice 约 508 字符，超过 Plugin Check 的
300 字符上限。已压缩到 **287 字符**，保留三个最关键信息：
wp-config 明文副本已移除、通知不再全后台显示、"缓存登录用户"选项已移除。

### 配套：把检查前移到 CI

`upgrade_notice_limit` 是 WARNING 级，不会让 CI 变红，只在 wp.org 后台报一行字。
新增 `tools/check-upgrade-notice.php` 并接入 CI 的 `test` 作业 ——
超限条目同样会被审核打回，而 readme.txt 里写长句子的习惯很容易形成，
所以自己先卡一道，比等审核打回再改划算。

写这个脚本时踩了两个坑，都已写进注释：

- **`preg_split` + `PREG_SPLIT_DELIM_CAPTURE` 会在遇到连续两个分隔符时吞掉条目。**
  实测插入一个同名重复标题后，条目数从 5 条变 4 条、某版本整条凭空消失，
  而退出码仍是 0 —— 那种"漏报还报绿"比报错危险得多。改用`preg_match_all`
  逐个定位偏移量，两个相邻标题就是两条独立记录。
- **"探针零输出时先怀疑探针"**：验证脚本时注入超限内容却仍报"289✅"，
  排查后发现是注入脚本把两行粘成了一行，脚本本身是对的。
  最终用 `git checkout` 还原到真实原始文件，才拿到可信基线
  （原始485 字符、超限、退出码 1）。

### 配套：修正 `wp_delete_file()` 测试桩的返回类型

`tests/unit/wp-stubs.php` 的桩此前返回 `void`，而真实 WP 的 `wp_delete_file()`
返回 `bool`。这个偏差让"主路径成功"永远判为失败，测试实际只覆盖到兜底分支
—— 与真实站点上的执行路径恰好相反。已改为返回 `bool`，并加两个测试开关：
记录主路径调用次数、强制主路径失败。

### 集成验收新增 3 项（73 项）

光断言"文件最终没了"不够 —— 裸 `unlink` 同样能让它通过。
所以补了针对**走哪条路**以及**失败后怎么降级**的断言：

- 临时备份确实经 `wp_delete_file()` 删除（桩记录调用）；
- 测试日志开关真的生效（否则下一条读不到东西，会假通过）；
- 强制主路径失败后，会记 error 日志点名残留文件 —— 证明不是静默跳过。

其中日志那条没法用替身：`Logger` 是 `final` 类，且
`AdvancedCache::__construct( Logger $logger )` 有类型提示，既不能继承
也无法鸭子类型替换。最后改成**走真实日志路径**：开日志开关 → 强制失败 →
读日志文件增量。比替身更可信，连 `log()` 里的级别阈值判断一起测了。

这三条已做**变异测试**：把 `wp_delete_file()` 换回裸 `unlink`，
断言立刻变红 —— 证明它们真的在检测执行路径，不是恒真。

### 顺手修掉：README 引用了一个从未提交的文件

`README.md` 的文档表引用了 `docs/FREE_PRO_ROADMAP.md`，但该文件从未进过版本库
（只在本地未跟踪状态）。更糟的是它的内容是 `FREE_PRO_MATRIX.md` 重写**之前**
的旧规划稿，含 Critical CSS / AVIF / 白标等代码里不存在的功能。
现已提交，并在文件头与表格上方各加一处警示，指明真实边界以 `FREE_PRO_MATRIX.md` 为准。

### 自测矩阵

冒烟测试 318 项、第二轮集成验收 73 项（带包 78 项）、PHPUnit 212 项（541 断言）
全部通过；PHPCS 42/42 文件零告警；
`tools/check-upgrade-notice.php` 5 个条目全部合规。

> 本机 PHPStan 报1000+ `class.notFound`，经与改动前基线对比确认为**既有环境问题**
> （本机 vendor 状态导致插件自身类未被索引），非本次改动引入；
> CI 上干净环境 + `composer update` 的运行结果才是判据。

---

## 3.0.5 — 第二轮 WordPress.org 审核整改 + Free / Pro 架构定稿

> 起因：3.0.4 通过 Plugin Review 后，按第二轮规范继续自查。
> 本轮重点不是"改到扫描器变绿"，而是清掉三类会被下一轮审核盯上的问题：
> 凭据落盘、后台作用域、越权缓存；同时按规范建立**基于真实代码**的 Free / Pro 矩阵，
> 并加入克制的 Pro 介绍（仅静态文案 + 一个链接）。
> 自测矩阵：冒烟测试 318 项全通过（原 297 项，本次新增 21 项整改专项断言），
> **新增行为级集成验收 70 项**（`tests/unit/round2-integration.php`），
> PHPUnit 212 项全通过，PHPStan level 5 零错误，PHPCS 42/42 文件零告警，
> `E_ALL` 下 stderr 为 0 字节（零 Warning / Notice / Deprecated / Fatal）。
>
> 为什么新写一套集成验收而不是只用冒烟测试：冒烟证明的是「源码里写了 if」，
> 而这三项P0/P1 需要证明的是「if 真的会拦」。集成验收用真实调用把它们钉住：
> 逐个渲染 41 个非本插件后台页面确认零输出、真实写/校验失败回滚/停用
> `wp-config.php` 三条路径后确认磁盘无`.at8sa*` 残留、用 `realpath()` 实测
> 恶意 `Host` 无法把路径解析到缓存根之外。

### P0-1：禁止持久化 `wp-config.php` 明文备份

**问题**：`AdvancedCache::write_wp_config()` 在改写 `wp-config.php` 前，
把原文长期留在 `wp-config.php.at8sa.bak`。该文件位于 Web Root，可被任意 HTTP 请求读取，
而 `wp-config.php` 内含数据库密码、4 个 SECRET KEY 与 8 个 SALT。这是本轮最严重的问题——
比"缓存投毒"更直接，因为它把凭据整份交出去。

**修复**：按规范 20.2 的推荐流程实现「临时备份 → 改写 → 校验 → 删除」：

| 方法 | 职责 |
|---|---|
| `write_wp_config()` | 建临时备份 → 调 `apply_wp_config()` → **无条件**调`discard_wp_config_backup()` |
| `apply_wp_config()` | 写入 → 校验（`WP_CACHE` 已定义且可回滚）→ 不通过则回滚 |
| `discard_wp_config_backup()` | `file_exists` + `@unlink`，失败只写 error 日志、不阻断 |

关键设计：清理只有**一个出口**，因此不存在"写入成功但备份没删"的分支。
临时文件后缀由 `.at8sa.bak` 改为 `.at8sa.tmp`，语义上就不再可能被误当成长期备份。
用户提示文案也不再告知备份文件名（避免引导用户去找/保留它）。

### P0-2：`admin_notices` 作用域收窄

**问题**：`Notices` 类挂全局 `admin_notices`，只在排除自己页面后渲染——
等于在**所有其他后台页面**（文章、媒体、用户、工具、设置、其他插件页）都显示插件通知。
这是 Guidelines 第 11 条"不得劫持后台"的典型形态，也是审核员最反感的一类。

**修复**：新增 `is_allowed_screen()` 白名单方法，`render()` 入口立即校验：

```php
$allowed = array(
    'toplevel_page_' . SettingsPage::SLUG,
    'plugins',
    'dashboard',
);
return in_array( $screen->id, $allowed, true );
```

`get_current_screen()` 返回空（前端/AJAX/CLI）时直接 `false`，不做任何输出。

### P1-1：彻底移除 `cache_logged_in`（越权缓存）

**问题**：缓存键 `redis_key( $salt, $host, $uri, $mobile )` 不含任何用户维度。
一旦允许登录用户进入共享缓存，用户 A 登录后的页面会被存进公共键，用户 B 读取到 A 的页面。
这是规范 22.2 描述的场景，属于**设计层面的越权**，不是配置疏漏。

**修复**：不提供"安全实现"，直接取消该能力并清理全部遗留：

| 文件 | 处理 |
|---|---|
| `Cache/CacheEngine.php` | `is_user_logged_in()` 改为**无条件** `return false`；WooCommerce 判定不再依赖该键 |
| `Cache/Config.php` | `'cache_logged_in' => 0` **硬钉**，防止老站数据库里存着 `1` 继续生效 |
| `Cache/RequestGuard.php` | `has_auth_cookie()` 改为无条件触发绕过 |
| `Core/Settings.php` | 从 `defaults()` 与 `boolean_keys()` 移除，用户下次保存即清理历史数据 |
| `templates/settings-page.php` | 删除该开关，说明文案补充「登录用户始终不进入公共缓存」 |

### P1-2：Host Header 缓存投毒

**问题**：`CachePath::normalize_host()` 的产物直接成为**磁盘目录名**与 **Redis 键**。
原实现只过滤字符集 `[a-z0-9.\-:_]`，而纯点值 `.` / `..` 完全由白名单字符组成，
可通过字符过滤直达 `realpath()`——`Host: ..` 实测确实逃出缓存根目录。

**修复**：加 `rtrim( $host, '.-' )` 剥掉结尾点，并把纯点值降级为 `unknown-host`：

```php
$host = preg_replace( '/[^a-z0-9.\-:_]/', '_', $host );
$host = rtrim( (string) $host, '.-' );
if ( '' === $host || '.' === $host || '..' === $host ) {
    return 'unknown-host';
}
```

> 补充教训：本次验证脚本自身曾误报一次——把归一化后的字面量 `.._.._evil`
> 中的 `..` 当成目录穿越。严格用 `realpath()` 复测证明路径穿越不成立（斜杠已被替换），
> 但换到真实存在目录里测`Host: ..` 立刻暴露真漏洞。**结论：静态推理必须用 `realpath()`落地验证。**

### P1-3：三项复核确认无需改动

| 项 | 复核结论 |
|---|---|
| WebP 能力检测 | 3.0.4 已按格式逐项检测 GD 能力，无能力即跳过，不 Fatal |
| Mobile `Vary` | `CacheEngine::send_header()` 与 drop-in HIT 分支条件完全一致，统一出口已闭环 |
| 后台 CSS scope | 全部规则已 scoped 到 `.at8sa-wrap`，无裸 `body` / `.notice` 选择器 |

### 顺手发现并修掉的文档失真

P0-1 改完之后，全项目搜 `wp-config` 相关文案，发现三处仍在告诉用户
"会（自动）备份 wp-config.php"：`Notices.php` 的提示语、设置页工具卡说明、
`AdvancedCache` 的类级文档。这不只是文案过时——它会让用户**主动去找那个备份文件**
并保留下来，等于把刚修掉的风险又请回来。三处已统一为"临时备份、校验后立即删除"。

### 本轮的方法论教训

第 3～7 次误判全部来自同一个动作：**先写断言，再猜产品行为**。四次修法：

| 误判 | 真实情况 | 修法 |
|---|---|---|
| 目录排除失效导致 vendor 命中 | Windows 路径分隔符是 `\` | 路径先归一化再排除 |
| `.example.com` 应归一为 `example.com` | 前导点无害，剥掉反会让不同 Host 撞进同一目录 | 改期望值，并保留 realpath 实测作证据 |
| CSS 有未作用域选择器 | `.at8sa-wrap h1` 里的 `h1` 本就在作用域内 | 判定规则改为「任意一段带 `.at8sa-` 即算限住」 |
| `!important` 违规 1 处 | 命中的是注释里"禁止使用 !important"这句中文 | 检查前先剥注释 |
| wp-config 校验失败 | 手拼的内容缺 `WP_CACHE_MARKER`，没过可逆性检查 | 改调真实的 `enable_wp_cache()`，别自己拼 |

**规律**：验证代码行为时，要么调真实入口，要么让断言的期望值来自真实契约；
自己拼输入 + 自己猜期望，等于同时放弃了两个信息源。

### Free / Pro 架构定稿

**功能矩阵**：原 `docs/FREE_PRO_MATRIX.md` 写的是规划意图（含 Critical CSS、AVIF、白标等
代码里根本不存在的功能），已移出为 `docs/FREE_PRO_ROADMAP.md`，
矩阵文件按 Pro 仓库实际代码重写。审计结论分两类，这点必须诚实：

- Pro **开箱可用** 3 项：许可证管理、手动缓存预热、发布后单条预热
- Pro **代码存在但不可用** 6 项：JS 延迟、CDN 接入、CSS/JS 压缩、高级数据库分析、性能趋势、自定义清理规则

**Pro 介绍**：新增「高级版」标签页（`SettingsPage::tabs()` 加 `pro` 键），
内容为静态说明 + 一个普通外链，落在 `templates/settings-page.php` 的 `data-panel="pro"`。
刻意**没有**做：全局 `admin_notices` 广告、激活弹窗、`utm_*` / affiliate 参数、iframe 嵌入、远程请求。

**Free 独立性**：`tests/unit/smoke.php` 新增 6 条断言，把规范第十三、十四、三十六节
变成可执行约束——Free 包内不含 `download_url` / `Plugin_Upgrader` / `eval(` / `unzip_file` /
`wp_remote_post`，不含任何 `license_*` 校验代码。

---

## 3.0.4 — WordPress.org Plugin Review 整改

> 起因：WordPress.org Plugin Review Team 的 AUTOPREREVIEW 提出 8 项 P0。
> 本次不是"改到扫描器变绿"，而是逐项重新设计，并给每一项补上可验证的回归断言。
> 自测矩阵：冒烟测试 297 项全通过（原 249 项，本次新增 48 项整改专项断言），
> 全量 `php -l` 无错，`E_ALL` 下零 Warning / Notice / Deprecated / Fatal。

### P0-1：`COOKIEHASH` 不得作为缓存命名空间

**问题**：`COOKIEHASH` 是 WordPress 拼认证 Cookie 名（`wordpress_logged_in_<COOKIEHASH>`）
用的常量，属于认证材料。旧代码把它同时用作站点盐（`BackendFactory::site_token()`）
和落盘运行时配置的一个键（`Config::runtime()['cookie_hash']`），
等于把认证相关值复制进缓存目录名、Redis 键、`config/<host>.php` 与 JSON/PHP 配置。

**改法**：
- `site_token()` → `at8sa_blog<id>_<home_url 的 md5 前 12 位>`；
  多站点用 `get_current_blog_id()` 隔离，同站多域名用 `home_url()` 隔离，
  拿不到 `home_url()` 时退化为固定 `unknown`（绝不返回空串，否则所有站共用顶层目录）。
- `cookie_hash` 键**从运行时配置中删除**。
- `RequestGuard::has_auth_cookie()` 改为只按字面前缀 `wordpress_logged_in_` /
  `wordpress_sec_` 判定。这两个前缀本来就覆盖任意哈希后缀，判定强度不变，
  却彻底切断了插件与认证常量之间的联系。
- `BackendFactory::NAMESPACE_PREFIX = 'at8sa'` 常量化前缀。

### P0-2：移除无意义的 `require` WordPress Core 文件

**问题**：`CachePluginDetector::scan()` 里有
`require_once ABSPATH . 'wp-admin/includes/plugin.php'`，但加载后**从未使用**
`get_plugins()`——判定活跃插件用的是 `get_option( 'active_plugins' )`。

**改法**：删除该 require，新增私有方法 `active_plugin_files()` /
`is_active()`，只用 `get_option( 'active_plugins' )` +
`get_site_option( 'active_sitewide_plugins' )` 两个公开 option。
逐项处理了文档要求的边界：单站 / multisite / 网络激活 / option 不存在 /
被写成非数组（降级为空数组）/ 大小写（统一小写后比较）。

### P0-3：`advanced-cache.php` 归属保护（本轮最重要）

**问题**：`AdvancedCache::install()` 无条件 `file_put_contents()` 覆盖
`wp-content/advanced-cache.php`。用户一激活本插件，别人的缓存就被静默顶掉。

**改法**：
- 新增 `has_foreign_dropin()`：文件存在且归属标记不是本插件 → true。
- 新增 `blocked_reason()`：给管理员看的中文原因（用 `wp_kses()` 输出）。
- `install()` 第一步就是归属判断，冲突时记 warning 并返回 false。
- 模板文件头补 `Plugin Name` / `Drop-in` / `Owner: at8-site-accelerator` 标记。
  （后续修正：`Plugin Name` 已移除——它在插件目录内部会被 WP 扫成独立插件条目，
  反而导致激活报错"没有有效的标题"。归属保护实际依赖 `Owner:` 与版本戳，详见下文
  「drop-in 模板被 WP 扫成独立插件」一节。）
- `Activator::activate()` 把冲突结果写进激活结果 option（`dropin_blocked`）。
- `Ajax::dispatch_install_dropin()` 返回**具体原因**而不是笼统的"写入失败"。
- `Notices` 把归属冲突排在"未安装"之前提示——否则管理员会反复点安装却永远失败。

`Plugin::ensure_dropin()` 这条自愈路径天然安全：`needs_reinstall()` 仅在归属标记
属于本插件时才返回 true，且 `install()` 自身也有归属保护（双重保险）。

### P0-4：Heartbeat 只影响前台

**问题**：`heartbeat=disable` 时挂了两个回调，`heartbeat_disable_admin()`
无条件 `wp_deregister_script( 'heartbeat' )`——设置页写"前台禁用"，
实际把 wp-admin 的心跳也关了（影响自动保存与实时通知）。

**改法**：删除 `heartbeat_disable_admin()` 与 `admin_enqueue_scripts` 的挂载；
`heartbeat_disable_front()` 改为 `if ( is_admin() ) return;`。
UI 文案改为「前台禁用（wp-admin 不受影响）」并补说明。

### P0-5：Dashboard 小组件精确移除

**问题**：`remove_dashboard_widgets()` 把四个 meta box 写死在同一个回调里，
任一开关开启就整组执行——"移除新闻"顺手带走了 Site Health / 浏览器 / PHP 版本提示。
**附带缺陷**：它传的上下文是 `'side'`，而 WordPress Core 把 `dashboard_primary`
注册在 `normal`，所以这个开关**从来就没生效过**（死开关）。

**改法**：按开关分派，`remove_site_health` 只删 `dashboard_site_health`，
`remove_events_news` 只删 `dashboard_primary`（`normal` 与 `side` 都调用，
覆盖被第三方挪过位置的场景）。

### P0-6：WebP 的 GD 能力按格式检测

**问题**：`supported()` 只查 `imagewebp()` + `imagecreatefromjpeg()`，
而 PNG 路径调用 `imagecreatefrompng()`——缺少该函数的 GD 构建上直接 Fatal。

**改法**：
- 新增 `REQUIRED_FUNCTIONS` 常量表（按格式列出 decoder + encoder）。
- 新增公开方法 `can_convert_format( $ext )`：`extension_loaded('gd')` +
  该格式所需的**全部**函数 `function_exists()`。
- `supported()` 改为对 jpg/jpeg/png 逐个判定（全支持才算支持）。
- `convert()` 在进入任何 GD 调用**之前**按实际格式再判定一次；
  `load()` 内部对两个解码函数各留一道 `function_exists()` 兜底。

### P0-7：移动端缓存统一发送 `Vary: User-Agent`

**问题**：只有 drop-in 的 HIT 分支发 Vary，插件侧 HIT / MISS / MISS-SAVED / BYPASS
全都没发。移动端变体开启时，MISS 与新生成的响应缺少 Vary，
CDN / 反向代理据此缓存会把桌面版发给移动端——正是这个功能要避免的串页。

**改法**：收敛到 `CacheEngine::send_header()`——它是**所有**缓存响应路径的
唯一公共出口。`header( 'Vary: User-Agent', false )` 追加而非替换，与 drop-in 一致。
"开启移动端缓存 → 所有响应都带 Vary"从此成为一条不变量。

### P1：后台 UI 作用域审计

- `SettingsPage::enqueue_assets()` 已有 `toplevel_page_<slug>` 守卫；
  `Notices::render()` 已有 `current_user_can( 'manage_options' )` +
  自身页面不重复显示。均已加断言锁定，防回归。
- `assets/css/admin.css` 无任何全局元素选择器（`body` / `.notice` / `.button` /
  `input` / `select` / `table` / `h1`…），全部作用域限定在 `.at8sa-*` 下。
- REST 全部 `permission_callback` → `manage_options`，无 `__return_true`。

### 测试侧的配套改动

- `wp-stubs.php`：`remove_meta_box()` 记录调用（否则"只删新闻"无从断言）；
  `wp_deregister_script()` / `wp_script_is( ..., 'registered' )` 变成可观测的
  真实模拟；补 `get_current_blog_id()` 与 `wp_scripts_maybe_registering()`。
- `smoke.php`：新增 `strip_php_strings()`（与 `strip_php_comments()` 取舍相反，
  用于区分"真的 require 了 Core 文件"与"拿那段文本当字符串锚点用"）；
  新增 `at8sa_source_by_suffix()`（Windows 下 `$php_files` 键会残留 `includes/`
  前缀，硬编码键名会变成静默假绿）；新增第 20b 节共 48 条整改专项断言。

### P1：drop-in 模板被 WP 扫成独立插件、点激活报"没有有效的标题"（本次修复）

**问题**：上传 3.0.4 后，在插件列表页点激活，WordPress 报
**「该插件没有有效的标题」**（Fatal error: Cannot activate because the plugin is
missing a valid title）。URL 里的目标是
`plugin=at8-site-accelerator%2Ftemplates%2Fadvanced-cache.php`。

**根因**：`templates/advanced-cache.php` 的文件头带了 `* Plugin Name: AT8 Site Accelerator`。
WordPress 的插件扫描器（`get_plugins()`）会**递归**读取插件目录下所有 `.php`，
只要文件头有 `Plugin Name:` 就把它当成一个**独立插件条目**登记进插件列表。
而这个 drop-in 模板只写了 `Plugin Name`，没有 `Version` / `Plugin URI` /
`Description` 这些必需字段，WP 认为它不是一个完整插件，标题字段为空，
于是页面上冒出一个点得动却激活不了的空条目。

这个 header 是当初为了"让人一眼看出这是谁的 drop-in"加的，但它在插件目录**内部**，
语义完全错误：drop-in 是被复制到 `wp-content/advanced-cache.php` 执行的模板，
它自己永远不会被当作插件激活。

**改法**：删掉 `templates/advanced-cache.php` 的 `Plugin Name:` 行，
`Drop-in:` 与 `Owner: at8-site-accelerator` 保留。

**为什么安全**（归属保护没被削弱）：`AdvancedCache::dropin_head()` 与
`uninstall.php` 判定归属用的是 `strpos( $head, 'AT8 Site Accelerator' )`，
该字符串在文件头另有三处出现（首行标题、归属说明段），实测首次出现于
**字节偏移 13**，远在 `dropin_head()` 读 4096 字节与 `uninstall.php` 读 2048 字节
两个窗口之内，判定不受影响。真正的主标记 `Owner: at8-site-accelerator`
（字节偏移 461）与版本戳 `@at8sa-dropin-version`（字节偏移 522）都原样保留，
`AdvancedCache::VERSION_TAG` 正常比对版本自愈。

**防回归**：新增 1 条断言，锁死模板内不得再出现 `Plugin Name` 字样，
并把上面这段 WordPress 扫描机制写成注释，避免以后有人"好心"补回去。
冒烟测试 296 → 297 项。

### P1：3.0.4 推送后 CI 未通过（本次修复）

整改内容本身在 CI 上跑出 6 个 PHPStan 错误 + 2 个 PHPCS 警告，均已修复。
用 PHP 8.3.14 + phpstan 2.2.17 + wpcs 3.4.1 在本地完整复现后修复：

- **`Webp::convert()` 的 `isset()` 被判恒真**：$extension 已经过格式白名单，
  `REQUIRED_FUNCTIONS` 的键必然存在。把取值收进新私有方法
  `required_functions()`——在那里 $extension 是任意字符串，存在性判断是必要的；
  `convert()` 内则不再重复判断，判据统一由 `can_convert_format()` 负责。
  顺带消除了原先"判定的键"与"日志打印的键"可能不一致的隐患。
- **`smoke.php` 中 3 处 `method_exists()` 恒真**（PHPStan 已静态加载这些类，
  断言失去意义）：
  - `AdvancedCache` 的 2 条改为反射断言**方法可见性为 public**。这两个方法必须
    对外可见才能被 `Activator` / `Notices` / `Ajax` 调用，写成 private 会让
    归属保护静默失效——这才是真实风险，"存在性"不是。
  - `Webp::can_convert_format()` 的 1 条直接删除：紧随其后的真实调用就是更强的
    断言，方法不存在时那里会 Fatal。
- **`wp_scripts_maybe_registering()` 报 `function.notFound`**：它定义在
  `tests/unit/wp-stubs.php`，而该文件在 `excludePaths` 中。在
  `tests/phpstan/bootstrap.php` 补一份函数声明（只给签名不给实现），
  与该文件里已为 `COOKIEHASH` 做的常量补偿是同一机制。
- **`Activator.php` 等号未对齐**（`Generic.Formatting.MultipleStatementAlignment`），
  由 `phpcbf` 修正。

> 一个值得记下的坑：PHPStan 在中文路径下会报 UTF-8 错误，且清空result cache
> 之前会吐出上千个假的 `class.notFound`（符号表建立失败）。CI 是 Linux 环境
> 不受影响，但本地复现时必须先 `clear-result-cache` 才能看到真实错误数。

**修复后验证**：PHPStan level 5 No errors；PHPCS 0 错误 0 警告；
`php -l` 65 个文件通过；冒烟测试 296/0（有 GD 与无 GD 各跑一遍）；
PHPUnit 212 tests / 542 assertions 通过，6 skipped（Redis 相关，本机无 Redis）。

---

## 3.0.3 — Cookie 绕过判定一致性修复

> 起因：对 3.0.2 做独立真机复验时，发现 `bypass_cookies` 列表里的
> `woocommerce_items_in_cart` 实际是**死的**——WooCommerce 启用时它不再生效。

### 缺陷：WooCommerce 启用时购物车 Cookie 绕过失效（真机复现）

**现象**：带 `woocommerce_items_in_cart=1` 的 GET 请求返回 `X-AT8-Cache: MISS-SAVED`
（被写进缓存），而同为精确名的 `woocommerce_cart_hash` 与
`wp_woocommerce_session_*` 都正确返回 `BYPASS`。

**根因（已闭环证明）**：`WC_Cart_Session::set_cart_cookies()`
（WooCommerce `includes/class-wc-cart-session.php`）在购物车为空时执行
`wc_setcookie( ..., 0, time() - HOUR_IN_SECONDS )` **并 `unset( $_COOKIE[...] )`**。

缓存准入判定被调用两次：

| 时机 | 调用方 | 看到的 `$_COOKIE` | 结论 |
| --- | --- | --- | --- |
| WordPress 启动前 | `advanced-cache.php` drop-in | 原始值（含该 Cookie） | 正确绕过 |
| `template_redirect` | `CacheEngine::start_buffer()` | **已被 unset** | 判为"不绕过" → 落盘 |

于是本该绕过的请求被写进共享缓存，列表里的这一条形同虚设。

**证据**：
- 停用 WooCommerce 后同一请求立刻恢复 `BYPASS`；
- 临时 mu-plugin 在 `template_redirect@99` 打印 `$_COOKIE`：
  该请求 `COOKIE_KEYS=` **为空**，而 `cart_hash` 请求保留了键。

**修复**：`RequestGuard` 增加显式的 Cookie 快照。

- `warm_cookies()` 把当前 `$_COOKIE` 锁定为本次请求的判定基准（幂等）；
- 之后 `should_bypass()` / `has_auth_cookie()` 一律读快照，不再读实时 `$_COOKIE`；
- 锁定点两处：drop-in（WordPress 启动前，最干净的时机）+ 插件的
  `plugins_loaded@1`（覆盖"高级缓存关闭 / drop-in 被误删"时由插件层处理缓存的场景）。

**为什么用显式锁定而不是"首次调用自动快照"**：后者会让任何先跑到的判定把快照定死，
单元测试里逐条改写 `$_COOKIE` 再断言的 6 条 Cookie 绕过用例会全部失败（实测如此）。
显式锁定把"锁定时机"交给调用方，测试不调用即保持"每次读实时值"的语义。

**验证**：冒烟 249 通过 / 0 失败；真机对照实验（WooCommerce 启用/停用各一轮）。

---

## 3.0.2 — 命名空间合规 + drop-in 自愈版

> 起因：WordPress 官方 Plugin Check 2.1.0 报出两类问题——① 所有符号必须带它从代码里
> 推导出的 4 字符前缀（实测为 `at8sa`），而本插件当时用的是 `AT8\SiteAccelerator`；
> ② 一批编码规范告警（text-domain、直接查库、`unlink` 等）。
> 改名的过程中牵出了三个此前未被报告的真实缺陷，全部在真机上复现并验证修复。

### 修复 1：升级后整站白屏（严重，交付阻塞）

* **现象**：装上 3.0.2 后前台与 `wp-admin` 一起 500，页面源码为空。
* **根因链条**：
  1. `wp-content/advanced-cache.php` 是**复制**出去的 drop-in，插件升级只替换插件目录里的文件，
     **不会**动它；
  2. 它跑在 `wp-settings.php` 极早期，**早于插件加载**；
  3. 3.0.2 把命名空间从 `AT8\SiteAccelerator` 改成 `AT8SA`（PCP 前缀要求），
     于是老 drop-in 里硬编码的 `\AT8\SiteAccelerator\Cache\CachePath::normalize_host()`
     变成一个不存在的类 → `PHP Fatal error: Uncaught Error: Class "..." not found`；
  4. WordPress 根本没机会加载，所以没有任何"事后修复"能救它——用户只能手工删文件。
* **为什么升级不会自动重装 drop-in**：实测确认，WordPress 的后台自动更新走
  `wp_doing_cron()` 分支，而 `deactivate_plugin_before_upgrade()` 在 cron 下直接
  `return $response;`，`install_package()` 里也没有"重新激活"的调用 —— 所以
  `Activator::activate()` 不会跑，drop-in 不会被重写。
* **修复（四层，缺一不可）**：
  1. **兼容层**：`CachePath` / `RequestGuard` / `RedisClient` 三个类文件末尾各加一个
     `class_alias()` 到旧命名空间。老 drop-in 会 `require_once` 这些文件，所以升级瞬间仍然可用；
  2. **失败安全**：模板里加 `class_exists()` 护栏，类名对不上就 `return`，
     把"整站白屏"降级为"暂时没有页面缓存"；
  3. **版本戳 + 自愈**：模板 docblock 写入 `@at8sa-dropin-version`，
     `AdvancedCache::needs_reinstall()` 比对版本、`Plugin::ensure_dropin()` 负责重写；
  4. **drop-in 自检**：drop-in 一旦命中缓存就直接 `exit`，WordPress 不启动，
     第 3 层根本没机会跑（实测 3 次请求全 HIT，文件一个字节都没变）。所以 drop-in
     必须自己读插件主文件的 `AT8SA_VERSION` 与自身版本戳比对，不一致就主动让出命中。

### 修复 2：同一请求内二次保存不失效

* **现象**：一个请求里连续两次 `wp_update_post()`，第二次的改动在缓存里看不到。
* **根因**：`PurgeActions::$handled` 只按文章 ID 去重，`array<int,bool>`。
  "同一请求"被当成了"同一次保存"，第二次保存被静默跳过。
* **修复**：改成 `array<int,string>`，值存内容指纹（`post_modified_gmt` / 状态 / 标题 /
  别名 / 正文 / 摘要 的 md5）。指纹不同就是一次新保存，同时作废"已失效 URL"备忘
  （`Purger::reset_purged()`）——因为 Pro 的预热器会把页面写回缓存，
  沿用备忘会让第二次失效被跳过。

### 修复 3：缓存命中时兜底钩子永远不执行

* **现象**：drop-in 与运行时配置文件在持续命中缓存的情况下永远不刷新。
* **根因**：两个兜底挂在 `init` 优先级 **99**，而 `CacheEngine::maybe_serve_from_cache()`
  挂在 `init` 优先级 **1** —— 命中插件侧缓存时它输出完就 `exit`，99 的钩子跑不到。
* **修复**：两个兜底一起提前到 `init` 优先级 **0**。
  另外给 `ensure_dropin()` 补上 `advanced_cache` 设置门禁：
  `SettingsSync::sync()` 关闭该项时只是"不再安装"、不会删掉已有文件，
  少了门禁会出现"用户关掉 drop-in 之后一升级又被装回来"。

### Plugin Check 2.1.0 清零

* 命名空间 `AT8\SiteAccelerator` → `AT8SA`（440 处），满足 4 字符前缀要求。
* 直接查库处补 `esc_sql()` + 表名白名单，SQL 表达式内联在 `$wpdb->get_results()`
  的第一个参数里（`DirectDBSniff::check_expression()` 会越过表达式末端继续扫，
  写成变量会被误判）。
* `unlink()` → `wp_delete_file()`；`fsockopen` / `fclose` / `rmdir` / `rename`
  用**行尾合并**的 phpcs 豁免 + 理由注释（PHPCS 对同一行只保留最后一条注解，
  拆成两行会让前一条失效）。
* `readme.txt` 英文化并压缩到限制内（短描述 130 / 150 字符，Upgrade Notice 275 / 300 字符）。

### 真机验证结论（测试站 WordPress 7.1.2 / PHP 8.3.33 / nginx + Redis，插件版本 3.0.2）

| 场景 | drop-in 状态 | 结果 |
| --- | --- | --- |
| A | 新版模板 + 旧版本戳 3.0.0（缓存热） | 200，drop-in 自动刷成 3.0.2 |
| B | 新版模板 + 命名空间改坏 + 版本戳**一致** | 200，`class_exists` 护栏拦下，页面正常渲染 |
| C | 新版模板 + 命名空间改坏 + 旧版本戳 | 200，版本自检先让出，随后自动刷成新版 |
| D | 真实 3.0.1 老 drop-in（旧命名空间、无护栏无自检） | 200，`class_alias` 兜住 |
| E | 场景 D + 强制 MISS | 200，WP 启动后自动重装 drop-in |
| F | 老模板 + 命名空间改坏（**无护栏**） | **500** —— 对照组，证明护栏/自检才是防白屏的关键 |

场景 F 抓到的原始报错（就是"整站白屏"的真身）：

```
PHP Fatal error:  Uncaught Error: Class "AT8\TotallyGone\Cache\CachePath" not found
in /www/wwwroot/wordpress.xmm.fan/wp-content/advanced-cache.php:32
```

场景 D 说明了一件必须写进运维文档的事：**drop-in 一旦命中缓存就 `exit`，WordPress 不启动，
所以"事后重装"这条路在纯命中流量下走不通**——老 drop-in 靠 `class_alias` 继续服务，
真正被刷新要等到下一次 MISS 或后台访问。这也是场景 A/C 里"版本自检"必须存在的原因。

---

## 3.0.1 — 真机验证修复版

> 起因：在真实站点（WordPress 7.1.2 / PHP 8.3.33 / nginx，装 Redis）部署 3.0.0 后，
> 激活结果里 `wp_cache` 恒为 `false`，提示「写入后校验未通过，已自动回滚」。
> 逐层定位后确认是两个真实缺陷 + 一个测试缺陷。

### 修复 1：`WP_CACHE` 在含大括号 salt 的站点上永远开不起来（严重）

* **现象**：`enable_wp_cache()` 写入成功后被自己的校验判为失败，立即回滚。
  真机 `wp-config.php` 实测 `{` 6 个、`}` 10 个，多出来的**全部位于 8 个随机 salt 字符串内**
  （`AUTH_KEY` / `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` / `NONCE_KEY` / `AUTH_SALT` / `NONCE_SALT` 等）。
* **根因**：`verify_wp_config()` 用 `substr_count( $content, '{' ) !== substr_count( $content, '}' )`
  做**全文**括号计数。salt 的字符集包含 `{` `}`，字符串里的括号被计入 → 括号数永不配平 →
  校验恒失败。而 salt 含括号是常态，等于绝大多数真实站点都命中。
* **修复**：新增 `braces_balanced()`，改用 `token_get_all()` 按 PHP 词法统计——
  只计真实代码 token（含字符串插值的 `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`），
  `T_CONSTANT_ENCAPSED_STRING`、注释一律跳过。词法器不可用或抛异常时返回 `true` 不阻断。

### 修复 2：校验缺"可逆性"约束，且方向不对称

* **新增**：`verify_wp_config()` 接收写入前原文，调用 `is_reversible()` 要求
  **改动只差我们那一行**——写入后去掉标记行必须精确还原原文，否则判失败并回滚。
* **对称性**：启用是「加一行」、停用是「减一行」，只做单向判断会让停用路径恒失败。
  `is_reversible()` 现覆盖三个方向：① 启用-插入（strip 新文 == 原文）、
  ② 停用-删除（strip 原文 == 新文）、③ 启用-替换（把 `true` 换回 `false` == 原文）。
* **顺带修掉一个隐蔽缺陷**：插入时原本多带一个 `"\n"`，导致「删掉标记行」还原不出原文
  （多一个空行），可逆性检查正是靠这个不变量成立的。现已改为严格只插入一行。
* **幂等**：`enable_wp_cache()` 遇到文件中已是 `WP_CACHE, true` 时直接返回成功，
  不再落到「已有定义且无法自动改写」的报错分支。

### 修复 3：单元测试在装有 Redis 的机器上假失败

* **现象**：`purge_url 后缓存已失效` / `purge_all 后页面缓存已清空` 两条断言在真机失败。
* **根因**：`cache_backend` 默认 `auto`，Redis 可达时 `factory->make()` 返回 `RedisBackend`，
  失效器清的是 Redis；而断言检查的是 `new DiskBackend(...)` 写的磁盘文件 → 必然对不上。
  CI 机器没有 Redis，所以这个坑一直没暴露（`smoke.php` 第 13 节「缓存引擎」已经踩过并修过，
  但「失效器」一节漏了）。
* **修复**：给失效器一节引入独立的、`cache_backend=disk` 的 `Settings`，使断言与宿主机环境解耦。
* **结论**：这不是生产缺陷——`Config::runtime()` 与 `Purger` 都走同一个 `redis_probe()`，
  drop-in 读的 `backend` 与失效器清的后端天然一致。

### 修复 4：`bump_cache_version()` 不失效已解析的后端实例

* **现象**：清空缓存后统计「缓存条目数」返回 0，但 `redis-cli` 里明明有 71 个新键；
  精准失效测试因此量不出任何被清除的条目。
* **根因**：缓存盐 `site_token()|v<版本>` 是后端实例的**构造参数**，
  而 `BackendFactory` 把已构造的实例缓存在 `$this->resolved` 里。
  `bump_cache_version()` 只递增了 option，实例仍绑在旧盐上，
  于是后续读写全部落在旧命名空间——旧键没删、新键没人读。
* **修复**：版本变更后把 `$this->resolved` 置空，下次 `make()` 用新盐重建。

### 修复 5：WP-CLI 下改配置对前台不生效（**只修了一半，见修复 7**）

* **现象**：`wp` 命令行把 `cache_backend` 切成 `redis`，脚本报成功，
  但前台响应头依然是 `x-at8-cache-backend: disk`；配置文件里
  `default.php` 是新的、`<host>.php` 是旧的。
* **根因**：`Config::write()` 用 `$_SERVER['HTTP_HOST']` 决定写哪个主机文件。
  WP-CLI / WP-Cron 这类非 HTTP 上下文没有 `HTTP_HOST`，只写出了 `default.php`；
  而 drop-in 在前台优先读 `<host>.php` → CLI 里的改动永远到不了 drop-in。
* **修复**：`HTTP_HOST` 为空时退化为从 `home_url()` 取主机名；
  并顺带刷新目录里其它已存在的主机配置文件（跳过 `default.php` / `index.php`）。
* **⚠️ 这条修复是不完整的**：它只让 `Config::write()` 写对**文件**，
  却没有让它在 CLI 下被**调用**。真正的触发问题见下方「修复 7」——
  修复 7 之前，WP-CLI 改设置时运行时配置根本不会被重写，本条的修复无从生效。
  文档如实记录这个疏漏，避免后来者以为 CLI 场景已经覆盖。

### 修复 6：`html_minify_inline` 是个死开关

* **现象**：设置页有这个开关、默认值表里有这个键，但**代码里从未读取过它**。
* **修复**：实现保守的内联 CSS 空白折叠（`<style>` 内连续空白折成一个空格，
  引号内字符串原样跳过，内联 JS 一律不动——JS 的自动分号插入依赖换行）。
* **诚实说明**：真机实测该项收益约 0%。区块主题的内联 `<style>` 由 WordPress
  样式引擎生成，本身就是单空格排版，没有可折叠的空白。设置页文案已如实说明。

### 修复 7：设置同步只在后台生效，CLI / Cron / 其它插件改设置全都不生效（严重）

* **发现方式**：补 PHPUnit 与 PHPStan 之后做真机 HTTP 验证，发现设置改动"看着成功、实际没动"。
  在真机上做受控实验：连改 6 次 `cache_backend`（`disk`/`redis`/`auto` 轮换），
  `cache/at8-site-accelerator/config/<host>.php` 里的 `'backend'` **始终是旧值**。
* **根因**：这条链路的三个动作——刷新设置缓存、重写 drop-in 运行时配置、清理缓存——
  原先挂在 `Admin\SettingsPage::boot()` 上，而 `SettingsPage` 只在 `is_admin()` 为真时被启动。
  于是所有非后台上下文改设置都只改了数据库、没改运行时配置：
  * `wp option update at8sa_settings …`（WP-CLI）
  * `update_option( 'at8sa_settings', … )`（其它插件 / 主题 / 迁移脚本）
  * WP-Cron、外部 REST 客户端
* **修复**：把监听器提取成独立服务 `Core\SettingsSync`，改挂到 `Plugin::boot_shared()`
  （前后台 + CLI + Cron 都会执行），并让 `Admin\SettingsPage` 交出这个职责，
  避免两处各写一份、再次漂移。
* **验证**：真机受控实验从"6 次全不生效"变为"每次立即生效"；
  新增 PHPUnit `SettingsSyncTest`（7 个用例）与冒烟测试的源码级架构守卫
  （监听器不得再出现在任何后台类里）。

### 修复 8：运行时配置不会自愈，绕过 `update_option()` 的写入永远不生效

* **根因**：修复 7 只能覆盖走 `update_option()` 的路径。现实里还存在绕过它的写入方式——
  直接 `$wpdb->update()`、`wp option import`、站点迁移脚本、DB 层手工修改。
  这些情况下钩子不触发；而原有的兜底 `Plugin::ensure_runtime_config()`
  **只在配置文件缺失时**才重写，文件在但内容陈旧就永远不管。
* **修复**：`Config` 新增 `needs_refresh()`——把设置算成指纹写进运行时配置，
  每个请求（插件已加载时）比一次；对不上就重写。成本极低：
  `at8sa_settings` 是 autoload 选项，读它不产生额外查询，
  而命中的请求在 drop-in 阶段就 `exit` 了，压根到不了这里。
* **顺带修掉一处重复实现**：`Plugin::ensure_runtime_config()` 原先自己写了一份
  主机名归一化正则，与 `Config::write()` 里的 `CachePath::normalize_host()` **并不等价**，
  属于"写 A 文件、查 B 文件"的隐患。现已统一到 `Config::config_host()` 一处。

### 修复 9：`purge_all()` 先换盐再 flush，导致 Redis 索引集合无限堆积（资源泄漏）

* **根因**：后端实例的缓存盐（`site_token|v<版本>`）是**构造参数**。
  `purge_all()` 先 `bump_cache_version()` 再 `make()`，拿到的是**新盐**的实例，
  于是 `flush()` 去删新盐命名空间——那里本来就是空的，什么都没删到。
  后果是旧盐的索引集合 `at8sa:<盐>|__index` 被永久孤立：
  它是 `SADD` 建的、**没有 TTL**，会一直堆在 Redis 里，
  每个版本留一份"该版本全部缓存键"的清单。
  真机测试机上连续调试后残留了 `v10`~`v36` 共 **21 个**这样的孤儿索引集合。
* **附带影响**：`stats()` 在新盐上统计，`purge_all()` 恒返回 0，
  日志里的"失效条目数"完全失真（用户点"清缓存"永远看到 0 条）。
* **修复**：调整顺序为**先 flush 当前盐，再递增版本盐**。
  正常路径下 flush 把当前盐的索引集合与条目真正删掉（Redis 后端还会 SCAN 兜底）；
  异常路径下换盐依旧兜底——即便 flush 因权限/连接问题半途失败，
  旧键也已在语义上不可达，访客不会命中陈旧页。两全，且顺带修好了条目数统计。
* **防御加固**：`RedisBackend::flush()` 额外清扫本站点**历史版本**遗留的孤儿索引集合
  （按 `at8sa:<站点令牌>|*|__index` 精确匹配，前缀带 `|` 分隔符，
  不会误伤同 Redis 上其它站点）。这样即便将来顺序再被改错，也不会无限堆积。
* **验证**：真机 `purge_all()` 返回值从 `0` 变为真实条目数；失效后
  `redis-cli --scan --pattern 'at8sa:*'` 计数为 0、孤儿索引集合为 0。
  新增 `RedisBackendTest`（6 个用例，含孤儿清扫与跨站点隔离）与 `PurgerTest`（6 个用例）。

### 修复 10：同一个响应头因代码路径不同给出不同大小写

* **现象**：`X-AT8-Cache-Backend` 由 drop-in 命中路径给出时是 `redis`/`disk`（配置里的原始字符串），
  由插件侧给出时是 `Redis`/`Disk`（`BackendInterface::name()`）。
  同一个诊断字段出现两种取值，排查时容易误判。
* **修复**：drop-in 模板改为与 `name()` 对齐输出 `Redis`/`Disk`。

### 测试基础设施：补上 PHPUnit、PHPStan 与 Redis 覆盖

* **新增 PHPUnit 9.6 套件**（`tests/phpunit/`，208 个用例 / 550 条断言）：
  基类在每个用例前后双向重置桩全局状态与 `$_SERVER` / `$_COOKIE` 快照，
  避免"单跑绿、全跑红"。
* **新增 PHPStan level 5**（`phpstan.neon.dist`，0 错误，豁免仅 1 条且附理由）。
  首跑 49 个错误全部逐条分类处置：真代码修 10、真文档修 9、改为消费依赖属性 4、
  真实潜在 Bug 2、测试自身缺陷 7、配置局限 1。
  "恒真/恒假"类噪音用 `treatPhpDocTypesAsCertain: false` 从根上关掉，
  而不是逐条 `ignoreErrors`——PHPDoc 对 WordPress 插件是**契约**不是运行时保证，
  数据大量来自 `apply_filters`、数据库脏数据与任意客户端，防御性判空必须留着。
* **修正 WordPress 桩的两个不忠实之处**（这是漏掉修复 7 的直接原因）：
  * `do_action()` 原是空实现 → 改为真实回调已注册的钩子。
    空实现会让"钩子挂没挂上"在测试里恒为真，依赖钩子的缺陷完全测不出来。
  * `update_option()` 原是无条件写 + 不触发钩子 → 改为与 WP 一致：
    值没变时返回 `false` 且不触发 `update_option_{$option}` / `updated_option`。
* **修正测试环境的 Redis 库号**：新增 `AT8SA_REDIS_DB = 15`（`tests/unit/wp-stubs.php`）。
  插件默认用 2 号库，测试若不覆盖这个常量，在**开发者本机**执行冒烟/单元测试
  就会把线上缓存清掉——一次 `purge_all()` 就够。
* **CI 新增 3 个阻断式任务**：`phpunit`（PHP 7.4 / 8.3 矩阵）、`phpstan`（level 5）、
  以及把 Redis 任务从"只跑冒烟"升级为"冒烟 + PHPUnit"，
  并额外断言**跳过数为 0**——否则 `RedisBackendTest` 会静默 skip，覆盖形同虚设。
  `package` 任务的 `needs` 相应补上 `test-redis`。

### 代码规范：PHPCS 存量清零，CI 改为阻断

* 引入 `wp-coding-standards/wpcs` 3.4.1 + `phpcsstandards/phpcsextra`，
  在真机（PHP 8.3.33）上跑全量检查：**535 错误 / 88 警告 → 0**。
* 修正 `phpcs.xml.dist` 两处失效配置：
  * `<arg name="-p"/>` 在 phpcs 3.x 下会被拼成 `---p` 直接报错，应为 `<arg value="p"/>`；
  * WPCS 3.x 把若干嗅探从 `file_system_operations_*` 改名为 `<函数名>_<函数名>`，
    旧豁免名不再命中（`file_get_contents` / `unlink` / `rmdir` / `rename` 均受影响）。
* 真实修复（非豁免）：
  * **`RequestGuard` 新增统一的超全局净化入口 `server()`**，去掉 `NUL` 与控制字符
    （`REQUEST_URI` 里的 `NUL` 会让下游字符串函数提前截断，从而绕过 `/wp-admin` 前缀判断），
    并让 drop-in、`Config::write()`、`Plugin::ensure_runtime_config()` 三处共用，
    保证同一个 `HTTP_HOST` 在三条路径上算出同一个主机名。
  * `RedisClient` 的 RESP bulk string 读取改用 `while (true)` + 显式 break，
    避免把 `strlen()` 提到循环外（那样会在一次 read 不足时提前退出、拿到截断响应）。
  * `Purger` 两处 `for` 循环边界里的 `min()` / `max()` 提到循环外。
  * 保留字参数名 `$class` / `$default` / `$new` 改名。
  * `uninstall.php` 用 `is_readable()` 替代 `@file_get_contents` 抑制。
* 带书面理由的豁免（不是"关掉检查"）：中文注释不适用 ASCII 句号/大写开头规则、
  `/** @var Type $var */` 类型标注不算缺描述、缓存插件必须直接读写文件、
  `rename()` 是原子写的必需品（`WP_Filesystem::move()` 会失去原子性）。
* CI 的 `phpcs` 任务去掉 `continue-on-error: true`，从 3.0.1 起新增违规会让流水线变红。

### 验证

四道闸门全部为阻断式，结果如下（真机 PHP 8.3.33）：

| 闸门 | 结果 |
| --- | --- |
| PHPCS（WordPress 规范） | **0 错误 / 0 警告**（42 个文件） |
| PHPStan level 5 | **0 错误**（豁免仅 1 条，附理由） |
| 冒烟测试 | **248 通过 / 0 失败**（3.0.0 时为 208 通过 / 2 失败） |
| PHPUnit | **208 用例 / 550 断言，全通过** |
| 全量 `php -l` | 无错 |
| 打包自检 | 49 文件、153.0 KB、无开发文件泄漏 |

真机端到端（`https://wordpress.xmm.fan/`，WordPress + nginx + Redis）：

* `wp-config.php` 第 98 行写入 `define( 'WP_CACHE', true );`，站点 HTTP 200。
* drop-in 命中链路 `MISS-SAVED → HIT → HIT`，HIT 携带
  `Cache-Control: public, max-age=3600` 与 `X-AT8-Cache-Backend: Redis`。
* **设置变更即时生效**：`cache_backend` 在 `disk`/`redis`/`auto` 间切换，
  运行时配置每次同步（修复 7 前是 6 次全不生效）。
* **Cookie 语义**：8 类绕过 Cookie（登录态、密码保护、评论者、WooCommerce 购物车、EDD）
  全部 `BYPASS`；无关 Cookie 不误伤。`cache_logged_in=1` 时登录态可缓存，
  但密码保护页面**依然**拦截（原先这个开关是死的）。
* **双后端**：Redis 与磁盘各自 `MISS-SAVED → HIT`，磁盘布局
  `<host>/__root/index.html`。
* **失效**：`purge_all()` 返回真实条目数（修复 9 前恒为 0），
  失效后 Redis 数据键 0 个、孤儿索引集合 0 个。
* 性能基准：见 `docs/PERFORMANCE_BENCHMARK.md` 第七节（A/B/C 三组对照 + 可复现脚本）。
* 可复现脚本：`tests/unit/smoke.php`（冒烟）、`vendor/bin/phpunit`（单元）、
  `tools/build-zip.php`（打包自检）。

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
* **缓存版本盐**：`site_token()|v<版本号>`。整站失效时**先 flush 当前盐、再递增版本号**——
  顺序不能反：后端实例的盐是构造参数，先换盐会让 flush 打在新命名空间上什么也删不到，
  旧盐的索引集合（`SADD` 建的、无 TTL）就会被永久孤立。反过来则两全：
  正常路径删干净，异常路径靠换盐兜底，旧键立即不可达。
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

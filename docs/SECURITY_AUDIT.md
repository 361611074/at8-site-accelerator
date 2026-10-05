# 安全审计报告

> 对应开发计划书 §69（路径穿越）、§70（环境门槛）、§71（Sanitization）、
> §104（禁止自动改核心）、§124（所有输入必须 sanitize）、§125（目录守卫）。

审计对象：`at8-site-accelerator` 3.0.0 Free 版，41 个 PHP 文件，约 10,388 行。

审计方式：静态扫描（`tests/unit/smoke.php` 第 20–21 节，自动执行）+ 人工逐条核对。
静态扫描在扫描前会用 `token_get_all()` **剥掉注释**，避免把"解释为什么不用某函数"
的文档注释误判成实际调用。

---

## 一、自动化检查项（CI 每次运行）

| 检查项 | 方法 | 结果 |
| --- | --- | --- |
| 所有 PHP 文件有 `ABSPATH` 守卫 | 全文包含 `ABSPATH` | ✅ 通过 |
| 无 `eval()` | 剥注释后正则 | ✅ 通过 |
| 无 `FLUSHDB` | 剥注释后不区分大小写查找 | ✅ 通过 |
| 无 `extract()` | 剥注释后正则 | ✅ 通过 |
| 无硬编码密钥 | 4 类模式（`sk_live_`、AWS AK、PEM 私钥、`api_key/secret_key/password` 赋值） | ✅ 通过 |
| 无命令执行类函数 | `shell_exec` / `passthru` / `proc_open` / `popen` / `system` / `exec` | ✅ 通过 |
| REST 路由全部声明 `permission_callback` | 计数比对（routes ≤ guarded） | ✅ 5 条路由 / 5 个回调 |
| 路径穿越防护有效 | 6 组恶意输入 + 4 组边界路径 | ✅ 通过 |
| 诊断输出不含密码/密钥字段 | 序列化后查找 `password` / `secret` | ✅ 通过 |
| 卸载脚本尊重保留数据开关 | 静态检查 | ✅ 通过 |
| 卸载脚本不使用 `FLUSHDB` | 静态检查 | ✅ 通过 |
| 卸载脚本只删自己的 drop-in | 静态检查归属标记 | ✅ 通过 |

> 注：`proc_open` 只出现在 `tests/unit/smoke.php`（用于在独立进程里跑 drop-in），
> 不在插件本体中。扫描范围是 `includes/`、`at8-site-accelerator.php`、`uninstall.php`。

---

## 二、路径穿越（§69）

### 攻击面

缓存路径由用户可控的 `REQUEST_URI` 与 `HTTP_HOST` 推导，
是典型的"用户输入直接进文件系统"场景。

### 防线

**第一层 — 路径段消毒**（`CachePath::sanitize_segment()`）

```php
$segment = str_replace( array( '..', '\\' ), '', $segment );   // 显式吃掉穿越序列
$segment = preg_replace( '/[\x00-\x1f\x7f]/', '', $segment );   // 控制字符
$segment = preg_replace( '/[^A-Za-z0-9._\-]/', '_', $segment ); // 白名单
$segment = trim( $segment, '.' );
```

只保留 `[A-Za-z0-9._\-]`，单段上限 120 字符。

**第二层 — 主机名消毒**（`CachePath::normalize_host()`）

只保留 `[a-z0-9.\-:_]`，长度上限 190。

**第三层 — 删除白名单**（`Filesystem::is_inside_cache_root()`）

```php
$root = self::normalize( WP_CONTENT_DIR . '/cache' );
$real = self::normalize( $path );
return $real === $root || 0 === strpos( $real, $root . '/' );
```

`normalize()` 先 `realpath()`（可解开符号链接绕行）；目标不存在时逐级上溯到最近的
已存在祖先再拼回，因此对"尚未创建的路径"同样有效。

**任何递归删除都必须先过这一关**（`Filesystem::rrmdir()`、`prune_empty_dir()`、
`DiskBackend::delete_url()`、`DiskBackend::flush()`）。

### 已验证的攻击载荷

| 载荷 | 结果 |
| --- | --- |
| `../../../etc/passwd` | 消毒为 `etc/passwd`，不含 `..` |
| `..%2f..%2fetc` | `rawurldecode` 后消毒，不含 `..` |
| `/foo/../../bar` | 消毒为 `foo/bar` |
| `/a/..;/b` | `;` 不在白名单，替换为 `_` |
| `/a\x00/b` | NUL 被剥离 |
| `/a/....//b` | 消毒后不含 `..` |
| `AT8SA_CACHE_ROOT . '/a/b'`（不存在） | 判定为**在内** |
| `ABSPATH` | 判定为**在外** |
| `C:/Windows/System32` | 判定为**在外** |
| `AT8SA_CACHE_ROOT . '/../../plugins'` | 判定为**在外** |

### 已知修复

审计过程中发现并修复一处**跨平台失效**：
`is_inside_cache_root()` 原本用 `DIRECTORY_SEPARATOR` 拼接前缀，
而 `normalize()` 统一输出正斜杠。在 Windows 上 `DIRECTORY_SEPARATOR` 是 `\`，
前缀比较恒为假——即"白名单"在 Windows 上退化成"全部拒绝"。
虽然方向是保守的（拒绝而非放行），但会让插件在 Windows 上完全无法删除缓存，
已在 3.0.0 修复为统一使用 `/`。

---

## 三、CSRF 与权限（§71 / §124）

### 管理后台

全部 14 个 `admin-ajax` 动作统一走 `Ajax::guard()`：

```php
check_ajax_referer( self::NONCE, 'nonce' );        // 先验 nonce
if ( ! current_user_can( 'manage_options' ) ) {    // 再验权限
    wp_send_json_error( ..., 403 );
}
```

顺序刻意是"先 nonce 后 capability"：nonce 失败说明请求来源不可信，
此时不应该再去触碰权限系统。

### 管理栏"清空缓存"

管理栏按钮**不使用链接，而是 `<button>` + `fetch(POST)`**。

原因：`CheckIsRefererValid()` 类校验在 WordPress 中会**接受 GET 传递的 csrfToken**，
且 Referer 为空时直接放行。浏览器与部分扩展会预取/prefetch 页面里的链接——
一个 GET 形式的"清空缓存"链接可能被预取，导致用户只是悬停就真的清了整站缓存。
改用 POST + fetch 后，预取不会触发写操作。

### REST API

5 条路由全部声明 `permission_callback`，指向统一的 `can_manage()`：

```php
public function can_manage() {
    return current_user_can( 'manage_options' );
}
```

命名空间 `at8sa/v1`，无公开只读端点。

### 设置写入

`register_setting()` 的 `sanitize_callback` 指向 `Settings::sanitize()`（实例方法）：

- 布尔键 → 强制 0/1；
- 枚举 → 白名单，非法值回退默认；
- 整数 → 区间钳制（如 `cache_ttl` 钳到 60–2592000）；
- 文本 → `sanitize_textarea_field()`。

### 已知修复

`sanitize()` 原本把"输入中缺失的键"一律当成 0。这在表单场景下是对的
（未勾选的 checkbox 不提交），但 REST / 导入设置这类**局部更新**会因此
静默关掉用户没碰过的功能——例如"只改一个 TTL"会顺手关掉整站缓存。

已改为：**缺键保留当前已存值**，同时在设置页为每个开关补一个 `value="0"` 的
hidden 字段（未勾选时提交 0，勾选时 checkbox 覆盖它）。
两个改动必须成对出现，缺任何一个都会让开关失效或让局部更新误伤配置。

---

## 四、文件写入（§104）

### wp-config.php

这是插件唯一会碰的、**不属于自己**的文件。流程严格三段式：

```
1. 备份         → wp-config.php.at8sa.bak（备份失败则中止，不做任何修改）
2. 写入         → 只增删带 '// Added by AT8 Site Accelerator' 标记的那一行
3. 完整性校验   → 长度 > 100 字节 && 含 DB_NAME && 含 wp-settings.php && 大括号配平
                  ↓ 校验失败
                  立即用备份内容回滚 + 记录 error 日志 + 返回失败
```

**永不重写整个文件**，只在标准锚点（`/* That's all, stop editing!` 或
`require_once ABSPATH . 'wp-settings.php';`）前插入一行。

### .htaccess

**不自动覆盖**。只提供规则片段供用户复制，若用户显式点击"写入"，
会先备份为 `.htaccess.at8sa.bak`，且只操作
`# ==== BEGIN/AT8 END AT8 Site Accelerator ====` 之间的内容。

### drop-in

`wp-content/advanced-cache.php` 由模板生成。卸载时**先读前 2048 字节确认归属标记**，
只有确认是自己写的才删除。其他插件（WP Rocket、LiteSpeed 等）的 drop-in 不会被误删。

### 缓存目录守卫

缓存根目录与 `config/` 各放一个 `index.php`（内容 `<?php // Silence is golden.`），
防止服务器开启目录列表时暴露缓存结构。

---

## 五、日志脱敏（§71）

`Support\Logger` 在写入前做两层脱敏：

**字符串层**（`redact()`）：
- 长度 ≥ 32 的十六进制串 → `[redacted]`（覆盖各类 token / hash）；
- `sk_` / `pk_` 前缀的长串；
- `Bearer <token>`。

**数组层**（`redact_array()`）：
键名含 `cookie` / `password` / `secret` / `token` / `license` / `key`（不区分大小写）
的值一律替换为 `[redacted]`。

日志默认**关闭**。开启后单文件上限 1MB，超出自动轮转。

已验证：
- `240a8412ed8d743be4c0c373c0a2cf82` → 已脱敏；
- `Bearer abcdefghijklmnop` → 已脱敏；
- `['cookie' => 'wordpress_logged_in_secret']` → 已脱敏。

### 诊断报告

`Diagnostics::collect()` 输出 8 组环境事实（缓存、对象缓存、PHP、WordPress、
服务器、HTTPS、数据库、冲突）。

刻意**不做评分**：评分会诱导用户为了"刷分"去开启不合适的选项，
而真正该看的是"哪一项不正常、为什么"。已验证输出中不含 `password`、`secret`、
`score` 字段。

---

## 六、Redis 安全

### 不使用 FLUSHDB

同一台 Redis 上通常跑着多个站点或应用。`FLUSHDB` 会清掉整个数据库，
包括别人的数据。这是 2.x 踩过的坑，3.0.0 用三层机制替代：

1. **站点盐前缀**：所有键为 `at8sa:<站点盐>|v<版本>:*`，其中站点盐由插件自有前缀
   `at8sa` + `get_current_blog_id()` + `home_url()` 派生值组成
   （3.0.4 起不再使用 `COOKIEHASH`——它是认证材料，不该出现在缓存键里）；
2. **索引集合**：`at8sa:<盐>|__index` 记录本站点写过的所有键，失效时按索引删除；
3. **SCAN 兜底**：索引集合丢失时，用 `SCAN MATCH <前缀>*` 分批游标扫描。

无站点盐时**安全退避**：拒绝执行整站失效，而不是退化成 `FLUSHDB`。

### 无扩展依赖

`Support\RedisClient` 是纯 PHP 实现的 RESP 协议客户端（`fsockopen`），
不依赖 `phpredis` 扩展。连接超时 1 秒，避免 Redis 挂掉时拖垮整个站点。

---

## 七、环境门槛（§70）

插件头里的 `Requires PHP` / `Requires at least` **只在从后台安装时**被 WordPress 校验。
手工上传、must-use 安装、脚本批量部署都会绕过它。因此入口文件运行时再判一次：

```php
if ( version_compare( PHP_VERSION, AT8SA_MIN_PHP, '<' ) ) { 提示 + return; }
global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, AT8SA_MIN_WP, '<' ) ) { 提示 + return; }
```

不满足时**不注册任何钩子、不注册自动加载器**，只挂一条 `admin_notices` 提示后 `return`。

---

## 八、未覆盖 / 已知限制

诚实列出，避免给使用者错误的安全预期：

1. **缓存文件本身没有访问控制**。若服务器未正确配置，
   `wp-content/cache/at8-site-accelerator/` 下的 HTML 可能被直接访问。
   已放置 `index.php` 守卫防目录列表，但**不阻止直接访问已知文件名的缓存文件**。
   这与 WP Rocket / LiteSpeed 的行为一致（缓存页本身就是公开内容）。
   **缓存页中不得出现登录用户专属内容**——这也是"登录用户默认不缓存"的原因。

2. **REST API 无速率限制**。所有端点都要求 `manage_options`，
   且操作都是幂等的缓存失效，未做限流。若被恶意高频调用，
   影响是"缓存反复失效"而非数据泄露。

3. **数据库清理是不可逆操作**。预览功能会显示每项将删除的条数，
   但删除后无法恢复。默认全部关闭，必须由用户显式勾选。

4. **WebP 转换依赖 GD 或 Imagick**。两者都不可用时功能静默跳过，
   不会报错也不会中断上传。

5. **未做加密存储**。插件不保存任何密钥或凭证，
   Redis 连接参数通过 `wp-config.php` 常量或 filter 提供。

---

## 九、结论

- **P0（阻断发布）**：无。
- **P1（需修复）**：无。
- **P2（建议改进）**：缓存目录的 Web 服务器访问规则（依赖用户服务器配置，
  在「工具」标签页提供 nginx / Apache 片段）、REST 限流（当前判定为低优先级）。

审计发现的全部问题已在 3.0.0 修复，详见 `CHANGELOG.md` 的「修复」章节。

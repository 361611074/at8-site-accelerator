# AT8 License Server 规格

> 对应开发计划书 §20（Site Fingerprint）、§21（Staging）、§22（License API 安全）、
> §23（License 验证策略）、§24（License 离线容错）、§25（Pro Update Server）、
> §26（Update Package）、§43（Free / Pro 架构）。

---

## 0. 状态：本文件基于待确认假设

商业化决策尚未由用户拍板（见 `COMMERCE_OPEN_QUESTIONS.md`）。为避免"编造定价式的幻觉文档"，
本规格**只写不随决策改变的技术骨架**，并逐条列出所采用的默认假设。

| 编号 | 假设 | 出处 | 若被推翻，需要改的部分 |
| --- | --- | --- | --- |
| A1 | Pro 为**独立插件** `at8-site-accelerator-pro`，通过共享 Core API 与 Free 协作，**不修改 Free 任何核心文件** | §43 强制约束 + Q1 建议 | 目录结构、`Plugin::boot()` 服务登记方式 |
| A2 | 授权粒度为**站点级 license key**，一个 key 绑定 N 个站点席位 | `FREE_PRO_MATRIX.md`「Pro 版本形态」 | 席位模型、激活/停用接口 |
| A3 | 校验强度为**激活时校验 + 周期性心跳**，**不做每次页面访问校验** | §23 明确禁止每次访问校验 | 心跳周期与本地缓存策略 |
| A4 | 心跳周期默认 **7 天**，由服务器策略下发覆盖 | §23「7 天左右验证一次」 | 策略字段与客户端行为 |
| A5 | 离线宽限（Grace Period）默认 **30 天** | §24 + Q7 建议 | 降级判定逻辑 |
| A6 | 本地/开发/预发布环境**自动免授权且不消耗正式席位**，具体判定规则**服务器端可配置** | §21 强制要求 | 环境判定与席位扣减 |
| A7 | 更新分发走**自建更新服务器**，接口 `GET /api/v1/update` | §25 + Q9 | 更新链路 |
| A8 | Pro 代码采用**商业许可**（Free 保持 GPL-2.0-or-later） | Q13 | 本文件不受影响；影响 `LICENSE` 与分发条款 |
| A9 | **License Server Secret 绝不进入插件包** | §22 强制约束 | 密钥管理方案 |

> A1 与 A9 是计划书的硬性约束，**不可协商**；其余为待确认默认值。

---

## 1. 对象模型

```
License (许可证)
 ├── license_key        站点级密钥，一个 key 对应一个订单项
 ├── product_id         产品标识（at8-site-accelerator-pro）
 ├── plan               single | team | agency（席位档位，待 Q2 决策）
 ├── seats_total        席位数上限
 ├── seats_used         已占用席位
 ├── status             active | expired | revoked | refunded
 ├── expires_at         到期时间（订阅制）/ null（终身）
 └── sites[]           已激活站点

Site (站点)
 ├── site_uuid          插件首次激活生成的随机 UUID v4（存 option，不随域名变）
 ├── normalized_url     归一化后的站点 URL
 ├── fingerprint        sha256(normalized_url | site_uuid)
 ├── environment        production | staging | development
 └── last_seen_at       最后一次成功验证时间

Activation (激活记录)
 ├── license_key
 ├── fingerprint
 ├── activated_at
 ├── deactivated_at
 ├── last_validated_at
 └── grace_until         last_validated_at + grace_days
```

**为什么需要 `site_uuid` 而不只用 URL**：§20 明确要求处理
「网站迁移 / 域名更换 / HTTP→HTTPS / staging / localhost」。
仅用 `site_url` 会让域名一变就全部掉线；仅用 UUID 则无法识别"同一个站换了个域名"。
因此指纹 = 归一化 URL **与** 站点 UUID 的组合，并在服务端提供
Site Management 让用户可以手动解绑/迁移。

---

## 2. 站点指纹

### 2.1 URL 归一化

```php
function normalize_site_url( string $url ): string {
    $parts = wp_parse_url( strtolower( trim( $url ) ) );
    $host  = $parts['host'] ?? '';
    // 去 www.
    $host = preg_replace( '/^www\./', '', $host );
    // 去默认端口
    if ( isset( $parts['port'] ) && ! in_array( $parts['port'], [ 80, 443 ], true ) ) {
        $host .= ':' . $parts['port'];
    }
    // 子目录安装保留 path，去尾斜杠
    $path = rtrim( $parts['path'] ?? '', '/' );
    return $host . $path;   // 不含协议：HTTP → HTTPS 迁移不会掉授权
}
```

**要点**：归一化结果**不含协议**，因此站点从 HTTP 切到 HTTPS 时指纹不变。

### 2.2 指纹计算

```php
$fingerprint = hash( 'sha256', $normalized_url . '|' . $site_uuid );
```

`site_uuid` 由插件在首次激活时生成（`wp_generate_uuid4()`），存于 option，
**删除站点数据或重置插件才会变化**。

---

## 3. 环境类型判定（§21）

```php
function detect_environment( string $normalized_url ): string {
    $host = ...; // 归一化后的 host
    // 本地开发环境：强制 development
    if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true )
        || preg_match( '/\.(local|test|localhost)$/', $host ) ) {
        return 'development';
    }
    // 尊重 WordPress 官方环境类型
    if ( function_exists( 'wp_get_environment_type' ) ) {
        $type = wp_get_environment_type();   // production | staging | development | local
        if ( 'local' === $type )       { return 'development'; }
        if ( 'staging' === $type )     { return 'staging'; }
    }
    return 'production';
}
```

**席位扣减规则**（服务器端可配置，§21 要求"具体规则做成服务器端可配置"）：

| 环境 | 是否消耗席位 | 是否需要有效 license |
| --- | --- | --- |
| `development` | **否** | **否**（完全免授权） |
| `staging` | 可配置，**默认否** | 需要，但不占席位 |
| `production` | **是** | 需要 |

---

## 4. License API 安全（§22）

### 4.1 传输与限流

- **强制 HTTPS**，插件侧拒绝向 `http://` 端点发起任何授权请求；
- 服务端按 `fingerprint + IP` 双维度限流：
  - 激活：10 次 / 小时 / fingerprint
  - 验证：60 次 / 小时 / fingerprint
- 触发限流返回 `429`，插件**不得**将其解释为"授权失效"（见 §6 容错）。

### 4.2 请求签名

插件持有的是**每个 license 独立的 `license_key`**，
**不是** License Server 的全局 secret（§22 明确禁止 secret 进插件）。

请求头：

```
X-AT8-Key:        <license_key>
X-AT8-Timestamp:  <Unix 秒>
X-AT8-Nonce:      <随机 16 字节 hex>
X-AT8-Signature:  <hex hmac>
```

签名串（**严格按此顺序拼接，换行分隔**）：

```
timestamp
nonce
HTTP_METHOD 大写
path（含 query，原始顺序）
sha256( raw_request_body )
```

签名算法：`HMAC-SHA256( 签名串, license_key )`，输出小写 hex。

### 4.3 时间戳与重放保护

| 校验 | 规则 |
| --- | --- |
| 时间戳偏差 | `abs( server_time - timestamp ) <= 300`，超出返回 `408` |
| Nonce 去重 | Redis `SETNX nonce:<license>:<nonce>`，TTL 600 秒；已存在则返回 `409` 判定为重放 |

> Nonce TTL 必须**大于**时间戳窗口（600 > 300），否则窗口边缘的请求会被误判。

---

## 5. 验证策略（§23）

### 5.1 时序

```
激活（activate）
   ↓ 服务器返回签名授权 Token（含有效期与策略）
本地缓存（option + transient）
   ↓
周期性验证（默认 7 天，由服务器策略下发）
   ↓
成功 → 刷新 last_validated_at 与本地 Token
失败 → 进入 Grace Period，不立即降级
```

**明确不做**：每次页面访问都请求 License Server（§23 明令禁止）。
理由不只是性能——这会**把 License Server 的可用性直接变成客户网站的可用性**。

### 5.2 授权 Token

服务器签发的 Token 结构（服务端签名，插件只验签不解密）：

```json
{
  "license_key": "AT8SA-...",
  "fingerprint": "...",
  "product": "at8-site-accelerator-pro",
  "plan": "single",
  "issued_at": 1769000000,
  "valid_until": 1769600000,
  "next_check_at": 1769480000,
  "grace_days": 30,
  "policy_rev": 3
}
```

插件用服务器**公钥**验签（公钥随插件分发，非 secret）。

---

## 6. 离线容错（§24）

```
License Server Down
        ↓
本地 Token 仍在有效期内  → Pro 继续运行（正常路径）
        ↓
本地 Token 已过期
        ↓
now <= last_validated_at + grace_days  → Pro 继续运行（宽限）
        ↓
超出宽限  → 降级：Pro 功能停用，Free 功能不受影响
```

**降级行为必须温和**：

1. Pro 功能停用，**已生成的缓存与 Free 功能全部保留**；
2. 后台显示一次状态提示，**不弹窗、不阻断前台**；
3. 网络恢复后自动重新验证并恢复，无需用户操作。

> 反面模式（明确禁止）：License Server 一挂就让客户的缓存插件整体失效。
> 这会把一次基础设施故障放大成客户的业务事故。

**网络错误与授权错误必须区分对待**：

| 情况 | 判定 | 行为 |
| --- | --- | --- |
| 超时 / DNS 失败 / 5xx | **网络错误** | 进入宽限，**不**更新 `last_validated_at` |
| 401 / 403 / license revoked | **授权错误** | 立即降级，不等宽限 |
| 429 | 限流 | 指数退避重试，不降级 |

---

## 7. 更新服务（§25 / §26）

### 7.1 接口

```
GET /api/v1/update?product=at8-site-accelerator-pro&version=1.0.0&fingerprint=...
```

响应：

```json
{
  "version": "1.1.0",
  "download_url": "https://.../signed-url?expires=...&sig=...",
  "requires_php": "7.4",
  "requires_wp": "5.8",
  "tested": "6.9",
  "sha256": "..."
}
```

必须校验（§25 要求）：**License / Product / Version / Site** 四项全部匹配才返回下载地址。

### 7.2 下载链接

- **禁止**公开永久下载链接（§26）；
- 使用**短期签名 URL**，有效期 ≤ 15 分钟，一次性使用优先；
- 插件下载完成后**必须**比对 `sha256` 再解压，不匹配则中止并保留旧版本。

---

## 8. 接口一览

| 方法 | 路径 | 用途 | 幂等 |
| --- | --- | --- | --- |
| POST | `/api/v1/license/activate` | 激活站点，占用席位 | 否（重复激活同一指纹返回已有记录） |
| POST | `/api/v1/license/deactivate` | 解绑站点，释放席位（§20 要求必须提供） | 是 |
| POST | `/api/v1/license/validate` | 心跳验证，刷新 Token | 是 |
| POST | `/api/v1/license/renew` | 续期 | 否 |
| POST | `/api/v1/license/revoke` | 吊销（退款/争议时，服务端触发） | 是 |
| GET | `/api/v1/update` | 更新检查 | 是 |
| GET | `/api/v1/sites` | 站点管理（迁移/解绑，§20 要求） | 是 |

### 8.1 激活响应（成功）

```json
{
  "status": "active",
  "token": "<signed token>",
  "fingerprint": "...",
  "environment": "production",
  "seats": { "total": 1, "used": 1 },
  "grace_days": 30,
  "next_check_at": 1769480000
}
```

### 8.2 激活响应（席位耗尽）

```
HTTP 409
{
  "error": "seats_exhausted",
  "message": "该许可证的站点席位已用完，请先在其他站点停用。",
  "sites": [ { "fingerprint": "...", "url": "...", "last_seen_at": 1769000000 } ]
}
```

> 必须返回已占用站点列表（脱敏后的 URL），否则用户不知道该去哪个站点停用。

---

## 9. 与 Free 版的关系（§43）

```
at8-site-accelerator        （Free，GPL-2.0-or-later）
        ↑ 只读依赖
at8-site-accelerator-pro    （Pro，商业许可）
```

**硬约束**：Pro **不得**修改 Free 的任何核心文件。
Pro 只能：

- 读取 Free 的公开 Core API；
- 通过 Free 已注册的 action / filter 扩展行为；
- 在 Free 未安装时，使用自带的 Core API 副本独立运行。

**两版共装时的检测**：Pro 激活时检查 Free 是否存在，若存在则提示"Pro 已接管，
Free 的以下能力由 Pro 提供"，并**确保不出现两份缓存后端同时写**。

---

## 10. 待补充（依赖用户决策）

以下内容**必须**等 `COMMERCE_OPEN_QUESTIONS.md` 的对应问题有答案后才能写，
当前刻意留空，不做编造：

- **Q2 / Q5**：席位档位（单站点 / 3 站点 / 不限）、订阅还是买断 → 影响 `expires_at` 语义与续期流程；
- **Q6**：退款策略 → 影响 `revoke` 的触发条件与宽限处理；
- **Q12**：白标 → 影响授权返回是否携带品牌字段。

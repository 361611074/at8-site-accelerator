# AT8 Payment 测试报告

> 对应计划书 §73（Commerce 测试）/ §74（Payment Contract Tests）/ §75（E2E 支付测试）/
> §106（Sandbox）/ §138（完成标准）。配套：`PAYMENT_SPEC.md`、`LICENSE_TEST_REPORT.md`。

---

## 0. 结论（先读这个）

| 渠道 | 代码 | 契约测试 | 密码学路径验证 | 真实 Sandbox 联调 | 上线清单 |
| --- | --- | --- | --- | --- | --- |
| Stripe | ✅ | ✅ | ✅（本地 HMAC） | ❌ 待凭证 | ⬜ |
| PayPal | ✅ | ✅ | ✅（官方验签接口 mock） | ❌ 待凭证 | ⬜ |
| 支付宝 | ✅ | ✅ | ✅（本地 RSA2） | ❌ 待凭证 | ⬜ |
| 微信支付 | ✅ | ✅ | ✅（本地 RSA+AES-GCM） | ❌ 待凭证 | ⬜ |
| Mock 渠道 | ✅ | ✅ | —（本身即测试设施） | 不适用 | 不适用 |

**按 §138 的严格判据：Commerce Release = NOT READY**——四个核心渠道没有一个
完成真实 Sandbox 流程验证（PAYMENT_SPEC §0.2 的已知阻塞，凭证尚未提供）。
本报告如实区分"代码与自测证据"与"渠道联调证据"，不做任何混同。

---

## 1. 测试环境与工具

| 项 | 值 |
| --- | --- |
| 本机测试 | PHP 7.3.4（Windows，本机唯一可用 CLI），SQLite 内存/临时库 |
| 服务器测试 | PHP 8.3.33 / Linux / wordpress.xmm.fan 同机 |
| 测试套件 | `at8-commerce/tests/run.php`（自包含，零外部依赖） |
| 契约验证方式 | 本地生成 RSA 测试密钥对（`tests/keys/`，仅测试用）+ FakeTransport 拦截出站 HTTP |
| 真机 E2E | `.tmp/at8ec-e2e.php`（服务器执行，15 项断言） |
| 静态分析 | PHPStan 1.12.32 level 5，0 errors（`phpstan.neon.dist` 已入库） |

**方法声明**：密码学路径测试证明的是**我们这一侧**的签名/验签/解析逻辑正确；
它**不能**替代渠道联调。所有 FakeTransport 处理器在测试文件中显式登记，
不存在"把 mock 结果当成渠道返回"的路径。

---

## 2. 契约测试结果（§74：createOrder/queryOrder/handleWebhook/refund）

### 2.1 统一契约

四个渠道 + Mock 均实现 `PaymentGateway` 接口（PAYMENT_SPEC §1）：
`create_payment` / `parse_webhook` / `query_order` / `refund` / `id`。
新增渠道必须先过同一套契约测试（§74 的初衷）。

### 2.2 Stripe（Checkout Session + Webhook）

| 用例 | 结果 |
| --- | --- |
| create_payment 请求形状：金额=分、币种小写、client_reference_id=订单号、mode=payment | ✅ |
| Webhook 验签：正确签名通过（HMAC-SHA256 of `t.raw_body`） | ✅ |
| 篡改 body → 验签拒绝（400，不入库） | ✅ |
| 时间戳超窗（>300s）→ 拒绝 | ✅ |
| 事件映射：client_reference_id → 订单号；needs_query=true（金额不可信） | ✅ |
| query_order：Session → payment_status=paid + 金额/币种 | ✅ |
| refund：部分退款（payment_intent + amount） | ✅ |

### 2.3 支付宝（电脑网站支付 + 异步通知）

| 用例 | 结果 |
| --- | --- |
| 验签串：按 key 升序、**剔除 sign 与 sign_type**（§2.2 陷阱） | ✅ |
| create_payment：RSA2 签名跳转 URL、沙箱网关可配置、notify_url 已带 | ✅ |
| 非 CNY 订单拒绝（当前账户能力；§8 不写死"支付宝=CNY"进代码，由渠道配置表达） | ✅ |
| 合法通知（"支付宝侧"私钥签名）→ paid；total_amount 解析为分 | ✅ |
| 通知免回查（服务端断言），金额核对交由管线 | ✅ |
| 篡改金额后签名不匹配 → 验签拒绝 | ✅ |
| 改金额但重新签名 → 解析通过（金额核对责任在管线，见 §4） | ✅ |
| query（alipay.trade.query 响应键为下划线形式——实测修复的点） | ✅ |

### 2.4 微信支付（Native v3 + 回调）

| 用例 | 结果 |
| --- | --- |
| 请求签名：WECHATPAY2-SHA256-RSA2048（商户私钥 + 商户证书序列号） | ✅ |
| 回调验签：平台证书按 `Wechatpay-Serial` 选择 | ✅ |
| **未知证书序列号 → 拒绝**（证书轮换必须更新映射，不硬编码） | ✅ |
| AES-256-GCM 解密 resource（APIv3 密钥 + associated_data） | ✅ |
| 篡改回调体（签名失配）→ 拒绝 | ✅ |
| base64_decode 严格模式：非法密文拒绝（修复点：宽松模式会静默丢弃字符） | ✅ |
| 退款回调 REFUND.SUCCESS → payment.refunded + refund_id/amount 提取 | ✅ |

### 2.5 PayPal（Orders v2 + 官方验签接口）

| 用例 | 结果 |
| --- | --- |
| OAuth2 client credentials（进程内缓存） | ✅ |
| create_payment：intent=CAPTURE、reference_id=订单号、approve 链接提取 | ✅ |
| Webhook 验签**走官方接口**（/v1/notifications/verify-webhook-signature，不自拼签名） | ✅ |
| 验签 FAILURE → 拒绝 | ✅ |
| capture 事件提取 order_id（供回查）与金额（"49.00" → 4900 分） | ✅ |
| query_order：状态 + capture id | ✅ |

### 2.6 Mock 渠道（沙箱全链路设施）

- production 模式**强制拒绝**（§4），测试环境可用；
- 事件由本服务构造并以 server_secret HMAC 签名，进入与真实渠道**完全相同**的六步管线。

---

## 3. Webhook 六步管线（§73：每个渠道的 Webhook / Duplicate Webhook）

管线实现（PAYMENT_SPEC §2.1 顺序）：raw body → 验签 → 幂等（event_id 唯一索引）
→ 交易核实 → 订单状态机 → 200（支付宝回 `success` 文本）。

| 用例 | 结果 |
| --- | --- |
| 合法支付事件 → paid → 履约（license + 发票 + 邮件） | ✅ |
| **同一事件重放** → 200 且 duplicate=true，不产生第二笔 paid | ✅ |
| **不同事件**对已付订单再次"成功"（渠道双事件场景）→ 忽略并审计 | ✅ |
| 金额不符（1 分 vs 4900 分）→ 事件收下但**拒绝履约** + 审计（§55） | ✅ |
| 支付宝通知金额 1.00 ≠ 订单 299.00 → 拒绝 paid（验签通过也拦） | ✅ |
| 未知订单 → 200（不向渠道暴露存在性） | ✅ |
| 验签失败 → 400 且**不写业务审计**（防伪造请求污染审计流） | ✅ |
| 失败事件 → 订单回 pending（§37：订单 48h 内可换渠道重试） | ✅ |
| License Server 掉线 → 订单停留 **paid**（不丢单、不发 license）+ 审计 | ✅ |
| 恢复后 retry-fulfill → fulfilled 全链路补完 | ✅ |
| 订单超时清扫（expires_at）→ expired | ✅ |

## 4. 订单状态机（§54）

`pending → paid → fulfilled → refunding → refunded` 全部正向转换通过；
`pending → refunded`（跳步）、`paid → pending`、`refunded → paid`（§3.4 不可逆）
全部被拒并写审计。

## 5. E2E 真机链路（§75 的本服务可测部分）

服务器（wordpress.xmm.fan 同机）实跑，15/15 断言通过：

```
注册用户 → 创建订单（AT8-20260927-BFAGXK，4900 分）→ Mock 渠道支付事件
→ 六步管线（94.9ms）→ 订单 fulfilled
→ Provisioning 调真实 License Server（HTTP + admin HMAC）
→ license AT8SA-CPYKE-2BNDY-K4WHW-27HFG 创建
→ 形式发票 AT8-20260927-0001 → license 邮件（key 在正文，非 URL 参数）
→ 用插件同款 HMAC 签名方式向真实 License Server 激活 → 200 + Ed25519 Token
→ 心跳 validate 200 → 重放 webhook 事件幂等
```

**与生产链路的唯一差别**：支付事件由 Mock 渠道发出而非真实渠道回调。

## 6. 未完成（如实列出，§138）

| 项 | 阻塞 |
| --- | --- |
| 四渠道真实 Sandbox 联调（Stripe Test / PayPal Sandbox / 支付宝沙箱 / 微信测试） | 凭证未提供（§106 禁止索取生产私钥） |
| 真实渠道 Webhook URL 配置与端到端回调 | 同上 |
| 真实退款演练 | 同上 |
| 邮件真实发送（当前 log driver 落库/落盘） | 事务邮件服务商未选型（PAYMENT_SPEC §9） |
| Production Payment Checklist（§107） | 全部 ⬜，见 PAYMENT_SPEC §5 |

## 7. 安全核对（§69 / §104）

- 无硬编码密钥：渠道密钥只经 `Env` 读环境变量，`config.example.php` 无真实值 ✅
- 测试密钥对（tests/keys/）**明确标注仅测试用**，非任何环境的生产秘密 ✅
- 密钥不入库/不入日志/不入 ZIP ✅
- 验签失败不写业务审计、金额篡改拒绝履约、重放保护、CSRF、登录双桶限流 ✅
- IDOR：404 而非 403；资源查询强制 user_id 过滤 ✅（tests 第 5 节）

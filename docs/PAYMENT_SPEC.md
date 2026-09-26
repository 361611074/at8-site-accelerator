# AT8 Payment 规格

> 对应开发计划书 §97（Payments）、§98（Webhooks）、§105（Payment Secrets）、
> §106（Sandbox）、§107（Production Payment Checklist）、§111（区域支付推荐）、
> §112（Payment Failure UX）、§113–§115（邮件）。
>
> 配套：`COMMERCE_SPEC.md`（订单与履约）、`LICENSE_SPEC.md`（授权发放）。

---

## 0. 状态与已知阻塞

### 0.1 假设

| 编号 | 假设 | 出处 | 若被推翻 |
| --- | --- | --- | --- |
| C1 | 先建立**统一 Payment Adapter**，再逐个接渠道；接入顺序 **Stripe → PayPal → 支付宝 → 微信支付** | §97 明确要求 | 适配器接口与实现顺序 |
| C2 | 所有密钥走**环境变量 / Secret Manager**，**绝不入库、绝不进代码、绝不进 ZIP** | §105 强制 | 配置加载方式 |
| C3 | **所有开发阶段只用 Sandbox 凭证** | §106 强制 | 无 |
| C4 | 金额一律用**整数"分"**存储与传输 | 对账纪律 | 金额字段类型 |
| C5 | Webhook 为**权威履约依据**，前端跳转不作为支付成功凭证 | §98 + `COMMERCE_SPEC.md` §3 | 订单状态机触发源 |

### 0.2 已知阻塞：Sandbox 凭证

§106 要求"所有开发阶段"使用
Stripe Test / PayPal Sandbox / 支付宝沙箱 / 微信支付测试能力，
且明确规定 **不得要求开发人员提供真实生产私钥**。

因此**支付渠道的实际联调需要用户提供 Sandbox 凭证**：

| 渠道 | 需要的 Sandbox 凭证 | 获取难度 |
| --- | --- | --- |
| Stripe | Test 模式 `sk_test_...` + Webhook signing secret | 低（注册即有） |
| PayPal | Sandbox Client ID / Secret | 低（开发者后台生成） |
| 支付宝 | 沙箱 APPID + 应用私钥 + 支付宝公钥 | 中（需开放平台账号） |
| 微信支付 | 商户号 + API v3 密钥 + 商户私钥 | 高（需企业主体） |

**当前状态：尚未获取。**
**这不影响本规格与适配器接口的设计**，但会影响
`COMMERCE_OPEN_QUESTIONS.md` 之外的一件事：**无法出具"支付链路已跑通"的实证**。
在拿到凭证前，支付模块只能做到：
接口与状态机完备 + 本地 Mock 渠道（走完整链路但不发真实请求）+ 上线清单待勾选。

> 这是刻意保留的诚实缺口，不会用"假设已联调"来fake 掉。

---

## 1. 统一 Payment Adapter

```php
interface PaymentGateway {
    /** 创建支付意图，返回跳转/拉起所需的信息 */
    public function create_payment( Order $order, array $args ): PaymentIntent;

    /** 校验并解析 Webhook 原始请求体 */
    public function parse_webhook( string $raw_body, array $headers ): WebhookEvent;

    /** 主动查询订单真实状态（对账与补偿用，§107 要求 Order Query） */
    public function query_order( string $gateway_txn_id ): OrderStatus;

    /** 发起退款（§107 要求 Refund） */
    public function refund( string $gateway_txn_id, int $amount_minor, string $reason ): RefundResult;

    public function id(): string;            // 'stripe' | 'paypal' | 'alipay' | 'wechat'
}
```

**设计要点**：

1. 适配器**只做渠道差异**，订单状态机与履约逻辑**不属于**适配器；
2. `query_order()` 必须实现——回调丢失时它是唯一的对账手段；
3. `refund()` 的 `$amount_minor` 支持部分退款；
4. 所有方法**不得**吞掉异常，网络类异常要可区分于业务失败。

---

## 2. Webhook 接收（§98）

### 2.1 处理顺序（顺序错了会出安全与幂等问题）

```
1. 读取原始请求体（raw body，不能读解析后的 $_POST）
       ↓  签名校验必须基于原始字节
2. 验签（渠道各自的签名算法）
       ↓  失败 → 400，不记业务日志（避免伪造请求污染审计）
3. 查重（幂等）：event_id 唯一索引
       ↓  已处理过 → 200，直接返回（不重复履约）
4. 交易核实（Transaction Verification）
       ↓  不信任 body 里的金额，调 query_order 或校验渠道返回的真实状态
5. 驱动订单状态机（见 COMMERCE_SPEC.md §3）
6. 返回 200
```

### 2.2 各渠道验签要点

| 渠道 | 验签方式 | 关键陷阱 |
| --- | --- | --- |
| Stripe | `Stripe-Signature` 头 + Webhook Signing Secret，HMAC-SHA256，含 timestamp | 必须用 **raw body**；重放窗口默认容忍 5 分钟 |
| PayPal | 调用渠道 API 校验 `transmission_id` / `cert_id` / 签名链 | 不要自己拼签名，**用官方校验接口** |
| 支付宝 | 公钥验签 `sign`（RSA2），参数按规则排序后拼接 | 必须**排除 `sign` 与 `sign_type`** 再参与验签 |
| 微信支付 | API v3：`Wechatpay-Signature` + 平台证书 + 序列号 | 需处理**平台证书轮换**，不能硬编码证书 |

### 2.3 幂等

```sql
CREATE TABLE webhook_events (
  event_id      VARCHAR(128) PRIMARY KEY,   -- 渠道事件 ID
  gateway       VARCHAR(32) NOT NULL,
  order_id      BIGINT,
  processed_at  DATETIME,
  payload_hash  CHAR(64)
);
```

- `event_id` 主键即幂等防线：重复投递在 INSERT 时冲突，捕获后返回 200；
- **不要用"订单状态是否已变更"做幂等**——并发下会漏判；
- 同一 `gateway_txn_id` 的多次回调（如 `payment_intent.succeeded` 与 `charge.succeeded`）
  需按业务语义去重，只让一次进入 `paid`。

---

## 3. 密钥管理（§105）

### 3.1 必须走环境变量 / Secret Manager

```
STRIPE_SECRET_KEY
STRIPE_WEBHOOK_SECRET
PAYPAL_CLIENT_ID
PAYPAL_CLIENT_SECRET
ALIPAY_APP_ID
ALIPAY_PRIVATE_KEY
ALIPAY_PUBLIC_KEY
WECHAT_MCH_ID
WECHAT_API_KEY
WECHAT_PRIVATE_KEY
WECHAT_CERT
```

> 计划书注明"这些名称只是示例，实际实现以 Provider 最新官方 SDK / API 要求为准"。

### 3.2 硬性纪律

| 规则 | 说明 |
| --- | --- |
| 不入库 | 密钥**绝不**写入数据库或 option 表 |
| 不进代码 | 不出现在任何 PHP 文件、配置文件、注释里 |
| 不进 ZIP | 打包白名单已排除 `.env` `.env.*`（见 `RELEASE_CHECKLIST.md` 与 §87） |
| 不进日志 | 日志脱敏：密钥、完整卡号、`sign` 字段一律不落盘 |
| 不进前端 | 只有 publishable key / client id 可下发到浏览器 |
| 轮换 | 支持不重启轮换；微信平台证书必须支持热更新 |

---

## 4. Sandbox 策略（§106）

- 配置开关：`AT8_PAYMENT_MODE=sandbox|production`，**默认 sandbox**；
- Sandbox 模式下：使用测试密钥、测试商户号，**产生的订单不得发放真实 license**
  （发放的测试 license 需带 `is_test` 标记，且不能被生产环境验证通过）；
- **禁止**为测试方便而使用生产私钥（§106 明文禁止）；
- 每个渠道必须有一个 Mock 实现，供无凭证时跑通全链路（见 §0.2）。

---

## 5. 上线前检查清单（§107）

| 项 | 检查内容 | 当前状态 |
| --- | --- | --- |
| Merchant Account | 商户账号已开通并通过渠道审核 | ⬜ 待凭证 |
| Webhook URL | 已在渠道后台配置，HTTPS 可达 | ⬜ 待凭证 |
| HTTPS | 全站 HTTPS，回调地址不接受 http | ✅ 可提前完成 |
| Signature | 四渠道验签均已实现并有测试覆盖 | ⬜ 待联调 |
| Refund | 全额与部分退款均已跑通 | ⬜ 待凭证 |
| Order Query | 主动查单可用，回调丢失时可补偿 | ⬜ 待联调 |
| Currency | 币种与最小收款额校验正确 | ✅ 可提前完成 |
| Tax | 税率与展示符合区域要求 | ⬜ 待 Q4 / 主体确认 |
| Email | 支付成功 / 续费 / 退款邮件均已发送验证 | ⬜ 待邮件服务 |
| License | 支付成功 → license 发放 → 可激活，全链路通 | ⬜ 待联调 |

**"全部验证"是上线前置条件**（§107 原文），不允许部分通过即上线。

---

## 6. 支付失败 UX（§112）

**不要显示**无意义的 "Payment Error"。

**要显示**：

```
支付未完成

订单仍然有效。
您可以重新选择支付方式。
```

并同时提供三个出口：**Retry Payment** / **Change Payment Method** / **Contact Support**。

补充要求：

- 失败页面**必须显示订单号**，方便用户与客服沟通；
- 用户放弃支付后，订单保留**至少 24 小时**，期间可回来继续支付；
- 因渠道返回的错误（余额不足、风控拦截）应给出**渠道给出的可公开原因**，
  不要把渠道的技术错误码直接抛给用户。

---

## 7. 区域支付推荐（§111 / §97）

| 区域 | 主渠道 | 备渠道 |
| --- | --- | --- |
| 北美 / 欧洲 / 大洋洲 | Stripe | PayPal |
| 中国大陆 | 支付宝 | 微信支付 |
| 东南亚 / 拉美 | PayPal | Stripe |
| 其他 | PayPal | Stripe |

> 上表为**建议**，最终以用户确认的市场优先级（Q3）与各渠道实际开通情况为准。

**渠道选择逻辑**：

1. 账单国家命中区域 → 展示对应主/备渠道；
2. 未命中或用户手动切换 → 展示全部可用渠道；
3. 渠道不可用（未配置密钥 / 维护中）→ **隐藏而非置灰禁用**（置灰会引来"为什么不能用"的咨询）。

---

## 8. 邮件（§113–§115）

### 8.1 License 邮件（支付成功）

主题：`Your AT8 Site Accelerator Pro license is ready.`

必须包含：Product / Plan / License / Sites / Expires / Download / Documentation。

**硬性要求（§113）**：**不要把 License Key 作为 URL 参数直接暴露**。
原因：URL 会进入浏览器历史、Referer、日志、代理缓存。
正确做法是邮件正文给出 key，下载链接单独走短期签名 URL。

### 8.2 其它邮件

| 场景 | 时机 | 要点 |
| --- | --- | --- |
| 退款（§114） | 退款完成 | 说明生效范围与 license 吊销时间 |
| 续费（§115） | 扣款前提醒 + 扣款后确认 | 扣款前提醒必须提供"取消订阅"入口 |

---

## 9. 待补充（依赖外部条件）

- **Sandbox 凭证**：四渠道均未提供，联调与清单勾选无法完成（见 §0.2）；
- **Q4 / 商户主体**：决定能否开通微信支付与开具正式发票；
- **Q6 退款策略**：决定退款是人工审批还是自动执行；
- **邮件服务商**：SMTP / API 通道未选定，邮件发送验证待定。

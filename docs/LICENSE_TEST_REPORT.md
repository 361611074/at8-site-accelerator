# AT8 License 测试报告

> 对应计划书 §76（License E2E）/ §95（License Server）/ §138（完成标准）。
> 配套：`LICENSE_SPEC.md`、`PAYMENT_TEST_REPORT.md`。
>
> 本报告汇总两轮证据：Phase 5 首轮真机联调（2026-09-26，33 项断言）与
> Phase 6-9 本轮 Commerce 履约链路联调（2026-09-27，E2E 15 项断言）。

---

## 0. 结论

| 链路 | 状态 | 证据 |
| --- | --- | --- |
| Pro 插件 ↔ License Server（激活/验签/绑定/心跳/更新/下载） | ✅ 真机通过 | Phase 5 E2E 33 断言（2026-09-26） |
| Commerce → License Server（支付履约 → license 创建 → 插件式激活） | ✅ 真机通过 | 本轮 E2E 15 断言（2026-09-27） |
| License Server 领域逻辑与安全层 | ✅ 单测 69/69 | `at8-license-server/tests/run.php` |
| License Server 性能（API 延迟） | ✅ | validate avg 13.98ms / p95 18.22ms（见 PERFORMANCE_BENCHMARK.md） |
| Grace Period / 离线容错（服务端侧） | ✅ 单测覆盖 | Token 有效期 + grace_days 字段与 401/403 vs 429/5xx 语义 |
| Grace Period / 离线容错（插件侧真机断网演练） | ⚠️ 部分 | 客户端逻辑已实现（LicenseClient/TokenStore），真机断网场景未单独演练 |

---

## 1. License Server 单元/端到端套件（69/69）

零依赖自包含套件（`php tests/run.php`），本机 PHP 7.3 与服务器 PHP 8.3.33 双通过：

| 组 | 覆盖 |
| --- | --- |
| A. 存储层（3） | 幂等建表、upsert 单键/复合键 |
| B. 安全层（11） | HMAC 签名往返、篡改拒绝、raw body 参与签名、时间戳窗口（±299 边界）、缺头、nonce 重放 409、**Nonce TTL(600) > 窗口(300)**、双层限流、指纹格式 |
| C. Token（4） | Ed25519 签发/验签往返、载荷/签名篡改失败、两段 base64url 结构 |
| D. 许可证领域（20） | 密钥格式与唯一性、激活幂等、席位耗尽 409 带站点列表、停用释放、重激活不翻倍、未激活心跳 409、产品不匹配 403、过期 403、终身许可、development 免席位**不免校验**、site_list 只回主机名、审计日志、不限席位 |
| E. 更新服务（12） | 无更新/有更新、语义化版本比较（1.10.0 > 1.9.0）、未激活/过期/产品不匹配拒绝、下载签名绑定 product+version+fingerprint+expires、过期 410、签名错 403 |
| F. HTTP 端到端（19） | health 无鉴权、404、缺 key 401、激活端到端（含 Token+席位）、指纹非法 400、重放 409、限流 429 带 Retry-After、GET query 参数、admin renew/revoke 鉴权、**v1.1.0 admin license/create（创建闭环、测试许可证默认拒绝、seats null=不限、冒充管理密钥 401）** |

## 2. v1.1.0 新增：Commerce 履约端点

`POST /api/v1/admin/license/create`（admin_secret HMAC，与 renew/revoke 同一套鉴权）：

- 请求：product（必填）/ plan / seats（缺省 1，**显式 null = 不限**）/ duration_days / email / order_number（审计）/ is_test；
- `is_test=1` 在 `allow_test_licenses=false`（默认）时**拒绝**——生产配置下
  沙箱订单的测试许可证绝不能发给真实用户（PAYMENT_SPEC §4）；
- 创建后立即可激活（闭环断言 F14）。

## 3. 真机端到端（Commerce → License Server → 可用 license）

服务器实跑（`.tmp/at8ec-e2e.php`，15/15）：

| 步骤 | 结果 |
| --- | --- |
| Mock 渠道支付事件 → 六步管线 → 订单 fulfilled | ✅（94.9ms，含 license HTTP 履约） |
| license_links 落库（镜像，is_test 标记） | ✅ |
| 形式发票 + license 邮件（key 在正文，非 URL 参数，§113） | ✅ |
| **插件同款 HMAC 签名**向真实 License Server 激活 | ✅ 200（22.9ms） |
| 返回 Ed25519 Token（两段 base64url 签名串，非 JSON） | ✅ |
| next_check_at 在未来（7 天心跳策略，§23） | ✅ |
| 心跳 validate 200 | ✅（13.7ms） |
| 重放同一 webhook 事件 → duplicate 幂等 | ✅ |

测试过程中验证过的防线（均为故意触发并确认按设计拒绝）：
- nonce 复用 → 409 重放拒绝（客户端每次请求必须生成新 nonce）；
- validate 限流（60/小时/指纹）与 IP 层限流（600/小时）先后触发 429；
- 未激活指纹直接 validate → 409 not_activated。

## 4. Phase 5 首轮真机联调（2026-09-26，33 断言，沿用证据）

- systemd 服务 `at8-license-server.service`（127.0.0.1:18090，enabled）；
- Pro 插件 v1.1.0 部署测试站，公钥经生产真实路径注入插件主文件；
- 激活 → 验签 → license/fingerprint/product 三项绑定 → 篡改令牌 status 立即变 none →
  换指纹/换许可证被拒 → 心跳 active → 更新检查检出 1.2.0 → 签名下载 sha256 一致 →
  篡改下载地址被拒 → WP 更新列表注入成功。

## 5. 已知事项与遗留

| 项 | 状态 |
| --- | --- |
| 2026-09-27 一次配置重建事故（正则替换损坏线上 config.php） | 已修复：ed25519 密钥对/密钥值原样恢复，health 报 token_alg=ed25519 确认插件侧公钥仍配对；后续部署脚本改为"整文件生成 + lint 后推送"，不再对线上文件做正则手术 |
| 插件侧断网宽限演练 | 待做（服务端 5xx/429 语义与 401/403 降级语义已单测锁定） |
| 多站点席位真实场景 | 单测覆盖（D2-D5、D20），真机多站未演练 |
| 续费（renew）真实支付回调链路 | 依赖支付渠道联调（见 PAYMENT_TEST_REPORT §6） |

## 6. 完成度判定（§138）

License 体系本身（License Server + Pro 插件 + Commerce 履约）在**测试环境**内
已完成真实 HTTP 链路验证，具备 ACCEPT 条件；但按 §107/§138 的上线前置
（支付渠道 Sandbox 未联调、邮件通道未接），**整体仍为 NOT READY**，
与 PAYMENT_TEST_REPORT §0 的判定一致。

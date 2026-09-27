# AT8 Global Release Report

> 计划书 §134 要求的最终汇报格式。生成时间：2026-09-27。
> 本报告**只陈述可验证的事实**；每项结论对应可复跑的命令或已入库的报告文档。

## WordPress Free

Version: 3.0.1
Status: **已发布**（GitHub tag `v3.0.1` + Release，ZIP 资产 151160 字节）
- 真机基准：首页 TTFB 316.7 → 52.3ms（−83.5%），命中路径 DB 查询净 0
- PHPCS 0/0；自研冒烟套件 236/0；`dist/at8-site-accelerator-3.0.1.zip` 覆盖安装复验通过
- 详情：`PHASE_REPORT.md`、`PERFORMANCE_BENCHMARK.md`、`TEST_REPORT.md`

## WordPress Pro

Version: 1.1.0
Status: **代码完成，真机链路验证通过，未发布**
- 20 个 PHP 文件（License/Preload/CSS/JS/CDN/Analytics/Advanced DB/Updater）
- Phase 5 真机 E2E 33 断言通过：激活→验签→三项绑定→心跳→更新→签名下载
- 刻意未做（依赖基础设施，如实记录）：Critical CSS、未用 CSS 移除、字体优化、LQIP、AVIF、白标、多站点网络管理

## Commerce

Version: 1.0.0
Status: **代码完成 + 自测通过，Release = NOT READY**（支付 Sandbox 未联调，见 §Payments）
- 零依赖 PHP（与 License Server 同形态），SQLite/MySQL 双兼容
- 商品/套餐/优惠券/订单状态机/结账/Webhook 六步/Customer Center/Admin API/发票/下载
- 测试：141/141 断言（本机 7.3 + 服务器 8.3 双跑）；PHPStan level 5 = 0 errors
- 真机部署：`/www/at8-commerce`，E2E 15/15（结账→webhook→履约→license→激活）

## License Server

Version: 1.1.0
Status: **已部署（真机 systemd 服务）+ 单测通过**
- v1.1.0 新增 `POST /api/v1/admin/license/create`（Commerce 履约；测试许可证默认拒绝）
- 测试：69/69 断言；PHPStan level 5 = 0 errors（动态分发 dead catch 带理由豁免）
- 基准：validate p95 18.22ms；health p95 0.72ms

## Payments

Stripe: 适配器完成，契约测试通过（本地 HMAC 验签路径），**Sandbox 未联调**
PayPal: 适配器完成，契约测试通过（官方验签接口路径），**Sandbox 未联调**
Alipay: 适配器完成，契约测试通过（本地 RSA2 密钥对路径），**Sandbox 未联调**
WeChat Pay: 适配器完成，契约测试通过（本地平台证书 + AES-GCM 路径），**Sandbox 未联调**
Mock 渠道: 可用（沙箱全链路设施；production 强制拒绝）

## Security

Status: 静态与运行时核查通过，细节见 `SECURITY_AUDIT.md` 与 `PAYMENT_TEST_REPORT.md` §7
- 合规项（§104 禁止事项）全部遵守：无硬编码密钥、不收集文章/访客数据、无远程分析、
  不自动改 WP Core、不自动停用他人插件、支付密钥只在环境变量
- Webhook 六步：验签→幂等→金额/币种核实→状态机→履约；验签失败不入业务审计
- IDOR 404 而非 403；角色矩阵（§118）服务端强制；高风险操作二次确认 + 审计
- 渠道验签陷阱全部落实：支付宝剔除 sign/sign_type、微信平台证书轮换 + AES-GCM、
  Stripe raw-body HMAC + 时间窗、PayPal 官方验签接口

## Tests

PHPUnit: 未引入（自包含断言套件覆盖同等逻辑；差异见 §Known Issues）
PHPCS: Free 插件 0 错误 / 0 警告（WPCS 3.4.1）
PHPStan: level 5 —— commerce 0 errors / license-server 0 errors（1 条带理由豁免）
E2E: License/Commerce 链路真机 15/15 + Phase 5 插件链路 33 项
Payment Sandbox: **未执行**（四渠道凭证缺失，§106 禁止索取生产私钥）
License: 69/69 单测 + 真机链路验证

## Performance

Before（无缓存基线）: 首页 TTFB 316.7ms / 回源 580.8ms
After（Free 3.0.1）: 首页 TTFB 52.3ms（−83.5%）/ 回源 618.4ms（+6.5%，10% 容差内）
Commerce/License: validate p95 18.22ms；webhook 履约 p95 87.09ms；plans p95 0.53ms
（Commerce 侧基准为 Phase 11 补齐，见 PERFORMANCE_BENCHMARK.md 第 8 节）

## Known Issues

- **PHPUnit 未接入**：Free Release Gate §130 的 PHPUnit 项未满足，以 236 断言自研
  冒烟套件 + PHPStan + PHPCS 替代覆盖；接入 PHPUnit 需 WP 测试引导环境（wp-env），
  属后续工作。
- **支付渠道 Sandbox 凭证缺失**（PAYMENT_SPEC §0.2）→ 按计划书 §138，
  Commerce Release = NOT READY；这是当前唯一阻断发布的事项。
- **邮件通道为 log driver**：生产需接事务邮件服务商（§36），未选型。
- **发票为形式发票（proforma）**：税务主体未决（Q4）前不开正式税务发票（§32/§33）。
- **Pro 高级优化项未做**：Critical CSS（需无头浏览器）、Unused CSS 移除等，
  见 Pro 节；属功能范围而非缺陷。
- **.github/workflows/ci.yml 未推送**：OAuth token 缺 workflow scope；
  CI 定义暂存于 `.github/ci-workflow.yml.disabled`，补 scope 后移回即生效。
- **定价未决策**：seed.php 中的价格为建议值（COMMERCE_SPEC B3），上线前必须运营确认。
- **WordPress.org 发布未开始**：Free 版待人工复核清单（RELEASE_CHECKLIST 第九节）收尾。

## Release Decision

READY / **NOT READY**

判定依据（§129/§138，逐项可复核）：
1. Commerce 四渠道无一完成真实 Sandbox 流程验证 → Commerce = NOT READY（阻断项）；
2. Free Release Gate 缺 PHPUnit 项 → Free 按严格口径 NOT READY（其余项全过）；
3. Pro 依赖 Commerce/更新服务器对外发布，随 Commerce 一同暂缓。

**解除路径**（按依赖顺序）：
① 提供 Stripe/PayPal Sandbox 凭证 + 支付宝沙箱 → 填入环境变量 → 按
`PAYMENT_SPEC §5` 清单逐项联调 → 重跑 `PAYMENT_TEST_REPORT` 矩阵；
② 接入 PHPUnit（wp-env）跑通 Free Gate 剩余项；
③ 邮件服务商选型接入后勾选 Email 项。
三项完成后，本报告可升级为 READY 并执行 Phase 12 发布流程（当前已完成打包预演）。

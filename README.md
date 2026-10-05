# AT8 Site Accelerator

轻量级 WordPress 整页缓存与性能优化插件。以「缓存命中路径尽可能短」为设计前提，
优先 Redis 对象缓存、不可用时自动降级为磁盘缓存，内置 Elementor / WooCommerce
兼容层与第三方缓存插件冲突检测。

- 当前版本：**3.0.5**
- 环境要求：**PHP 7.4+ / WordPress 5.8+**
- 许可：**GPL-2.0-or-later**

---

## 功能

### 页面缓存
- **磁盘缓存**：缓存目录即 URL 布局 `cache/at8-site-accelerator/<host>/<path>/index.html`
- **Redis 缓存**：纯 PHP RESP 协议客户端实现，不依赖 phpredis 扩展
- **drop-in 早期命中**：通过 `advanced-cache.php` 在 WordPress 完全启动前直接吐出缓存
- **移动端变体**：`__m/index.html` 独立缓存
- **查询串变体**：`q-<md5前10>/index.html`
- **原子写入**：临时文件 + rename，避免并发写坏缓存
- **压缩安全阀**：HTML 压缩后体积低于原始 40% 时自动放弃压缩结果

### 精准失效
取代「整站清空」的粗暴做法，支持按 URL / 文章 / 首页 / 归档 / 分类法 / 作者
分别失效；单次批量失效上限 300 个 URL，超出时自动升级为整站清空。

### 前端优化
- 原生懒加载（`loading="lazy"` + `decoding="async"`），首屏前 2 张图片标记为 eager
- HTML 压缩（块级元素保护，`pre` / `textarea` / `script` / `style` 不参与压缩）
- 浏览器缓存响应头 + 规则片段生成
- DNS 预取 / 预连接 / 资源预加载
- 链接预取（instant.page 风格，每个链接独立计时器）
- WebP 上传即转
- 前端冗余清理（emoji、embeds、generator 等）

### 数据库瘦身
7 类清理项，默认全部关闭，需手动开启。

### 兼容与诊断
- Elementor：CSS 就绪护栏 + `register_shutdown_function` 异步重建
- WooCommerce：动态页面 `DONOTCACHEPAGE` 标记 + 库存变化精准失效
- 11 款第三方缓存插件冲突检测
- 8 组环境体检（只报告事实，不做评分）

---

## 安装

1. 将 `at8-site-accelerator` 目录放入 `wp-content/plugins/`
2. 在 WordPress 后台「插件」页面启用
3. 启用后自动写入 `advanced-cache.php` drop-in 并改写 `wp-config.php`
   中的 `WP_CACHE` 常量（改写前只做临时备份，写入校验不通过即回滚，
   无论成败都会立即删除该临时文件，不在磁盘上留明文副本）
4. 进入「设置 → AT8 Site Accelerator」调整参数

也可直接使用打包好的发行版：

```
php tools/build-zip.php
# 产出 dist/at8-site-accelerator-3.0.5.zip
```

---

## 目录结构

```
at8-site-accelerator/
├── at8-site-accelerator.php     # 插件入口（常量、环境门槛、自动加载）
├── uninstall.php                # 卸载逻辑（默认保留数据）
├── includes/
│   ├── Core/                    # 容器、插件主类、设置中枢、激活/停用
│   ├── Support/                 # 日志、文件系统、Redis 客户端
│   ├── Cache/                   # 缓存引擎、路径、请求守卫、后端实现
│   ├── Purge/                   # 失效器与钩子绑定
│   ├── Optimization/            # 压缩、懒加载、浏览器缓存、WebP、预加载
│   ├── Compatibility/           # Elementor / WooCommerce / 缓存插件检测
│   ├── Diagnostics/             # 环境体检
│   ├── Admin/                   # 设置页、AJAX、工具条、通知
│   └── REST/                    # REST 控制器
├── templates/                   # advanced-cache.php drop-in、设置页模板
├── assets/                      # 后台 CSS / JS、前端预加载脚本
├── languages/                   # 翻译模板
├── docs/                        # 架构、安全、兼容性、测试等文档
├── tests/unit/                  # 冒烟测试（含 WordPress 函数桩）
└── tools/                       # 打包与 .pot 生成脚本
```

---

## Free / Pro

本仓库是 **Free 版**，功能完整、可独立运行：

```text
独立安装 · 独立激活 · 不依赖 Pro · 无需注册账号 · 无需授权 · 不连接任何外部服务器
```

Free 版不包含任何授权校验代码，也不会自动下载或安装任何东西。

Pro 版是**可选**的商业增强版本，功能边界见
[`docs/FREE_PRO_MATRIX.md`](docs/FREE_PRO_MATRIX.md)（基于两个仓库的真实代码整理）。
Pro 只能在官网产品页由用户主动获取、手动上传安装：

```text
Free 设置页「高级版」 → 用户点击链接 → 浏览器打开产品页 → 用户主动购买 / 下载
→ 后台手动上传安装
```

Free 版对 Pro 的介绍是**完全静态**的：设置页里一个说明区域 + 一个普通链接，
不使用全局后台通知、不弹窗、不自动跳转、不做用户追踪。

---

## 开发

### 运行测试

无需 WordPress 环境，测试自带函数桩：

```bash
php tests/unit/smoke.php
php tests/unit/round2-integration.php
php tools/check-upgrade-notice.php
```

冒烟测试 334 项（静态断言，含 drop-in 命中路径的子进程测试），
第二轮集成验收 73 项（行为断言：逐个后台页面验证通知作用域、
真实写/回滚 `wp-config.php` 后确认无临时备份残留、
验证删除临时备份确实走 `wp_delete_file()` 且失败时记 error 日志而非静默、
`realpath()` 实测 Host 归一化）。

`check-upgrade-notice.php` 校验 `readme.txt` 的 Upgrade Notice 每个版本条目
不超过 Plugin Check 的 300 字符上限 —— 该项在后台只报 WARNING，不会让 CI 变红，
但超限会被审核打回，所以自己先卡一道。

### 生成翻译模板

```bash
php tools/make-pot.php
```

### 打包发行版

```bash
php tools/build-zip.php
```

白名单式收录并回读自检，自动排除 `tests/`、`docs/`、`tools/`、`.github/`。

---

## 文档

| 文件 | 内容 |
|---|---|
| `docs/ARCHITECTURE.md` | 目录结构、请求生命周期、缓存布局、扩展点 |
| `docs/PRODUCT_SPEC.md` | 产品定位、全部功能清单与默认值、REST API |
| `docs/FREE_PRO_MATRIX.md` | Free / Pro 功能边界（基于真实代码审计）与划分理由 |
| `docs/FREE_PRO_ROADMAP.md` | Free / Pro 后续规划（尚未实现的设想，非现有功能） |
| `docs/SECURITY_AUDIT.md` | 路径穿越、CSRF、文件写入、日志脱敏、Redis 安全 |
| `docs/COMPATIBILITY.md` | 运行环境、主题/插件兼容、多站点、主机环境 |
| `docs/TEST_REPORT.md` | 测试策略、覆盖清单、已修复问题表 |
| `docs/PERFORMANCE_BENCHMARK.md` | 性能测量方案与验收标准 |
| `docs/RELEASE_CHECKLIST.md` | 发布检查清单 |
| `docs/COMMERCE_OPEN_QUESTIONS.md` | 商业化待决问题 |

---

## 许可

GPL-2.0-or-later，详见 [LICENSE](LICENSE)。

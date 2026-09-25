# 架构说明

> 对应开发计划书 §44（目录分层）、§62（Cache Engine）、§64（失效策略）、§68（兼容层）。

## 一、目录结构

```
at8-site-accelerator/
├── at8-site-accelerator.php      插件入口：常量、环境门槛、自动加载、生命周期钩子
├── uninstall.php                 卸载脚本（默认保留数据）
├── includes/
│   ├── Core/                     启动与设置
│   │   ├── Container.php             极简 DI 容器（惰性单例）
│   │   ├── Settings.php              设置中枢：默认值 / 清洗 / 迁移
│   │   ├── Plugin.php                主类：登记服务、分阶段 boot
│   │   ├── Activator.php             激活：建目录 → 迁移 → 写配置 → 装 drop-in
│   │   └── Deactivator.php           停用：只摘 drop-in + 取消定时任务
│   ├── Cache/                    缓存读写
│   │   ├── CachePath.php             ★ 零 WP 依赖：缓存键与磁盘路径推导
│   │   ├── RequestGuard.php          ★ 零 WP 依赖：请求级准入判断
│   │   ├── CacheEngine.php           MISS 路径：输出缓冲 → 校验 → 压缩 → 落盘
│   │   ├── Config.php                运行时配置 → config/<host>.php
│   │   ├── AdvancedCache.php         drop-in 安装/卸载 + wp-config.php 安全改写
│   │   └── Backend/
│   │       ├── BackendInterface.php
│   │       ├── DiskBackend.php       目录即 URL
│   │       ├── RedisBackend.php      站点盐前缀 + 索引集合 + SCAN 兜底
│   │       └── BackendFactory.php    后端选择 + 探测缓存 + 版本盐
│   ├── Purge/                    失效
│   │   ├── Purger.php                purge_all / url / post / home / archive / taxonomy / author
│   │   └── PurgeActions.php          WordPress 事件 → 精准失效的映射
│   ├── Optimization/             前端优化
│   │   ├── HtmlMinifier.php          块级内容保护 + 压缩率安全阀
│   │   ├── LazyLoad.php              原生 loading="lazy"，首屏提权
│   │   ├── BrowserCache.php          响应头 + nginx/Apache 规则片段
│   │   ├── DatabaseCleanup.php       7 类清理项，默认全关，先预览
│   │   ├── Webp.php                  上传即转，PNG 保 alpha
│   │   ├── LinkPreloader.php         悬停预取（区别于 Pro 的缓存预热）
│   │   └── FrontendCleanup.php       emoji/embeds/generator/… 前后台精简
│   ├── Compatibility/            第三方兼容
│   │   ├── ElementorCompat.php       三层防护
│   │   ├── WooCommerceCompat.php     动态页标记 + 库存失效
│   │   └── CachePluginDetector.php   11 款缓存插件冲突检测
│   ├── Diagnostics/
│   │   └── Diagnostics.php           8 组环境体检（不评分）
│   ├── Admin/
│   │   ├── SettingsPage.php          7 标签设置页
│   │   ├── Ajax.php                  14 个 admin-ajax 动作
│   │   ├── AdminBar.php              管理栏一键清缓存（fetch POST）
│   │   └── Notices.php               只在需要用户决策时提示
│   └── REST/
│       ├── CacheController.php       DELETE /cache、POST /cache/url、GET /cache/status
│       ├── SettingsController.php    GET/POST /settings
│       └── DiagnosticsController.php GET /diagnostics
├── templates/
│   ├── advanced-cache.php        drop-in 模板（{{AT8SA_PATH}} 占位符）
│   └── settings-page.php         设置页模板
├── assets/
│   ├── css/admin.css
│   └── js/{admin.js, preloader.js}
├── languages/at8-site-accelerator.pot
├── tests/unit/                   冒烟测试 + WP 函数桩 + drop-in 子进程夹具
└── tools/                        make-pot.php / build-zip.php
```

★ = **零 WordPress 依赖**。这两个文件是唯一允许被 drop-in 直接 `require` 的类，
因此内部不得出现任何 `is_*()` / `wp_*()` 调用。这是硬约束，不是风格偏好。

## 二、请求生命周期

### 2.1 HIT 路径（缓存命中）

```
浏览器请求
   ↓
wp-settings.php 加载 wp-content/advanced-cache.php（drop-in）
   ↓
读 cache/at8-site-accelerator/config/<host>.php（回退 default.php）
   ↓ 配置不存在 → return，交还 WordPress
   ↓ enabled=0 或 safe_mode=1 → return
   ↓
require CachePath.php + RequestGuard.php（此时插件类还没加载）
   ↓
RequestGuard::should_bypass() —— 非 GET / 黑名单路径 / 登录 Cookie / 购物车 Cookie → return
   ↓
CachePath::disk_file() 或 redis_key() 算键
   ↓
磁盘：is_file + mtime TTL 判断；Redis：GET
   ↓
命中 → header + echo + exit      ← 不查数据库、不加载主题、不加载其他插件
未命中 → return，交还 WordPress
```

**为什么写入不放在 drop-in 里**：drop-in 阶段 `is_404()`、`is_user_logged_in()`、
`is_cart()` 等条件函数都还不存在。在那里做"是否该缓存"的判断，
早晚会产出"把 404 页缓存成首页"这类经典事故。所以命中读、未命中写，职责严格分离。

### 2.2 MISS 路径（缓存未命中）

```
plugins_loaded:1   → Plugin::instance()->boot()
   ├─ register_services()   登记全部服务到容器
   ├─ maybe_migrate()       2.x 设置迁移（幂等）
   ├─ boot_shared()         前台共用模块挂载
   └─ boot_admin()          仅后台
   ↓
init:1             → CacheEngine::maybe_serve_from_cache()   drop-in 缺失时的兜底 HIT
template_redirect:1 → CacheEngine::start_buffer()            ob_start([$this,'store'])
   ↓
WordPress 正常渲染页面
   ↓
ob 回调 CacheEngine::store($buffer)
   ├─ RequestGuard::should_bypass()      纵深防御：再判一次请求级准入
   ├─ should_cache_response()            404 / 搜索 / Feed / 预览 / 登录 / 密码保护 / 购物车
   ├─ has_missing_elementor_css()        Elementor 样式就绪护栏
   ├─ apply_filters('at8sa_after_cache_buffer')   ← LazyLoad 在这里改写 HTML
   ├─ HtmlMinifier::minify()
   ├─ stamp()                            插入可诊断指纹
   └─ backend->set()                     原子写
   ↓
返回 $payload（= 已落盘的内容）            ← 保证首访与二访看到同一份 HTML
```

**`store()` 必须返回处理后的内容**，不能返回原始 `$buffer`。
否则首个访客看到未压缩、未懒加载的页面，第二个访客看到压缩后的——这是最难查的一类不一致。

## 三、缓存布局

### 3.1 磁盘

```
wp-content/cache/at8-site-accelerator/
├── index.php                                   目录列表守卫（只此一处）
├── config/
│   ├── index.php
│   ├── default.php                             兜底配置
│   └── example.com.php                         按主机名
└── example.com/
    ├── __root/
    │   ├── index.html                          首页
    │   └── __m/index.html                      首页（移动端变体）
    ├── blog/
    │   └── hello-world/
    │       ├── index.html
    │       ├── __m/index.html
    │       └── q-1a2b3c4d5e/index.html         带查询串的变体
    └── category/
        └── news/index.html
```

**为什么"目录即 URL"**：
- 精准失效退化成一次文件删除，不需要维护任何索引；
- 服务器规则可以直接 `try_files` 直出（不经过 PHP）；
- 排查时肉眼就能看出哪个 URL 被缓存了。

**为什么 `index.php` 守卫只放根目录**：1000 个页面会产生 1000 个多余小文件，
而且会让"删完文件顺手删掉空目录"永远失败（目录永远非空）。守卫的作用是防目录列表，
根目录一处即可。

### 3.2 缓存键

| 后端 | 键格式 |
| --- | --- |
| 磁盘 | `<host>/<path…>[/q-<md5前10>][/__m]/index.html` |
| Redis | `at8sa:<站点盐>:<host>:<归一化 URI>[|m]` |

**Redis 键刻意不含协议**：同一站点 http/https 混用时若键不同，缓存会被劈成两份，
命中率腰斩。

### 3.3 站点盐

```
salt = site_token() . '|v' . cache_version()
site_token() = COOKIEHASH（未定义时回退 md5(home_url())）
```

- `site_token` 解决**同服务器多站点隔离**；
- `cache_version` 解决**"删不干净"**：整站失效时先递增版本号，旧键立即不可达，
  即使后端有残留也不会被读到。这比单纯 flush 更硬。

## 四、失效策略

默认 `purge_scope=related`：保存文章**只失效真正受影响的 URL**。

```
save_post / transition_post_status
   ↓
maybe_purge_post()  跳过 revision / autosave / attachment / 非 publish|private
   ↓
Purger::purge_post($post_id)
   ├─ 文章本身（get_permalink）
   ├─ 首页（purge_home_on_save 控制）
   ├─ 文章类型归档
   ├─ 所属分类 / 标签 / 自定义分类归档
   ├─ 作者归档
   └─ 以上每项的翻页（第 2…N 页）
   ↓
单次上限 300 个 URL，超出则自动升级为整站清空
```

**为什么上限 300**：一次保存触发上千次删除会长时间占用 PHP 进程，
在高并发下反而制造故障。超过阈值说明"精准"已失去意义，直接整站清空更划算。

## 五、依赖注入

`Core\Container` 是最小的惰性单例容器：

```php
$container->bind( Settings::class, function () { return new Settings(); } );
$container->get( Settings::class );   // 首次调用时实例化，之后返回同一实例
$container->instance( $obj, $obj );   // 直接登记已有实例
$container->has( $id );
```

刻意不引入 `league/container`：
- 插件要能在"用户没装 Composer 的共享主机"上直接上传运行；
- 需要的只是"惰性单例 + 可替换"，一个 60 行的类足够；
- 少一个第三方依赖 = 少一条供应链风险。

## 六、扩展点（Filter / Action）

| 钩子 | 类型 | 用途 |
| --- | --- | --- |
| `at8sa_runtime_config` | filter | 改写 drop-in 运行时配置 |
| `at8sa_after_cache_buffer` | filter | 落盘前改写 HTML（优化器挂这里） |
| `at8sa_should_cache_response` | filter | 最终准入决定 |
| `at8sa_should_purge_post` | filter | 是否因这篇文章而失效 |
| `at8sa_post_purge_urls` | filter | 追加/裁剪要失效的 URL |
| `at8sa_lazyload_exclude` | filter | 懒加载排除规则 |
| `at8sa_preloader_config` | filter | 前端预取参数 |
| `at8sa_redis_args` | filter | Redis 连接参数 |
| `at8sa_purged_all` | action | 整站清空后 |
| `at8sa_activated` / `at8sa_deactivated` | action | 生命周期 |
| `at8sa_settings_saved` | action | 设置保存后 |

## 七、代码量

| 层 | 文件 | 行数 |
| --- | ---: | ---: |
| Core | 5 | 1,043 |
| Cache | 5 | 1,550 |
| Cache/Backend | 4 | 811 |
| Purge | 2 | 800 |
| Optimization | 7 | 1,812 |
| Compatibility | 3 | 624 |
| Diagnostics | 1 | 451 |
| Admin | 4 | 1,055 |
| REST | 3 | 403 |
| Support | 3 | 807 |
| 入口 + 卸载 + 模板 | 4 | 904 |
| **插件本体合计** | **41** | **≈10,388** |
| 测试 | 3 | 2,237 |
| 工具 | 2 | 350 |

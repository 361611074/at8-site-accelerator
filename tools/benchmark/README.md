# 基准测量脚本

`docs/PERFORMANCE_BENCHMARK.md` 第七节里所有数字的产出脚本。
放进来而不是留在 `/tmp`，是为了让结果**可复现**——换台机器跑一遍能对上，
数字才有意义。

## 脚本

| 脚本 | 用途 |
| --- | --- |
| `bench.sh <label>` | 主测量：命中率、TTFB、数据库查询数、缓存条目、并发稳定性、`.tmp` 残留 |
| `ttfb.sh <label> [n]` | 单点 TTFB（中位数 / 最小 / 最大 / P90），用于 Redis vs 磁盘对比 |
| `purge-precision.php` | 精准失效：预热 N 个 URL，编辑 1 篇文章，统计被清除的条目数 |
| `fixture-terms.php` | 给生成的测试文章批量分配分类（造"归档页 / 分类页"的失效依赖） |

## 用法

```bash
# 灌内容（500 篇起步，否则归档页/分类页太少，精准失效测不出差异）
wp post generate --count=500
wp term generate category --count=25
wp --allow-root eval-file tools/benchmark/fixture-terms.php

# A 组：停用插件
wp --allow-root plugin deactivate at8-site-accelerator
ROOT=/path/to/wp bash tools/benchmark/bench.sh A

# B / C 组：切配置后各跑一次
ROOT=/path/to/wp bash tools/benchmark/bench.sh B
ROOT=/path/to/wp bash tools/benchmark/bench.sh C

# 后端对比
ROOT=/path/to/wp bash tools/benchmark/ttfb.sh disk  30
ROOT=/path/to/wp bash tools/benchmark/ttfb.sh redis 30
```

环境变量：`SITE`（默认 `https://wordpress.xmm.fan`）、`ROOT`（默认当前目录）。

## 干扰排除（不做这些，数据一定是垃圾）

1. **绕过 CDN** —— CDN 命中时请求根本到不了源站，TTFB 测的是 CDN 不是插件。
2. **关掉安全插件的限流与 UA 黑名单** —— 这是最容易踩的坑：
   `ab` 和 `curl` 的默认 UA 常被 WAF 直接 403。本次实测未处理时，
   2000 次请求里 **1782 次是非 2xx**，命中率、TTFB、并发全是错的。
   本仓库脚本已统一使用浏览器 UA，但**限流仍需手动关**，测完记得还原。
3. **预热** —— OPcache 冷启动时第一次请求要编译 PHP，必须先跑一遍再采样。
4. **查询数要扣开销** —— `SHOW GLOBAL STATUS LIKE 'Questions'` 自身有开销，
   实测一次请求空转是 **3 次**，务必先标定再减。
5. **并发规模看机器** —— 方案里写的 `ab -n 5000 -c 100` 在弱 VPS 上会打满
   PHP-FPM，导致吞吐数字失真。4 核 4G 的机器用 `-n 800 -c 20` 更合适。

## 一个实测到的坑（值得单独记）

`wp-content/cache` 若是用 `wp` 命令（root）创建的，而 PHP-FPM 以 `www` 运行，
**磁盘后端会静默写不进去**——不报错，只是永远 MISS，看起来像"缓存没生效"。

```bash
chown -R www:www wp-content/cache
```

判断方法：切到磁盘后端后，缓存目录下除了 `config/` 之外没有任何 `index.html`。

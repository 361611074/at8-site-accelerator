#!/bin/bash
# AT8 Site Accelerator 性能基准测量脚本
# 用法: bash tools/benchmark/bench.sh <label>
#   label 只是给输出打标签（如 A / B / C / redis / disk）
# 环境变量:
#   SITE  站点首页 URL（默认 https://wordpress.xmm.fan）
#   ROOT  WordPress 根目录（默认当前目录）
#
# 前置条件（否则数据全是噪声）：
#   1. 站点无 CDN，或已绕过 CDN 直连源站；
#   2. 已临时关闭安全插件的限流 / UA 黑名单（见 README.md「干扰排除」）；
#   3. 已用 fixture-terms.php 之类灌入足量内容（建议 ≥ 500 篇）。
set -u

LABEL="${1:-X}"
SITE="${SITE:-https://wordpress.xmm.fan}"
ROOT="${ROOT:-.}"
cd "$ROOT" || exit 1

# 统一使用浏览器 UA：at8-security 的 block_bad_ua 会拉黑 curl/ab 的默认 UA，
# 不设这个头的话所有请求都返回 403，测量结果全是垃圾。
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

WP="wp --allow-root"

# ---- MySQL 凭据（从 wp-config.php 读，避免硬编码）----
DB_NAME=$($WP config get DB_NAME 2>/dev/null)
DB_USER=$($WP config get DB_USER 2>/dev/null)
DB_PASS=$($WP config get DB_PASSWORD 2>/dev/null)
DB_HOST=$($WP config get DB_HOST 2>/dev/null)
MYSQL="mysql -h${DB_HOST%%:*} -u$DB_USER -p$DB_PASS $DB_NAME -N -B"

queries_now() {
  mysql -h"${DB_HOST%%:*}" -u"$DB_USER" -p"$DB_PASS" -N -B \
    -e "SHOW GLOBAL STATUS LIKE 'Questions'" 2>/dev/null | awk '{print $2}'
}

# TTFB 中位数（毫秒），$1=url $2=次数
ttfb_median() {
  local url="$1" n="${2:-20}" i
  for ((i = 0; i < n; i++)); do
    curl -s -A "$UA" -o /dev/null -w '%{time_starttransfer}\n' "$url" 2>/dev/null
  done | sort -n | awk '{a[NR]=$1} END { if (NR==0) print "NA"; else printf "%.1f", a[int((NR+1)/2)]*1000 }'
}

# 单次请求的响应头字段
hdr() { curl -s -A "$UA" -o /dev/null -D - "$1" 2>/dev/null | grep -i "^$2:" | head -1 | tr -d '\r' | cut -d' ' -f2-; }

echo "================ BENCHMARK [$LABEL] ================"
echo "site   : $SITE"
echo "php    : $(php -r 'echo PHP_VERSION;')"
echo "date   : $(date -Iseconds)"
echo "backend: $($WP eval 'echo \AT8SA\Core\Plugin::instance()->container()->get(\AT8SA\Cache\Backend\BackendFactory::class)->active_name();' 2>/dev/null || echo 'n/a')"
echo "plugin : $($WP plugin get at8-site-accelerator --field=status 2>/dev/null || echo 'not-installed')"
echo

# ---- 1. 命中率抽样 ----
echo "---- 1. 命中率抽样 ----"
HIT=0; MISS=0; OTHER=0
for i in $(seq 1 60); do
  h=$(hdr "$SITE/?bench=$i" "x-at8-cache")
  case "$h" in
    HIT*) HIT=$((HIT + 1)) ;;
    MISS*) MISS=$((MISS + 1)) ;;
    *) OTHER=$((OTHER + 1)) ;;
  esac
done
# 第二轮：已预热，统计真实命中率
HIT2=0; MISS2=0; OTHER2=0
for i in $(seq 1 60); do
  h=$(hdr "$SITE/?bench=$i" "x-at8-cache")
  case "$h" in
    HIT*) HIT2=$((HIT2 + 1)) ;;
    MISS*) MISS2=$((MISS2 + 1)) ;;
    *) OTHER2=$((OTHER2 + 1)) ;;
  esac
done
echo "  冷抽样(60): HIT=$HIT MISS=$MISS 无头=$OTHER"
echo "  热抽样(60): HIT=$HIT2 MISS=$MISS2 无头=$OTHER2"
if [ $((HIT2 + MISS2)) -gt 0 ]; then
  awk -v h="$HIT2" -v m="$MISS2" 'BEGIN{printf "  热命中率: %.1f%%\n", h*100/(h+m)}'
fi
echo

# ---- 2. TTFB ----
echo "---- 2. TTFB (中位数, 20 次) ----"
# 预热
curl -s -A "$UA" -o /dev/null "$SITE/" 2>/dev/null
curl -s -A "$UA" -o /dev/null "$SITE/?miss=1" 2>/dev/null
echo "  首页 HIT      : $(ttfb_median "$SITE/" 20) ms   [$(hdr "$SITE/" 'x-at8-cache')]"
echo "  新URL MISS    : $(ttfb_median "$SITE/?fresh=$RANDOM$RANDOM" 1) ms"
# MISS 用不同 URL 各测一次取中位数
MISSVALS=""
for i in $(seq 1 12); do
  MISSVALS="$MISSVALS $(curl -s -A "$UA" -o /dev/null -w '%{time_starttransfer}' "$SITE/?m=$RANDOM$RANDOM" 2>/dev/null)"
done
echo "  MISS 中位数   : $(echo "$MISSVALS" | tr ' ' '\n' | grep -v '^$' | sort -n | awk '{a[NR]=$1} END{printf "%.1f", a[int((NR+1)/2)]*1000}') ms"
echo

# ---- 3. 数据库查询数 ----
echo "---- 3. 数据库查询数 (MySQL Questions 差值) ----"
for kind in hit miss; do
  if [ "$kind" = "hit" ]; then
    U="$SITE/"; curl -s -A "$UA" -o /dev/null "$U" 2>/dev/null
  else
    U="$SITE/?q=$RANDOM$RANDOM"
  fi
  q0=$(queries_now); curl -s -A "$UA" -o /dev/null "$U" 2>/dev/null; q1=$(queries_now)
  echo "  $kind 请求 ($(hdr "$U" 'x-at8-cache')): $((q1 - q0)) 次查询"
done
echo

# ---- 4. 缓存条目总数 ----
echo "---- 4. 缓存条目 ----"
$WP eval 'echo "cached_pages=" . \AT8SA\Core\Plugin::instance()->container()->get(\AT8SA\Purge\Purger::class)->backend_status()["cached_pages"] . "\n";' 2>/dev/null || echo "  n/a"
echo

# ---- 5. 并发稳定性 ----
echo "---- 5. 并发 (ab -n 800 -c 20) ----"
ab -n 800 -c 20 -H "User-Agent: $UA" -q "$SITE/" 2>/dev/null | grep -E "Failed requests|Non-2xx|Requests per second|Time per request" | sed 's/^/  /'
echo

# ---- 6. 临时文件残留 ----
echo "---- 6. 缓存目录 .tmp 残留 ----"
find wp-content/cache/at8-site-accelerator -name '*.tmp*' 2>/dev/null | wc -l | sed 's/^/  残留数: /'
echo
echo "================ END [$LABEL] ================"

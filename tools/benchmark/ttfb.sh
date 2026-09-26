#!/bin/bash
# 单点 TTFB 测量（用于 Redis vs 磁盘后端对比）
# 用法: bash tools/benchmark/ttfb.sh <label> [次数]
# 环境变量: SITE（默认 https://wordpress.xmm.fan）、ROOT（默认当前目录）
set -u

LABEL="${1:-X}"
N="${2:-30}"
SITE="${SITE:-https://wordpress.xmm.fan}"
ROOT="${ROOT:-.}"
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

cd "$ROOT" || exit 1

hdr() { curl -s -A "$UA" -o /dev/null -D - "$1" 2>/dev/null | grep -i "^x-at8-cache" | tr -d '\r'; }

# 预热一次，确保后续都是命中
curl -s -A "$UA" -o /dev/null "$SITE/" 2>/dev/null

echo "==== TTFB [$LABEL]  n=$N ===="
echo "状态: $(hdr "$SITE/")"

VALS=""
for i in $(seq 1 "$N"); do
  VALS="$VALS $(curl -s -A "$UA" -o /dev/null -w '%{time_starttransfer}' "$SITE/" 2>/dev/null)"
done

echo "$VALS" | tr ' ' '\n' | grep -v '^$' | sort -n | awk -v l="$LABEL" '
  { a[NR] = $1 }
  END {
    if (NR == 0) { print "  无数据"; exit }
    printf "  %-14s 中位数=%.1f ms  最小=%.1f ms  最大=%.1f ms  P90=%.1f ms\n",
      l, a[int((NR+1)/2)]*1000, a[1]*1000, a[NR]*1000, a[int(NR*0.9)]*1000
  }'

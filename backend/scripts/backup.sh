#!/usr/bin/env bash
# 每日备份：Redis dump + storage 内容库 + uploads → {项目}/backup/YYYY-MM-DD/，保留 14 天
# crontab: 0 3 * * * /srv/sucaiku/backend/scripts/backup.sh >> /tmp/sucaiku-backup.log 2>&1
set -uo pipefail

BACKEND_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(dirname "$BACKEND_DIR")"
OUT="$ROOT/backup/$(date +%F)"
mkdir -p "$OUT"

redis-cli bgsave >/dev/null 2>&1 || true
sleep 2
DUMP_DIR="$(redis-cli --raw config get dir 2>/dev/null | tail -1)"
if [ -n "${DUMP_DIR:-}" ] && [ -f "$DUMP_DIR/dump.rdb" ]; then
  cp "$DUMP_DIR/dump.rdb" "$OUT/redis-dump.rdb"
fi

cp -R "$BACKEND_DIR/storage" "$OUT/storage"
if [ -d "$BACKEND_DIR/uploads" ]; then
  cp -R "$BACKEND_DIR/uploads" "$OUT/uploads"
fi

# 保留 14 天
find "$ROOT/backup" -maxdepth 1 -type d -name '20*' -mtime +14 -exec rm -rf {} \; 2>/dev/null
echo "$(date '+%F %T') backup done: $OUT"

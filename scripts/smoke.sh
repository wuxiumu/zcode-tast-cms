#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; cd "$ROOT"
fail(){ echo "✗ $1" >&2; exit 1; }; ok(){ echo "✓ $1"; }
command -v php >/dev/null || fail "需要 PHP"
[[ -f check.sh && -f backend/public/index.php ]] || fail "缺少入口"
[[ -f backend/.env.example ]] || fail "缺少 backend/.env.example"
ok "核心文件齐全"
grep -n "admin123" backend/config/admin.php && fail "仍含 admin123 注释/口令" || ok "admin 配置无明文口令"
php -l backend/config/admin.php >/dev/null
php -l backend/config/app.php >/dev/null
ok "PHP lint OK"
# 结构级 doctor 可能需 Redis；不强制起服
echo ""; echo "smoke: ok"
echo "提示：配置 backend/.env 后 ./check.sh 做完整体检"

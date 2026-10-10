#!/usr/bin/env bash
# ============================================================
# 素材库 · 一键打包脚本
#
# 用法（在项目根目录执行）:
#   ./build.sh              # 打包前后台 + 后端代码 → dist_package/
#   ./build.sh fe           # 仅打包前端（H5 + Admin）
#   ./build.sh be           # 仅打包后端
#   NODE_BIN=/path/to/node_bin ./build.sh   # 自定义 node 目录（默认自动探测 nvm node22）
#
# 产物（dist_package/）:
#   frontend/h5/     用户端静态文件  → 服务器 /srv/sucaiku/frontend/h5
#   frontend/admin/  管理端静态文件  → 服务器 /srv/sucaiku/frontend/admin（挂 /admin/ 路径）
#   frontend.tar.gz
#   backend/         后端代码+storage → 服务器 /srv/sucaiku/backend
#   backend.tar.gz
#
# 域名等环境配置：frontend/*/.env.production（打包前可改）
# 服务器端配置：deploy/backend.production.env → 服务器 backend/.env
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/dist_package"
TARGET="${1:-all}"

# ---------- 定位 node/npm ----------
find_node_bin() {
  if [ -n "${NODE_BIN:-}" ]; then echo "$NODE_BIN"; return; fi
  for d in "$HOME"/.nvm/versions/node/v22.22.0/bin "$HOME"/.nvm/versions/node/v20*/bin /opt/homebrew/bin /usr/local/bin; do
    if [ -x "$d/node" ]; then echo "$d"; return; fi
  done
  echo ""
}
NODE_DIR="$(find_node_bin)"
if [ -z "$NODE_DIR" ]; then
  echo "!! 未找到 node ≥18，请安装或 NODE_BIN=/path/to/bin ./build.sh"; exit 1
fi
export PATH="$NODE_DIR:$PATH"
echo "==> node $(node -v), npm $(npm -v)"

build_frontend() {
  for app in h5 admin; do
    echo "==> 构建 frontend/${app} (production) ..."
    if [ ! -d "$ROOT/frontend/$app/node_modules" ]; then
      (cd "$ROOT/frontend/$app" && npm install --no-audit --no-fund)
    fi
    (cd "$ROOT/frontend/$app" && npx vite build --mode production)
  done
  rm -rf "$OUT/frontend"
  mkdir -p "$OUT/frontend"
  cp -R "$ROOT/frontend/h5/dist"    "$OUT/frontend/h5"
  cp -R "$ROOT/frontend/admin/dist" "$OUT/frontend/admin"
  tar -czf "$OUT/frontend.tar.gz" -C "$OUT" frontend
  echo "==> 前端完成: $OUT/frontend（h5 + admin）, $OUT/frontend.tar.gz"
}

build_backend() {
  echo "==> 收集后端代码..."
  rm -rf "$OUT/backend" "$OUT/backend.tar.gz"
  mkdir -p "$OUT/backend"
  # 代码与内容库打包；.env（服务器专属配置）与 uploads（体积大，单独同步）不入包
  rsync -a \
    --exclude '.env' \
    --exclude 'uploads/' \
    --exclude '.runtime/' \
    --exclude 'scripts/smoke.php' \
    --exclude '.DS_Store' \
    --exclude '*.zip' \
    --exclude 'node_modules/' \
    "$ROOT/backend/" "$OUT/backend/"
  mkdir -p "$OUT/backend/uploads"
  cp "$ROOT/deploy/backend.production.env" "$OUT/backend/.env.production.example"
  tar -czf "$OUT/backend.tar.gz" -C "$OUT" backend
  echo "==> 后端完成: $OUT/backend, $OUT/backend.tar.gz"
}

case "$TARGET" in
  fe|frontend) build_frontend ;;
  be|backend)  build_backend ;;
  all)         build_frontend; build_backend ;;
  *) echo "用法: ./build.sh [all|fe|be]"; exit 1 ;;
esac

echo ""
echo "============================================"
echo "打包完成 → $OUT"
echo "下一步见 deploy/README.md（上传服务器 + nginx + .env）"
echo "============================================"

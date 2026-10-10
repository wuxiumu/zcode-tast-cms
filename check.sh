#!/usr/bin/env bash
# ============================================================
# 素材库 · 快速体检（本地/服务器接口检查）
#
# 用法:
#   ./check.sh                                            # 检查本地开发服务 127.0.0.1:8090
#   ./check.sh https://sucaiku-api.chiguashentan.com      # 检查线上
#   ./check.sh <URL> --full                               # 加测上传闭环 + 内容库写入
#   ./check.sh <URL> --admin-user=admin --admin-pass=xxx  # 改过管理员密码时
#   ./check.sh <URL> --origin=https://front.example.com   # 指定跨域检测用的前端域名
# ============================================================
exec php "$(cd "$(dirname "$0")" && pwd)/backend/scripts/doctor.php" "$@"

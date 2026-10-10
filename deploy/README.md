# 部署手册（后端一个域名，前端一个域名）

| 端 | 域名 | 产物（build.sh 生成于 `dist_package/`） | 服务器目录 |
|----|------|------------------------------------------|------------|
| 后端 API | https://sucaiku-api.chiguashentan.com | `backend/`（或 backend.tar.gz） | `/srv/sucaiku/backend` |
| 前端（H5+Admin） | https://sucaiku.chiguashentan.com | `frontend/`（h5 + admin，或 frontend.tar.gz） | `/srv/sucaiku/frontend` |

> 前端域名以实际为准；如不同，改 `deploy/nginx-frontend.conf` 的 `server_name` 与 `deploy/backend.production.env` 的 `CORS_ALLOW_ORIGINS`。

## 1. 本机打包

```bash
./build.sh          # 全部；或 ./build.sh fe / ./build.sh be
```

域名配置位置（打包前确认）：
- **前端 API 域名**：`frontend/h5/.env.production`、`frontend/admin/.env.production` 的 `VITE_API_BASE`（构建时打进静态文件）
- **后端资源域名/跨域白名单**：`deploy/backend.production.env`（上传到服务器后重命名为 `backend/.env`）

## 2. 上传服务器

```bash
rsync -avz dist_package/backend/  user@server:/srv/sucaiku/backend/
rsync -avz dist_package/frontend/ user@server:/srv/sucaiku/frontend/
# 后续同步 uploads 与 storage（素材内容库）：
# rsync -avz backend/uploads/ user@server:/srv/sucaiku/backend/uploads/
```

## 3. 服务器准备

```bash
# 依赖：PHP >= 8.1（含 redis 扩展）、Redis、Nginx
php -v                     # 必须 >= 8.1，否则启动即提示
php -m | grep redis        # 必须有 redis
redis-server -v
# 启动 Redis 并开启 AOF（任务/奖励数据仅存 Redis）：
redis-cli config set appendonly yes
```

**低配服务器兼容说明**：
- `fileinfo` 扩展**可不装**——上传校验自动三级降级（fileinfo → mime_content_type → 内置魔数嗅探），功能不受影响；
- 除 `redis` 外无其他必需扩展；`gd/curl` 仅本机开发脚本（冒烟/演示数据）用到，生产不需要；
- 小内存（1G/2G）建议：php-fpm `pm.max_children = 10` 左右、开启 opcache、Redis 限制 `maxmemory 256mb`。

服务器后端配置（关键一步）：

```bash
cd /srv/sucaiku/backend
cp .env.production.example .env
vim .env    # 确认 ASSET_BASE_URL（资源域名）/ APP_DEBUG=0
```

## 4. Nginx 与跨域

- 后端：`deploy/nginx.conf` → `/etc/nginx/conf.d/sucaiku-api.conf`（含 /uploads 直出与 php-fpm 配置，按实际 sock 路径改 `fastcgi_pass`）
- 前端：`deploy/nginx-frontend.conf` → `/etc/nginx/conf.d/sucaiku-front.conf`
- **跨域（CORS）在 Nginx 层配置，后端代码不处理**：`deploy/nginx.conf` 的 `location /` 内已写好 4 行 `add_header` + OPTIONS 短路返回 204。宝塔操作：网站设置 → 配置文件，把该片段粘进 `location /`（或用宝塔自带「跨域」开关，建议把默认的 `*` 改成指定前端域名）
- HTTPS：`certbot --nginx -d sucaiku-api.chiguashentan.com -d sucaiku.chiguashentan.com`

```bash
nginx -t && nginx -s reload
```

## 5. 定时任务（必须）

```bash
crontab -e
# 过期任务兜底扫描
*/5 * * * * php /srv/sucaiku/backend/scripts/expire-sweep.php >> /tmp/sucaiku-sweep.log 2>&1
# 每日备份 Redis dump + storage + uploads
0 3 * * * /srv/sucaiku/backend/scripts/backup.sh >> /tmp/sucaiku-backup.log 2>&1
```

## 6. 验收

```bash
curl https://sucaiku-api.chiguashentan.com/api/v1/ping
# 应返回 {"code":0,...}；浏览器打开前端域名 → 登录（验证码见后端 .env mock_sms_code）→ 大厅图片应来自 API 域名完整 URL
```

## 7. 接入 OSS（以后要做的事）

1. OSS Bucket + 绑定 CDN 域名（如 https://cdn.chiguashentan.com）。
2. 服务器 `backend/.env` 改 `ASSET_BASE_URL=https://cdn.chiguashentan.com`。
3. 存量图片迁移到 OSS（保持 `/uploads/...` 路径结构）或改写存储层为 OSS SDK 上传。
4. `php scripts/rebuild-index.php` 重建素材缓存 → 出参即变 OSS 完整地址，代码零改动。

## 本机模拟部署（可选）

`deploy/nginx.local.conf` 为免 sudo 用户级实例（API 127.0.0.1:8093 反代 php -S:8090，前端 127.0.0.1:8094），
配套 `frontend/*/.env.staging`（`./build.sh` 前用 `npx vite build --mode staging` 构建）。
双域名模式需 sudo 加 hosts + 80 端口，命令见 `deploy/nginx.conf` 头部注释。

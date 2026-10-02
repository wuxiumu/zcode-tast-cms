# 素材库 · 图文代发任务系统（zcode-tast-cms）

运营按「两级分类 + 渠道标签（小红书/视频号/抖音…）」上架图文与视频素材；用户在分类下按渠道多选领取任务，去对应平台发布后回传链接 + 截图领取首发奖励；播放/点赞数据达标可补发数据截图追加奖励。内置人工审核中心与奖励流水。

> 技术形态：**PHP 8.1+（零 composer 依赖）+ Redis + Markdown 内容库**，前端 React 18 双端。为素材量 < 100 的轻量场景设计，不引入 MySQL；超限后按 [doc/05 §9](doc/05-Redis存储设计.md) 平滑迁移。

## 功能总览

| 端 | 能力 |
|----|------|
| 管理端 `/admin/` | 数据看板 · 素材管理（图文/视频+文字、一级→二级联动分类、标签多选、奖励档位规则、配额限领、多选批量上下架/删除、预览抽屉）· 分类与标签（联动筛选、批量启停/删除）· 审核中心（首发凭证 / 数据补发，按快照核定奖励）· 奖励记录（打款标记） |
| 用户端 H5 | 手机号登录 · 分类浏览 · 任务大厅（标签筛选 + 多选 1,2,3 领取）· 素材详情（文案一键复制）· 我的任务 · 提交发布凭证 · 数据补发 · 奖励记录 |
| 机制 | 领取 Redis Lua 原子防超发（多选整体回滚）· 链接/截图去重 · 48h 提交时限 + 过期兜底 · 奖励按凭证时刻规则快照核算 · 金额以分存储 |

## 技术栈与目录

```
backend/    PHP API（自写路由/JWT/限流，phpredis）
  public/     入口 + 路由表        app/  Controller·Service·Store 分层
  storage/    内容库（md + JSON frontmatter，source of truth，可 git 管理）
  uploads/    上传文件（演示图片已随库）
  scripts/    smoke 体检/种子数据/索引重建/备份
frontend/
  h5/         用户端（Vite + React 18 + TS + antd-mobile）
  admin/      管理端（Vite + React 18 + TS + Ant Design 5）
doc/        设计文档 00~10（需求/架构/数据模型/API/Redis/流程/部署/优化建议）
deploy/     服务器 nginx 配置与 .env 生产模板（宝塔可用）
build.sh    一键打包（前端 dist + 后端代码 → dist_package/）
check.sh    接口体检（本地/服务器通用，分层定位故障）
```

存储分工：分类/标签/素材 = `storage/*.md`（人可直接编辑、git 可回滚）；用户/任务/凭证/奖励 = Redis（需开启 AOF）；图片视频 = `uploads/`。**URL 约定：存储恒为相对路径，出参按 `ASSET_BASE_URL` 拼完整地址**——接 OSS/CDN 只改 `.env` + `rebuild-index.php`，代码零改动。

## 快速开始

依赖：PHP ≥ 8.1（须启用 redis 扩展；**fileinfo 可不装**，上传校验自动降级为内置魔数嗅探）、运行中的 Redis、Node ≥ 18。

```bash
# 1) 后端
cd backend
cp .env.example .env          # 按需修改（留空 ASSET_BASE_URL = 本地同源模式）
php -S 0.0.0.0:8090 -t public public/index.php

# 2) 用户端 H5 → http://localhost:5173
cd frontend/h5 && npm install && npm run dev

# 3) 管理端 → http://localhost:5174/admin
cd frontend/admin && npm install && npm run dev

# 4) 演示数据（分类树 + 13 条示范素材，幂等可重复执行）
php backend/scripts/seed-data.php
```

**账号**：管理端 `admin / admin123`（`backend/config/admin.php`）；用户端任意手机号 + 验证码 `123456`（mock，`backend/config/app.php`）。

## 常用脚本

| 命令 | 作用 |
|------|------|
| `./build.sh` | 一键打包前后台 → `dist_package/`（fe/be 可选） |
| `./check.sh <URL> [--full]` | 接口体检：网络→Web→API→鉴权→CORS→业务，失败项附修复提示 |
| `php backend/scripts/seed-data.php` | 补充参考数据（分类树 + 示范素材，幂等） |
| `php backend/scripts/rebuild-index.php` | 从 storage 重建 Redis 索引（内容库可随时重建缓存） |
| `php backend/scripts/expire-sweep.php` | 过期任务兜底扫描（crontab 每 5 分钟） |
| `php backend/scripts/backup.sh` | 每日备份 Redis dump + storage + uploads（保留 14 天） |

## 部署

前后端分离双域名：API 域名（php-fpm + `/uploads/` 直出）+ 前端域名（H5 根路径，Admin 挂 `/admin/`）。配置模板与完整步骤见 **[deploy/README.md](deploy/README.md)**；**上线前必读 [doc/10-优化建议.md](doc/10-优化建议.md) 的 P0 安全项**（更换 JWT secret、改管理员密码、CORS 白名单收紧、关闭调试）。

## 设计文档

| 编号 | 文档 |
|------|------|
| 01 | [需求概述](doc/01-需求概述.md) |
| 02 | [系统架构](doc/02-系统架构设计.md) |
| 03 | [数据模型](doc/03-数据模型设计.md) |
| 04 | [API 接口](doc/04-API接口设计.md) |
| 05 | [Redis 存储](doc/05-Redis存储设计.md) |
| 06 | [前端设计](doc/06-前端设计.md) |
| 07 | [任务与奖励流程](doc/07-任务与奖励流程.md) |
| 08 | [目录与里程碑](doc/08-目录结构与开发计划.md) |
| 09 | [代码执行计划](doc/09-代码执行计划.md) |
| 10 | [优化建议（P0~P3）](doc/10-优化建议.md) |

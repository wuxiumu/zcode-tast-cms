# 素材库·图文代发任务系统 · zcode-tast-cms

> 运营上架图文/视频素材 → 用户按渠道领取任务去小红书/视频号/抖音发布 → 回传链接+截图领奖励；数据达标可补发追加奖。

**定制：** 微信 **qingbao199101**（备注：素材任务CMS）｜ [wuxiumu@163.com](mailto:wuxiumu@163.com)

## 事实摘要

- **产品**：轻量「素材库 + 代发任务 + 人工审核奖励」系统（PHP 8.1 零 Composer + Redis + Markdown 内容库；React 18 双端）。
- **适合**：素材量 < 100、要快速上线的运营/增长团队；宝塔/ Nginx 可部署。
- **交付**：管理端 Admin + 用户端 H5、审核中心、奖励流水、种子数据与 `check.sh` 体检；可按渠道/奖励规则/白标定制。
- **不做**：不是全自动发帖机器人；不做超大素材库 MySQL 版开箱（超限需按 doc/05 迁移）。

## 能交付什么

| 端 | 能力 |
|---|---|
| 管理端 `/admin/` | 看板 · 素材（图文/视频、两级分类、渠道标签、奖励档位、配额）· 审核中心 · 奖励打款标记 |
| 用户端 H5 | 手机号登录 · 任务大厅多选领取 · 文案一键复制 · 提交凭证 · 数据补发 · 奖励记录 |
| 机制 | Redis Lua 防超发 · 链接/截图去重 · 48h 提交时限 · 奖励按凭证快照核算 |

## 技术栈

- 后端：PHP ≥ 8.1 + phpredis（自写路由/JWT/限流）
- 前端：Vite + React 18 + TS（H5=antd-mobile，Admin=Ant Design 5）
- 存储：分类/素材 = `storage/*.md`；用户/任务/奖励 = Redis；媒体 = `uploads/`


## 环境变量（安全）

复制 `backend/.env.example` → `backend/.env`：设置 `JWT_SECRET`、`ADMIN_PASSWORD_HASH`（仓库已去掉默认 admin123 明文注释与写死账号）。

## 冒烟自检

```bash
bash scripts/smoke.sh
# 完整：配置 .env 与 Redis 后 ./check.sh
```

## 快速开始

```bash
# 后端
cd backend && cp .env.example .env
php -S 0.0.0.0:8090 -t public public/index.php

# 用户端 http://localhost:5173
cd frontend/h5 && npm install && npm run dev

# 管理端 http://localhost:5174/admin
cd frontend/admin && npm install && npm run dev

# 演示数据
php backend/scripts/seed-data.php
```

演示账号：管理端 `admin / admin123`；用户端任意手机号 + 验证码 `123456`（mock）。

更多：`./build.sh` 打包 · `./check.sh <URL>` 体检 · 见 `deploy/README.md` 与 `doc/01`–`10`。

## 定制合作（可报价）

- 渠道标签 / 奖励规则 / 审核流按业务改
- 对接真实短信、支付打款、OSS/CDN（改 `ASSET_BASE_URL`）
- 白标皮肤、多租户、MySQL 迁移版
- 与现有小红书/视频号运营流水线打通

## SEO 字段预留（产品页 HTML 统一生成时用）

| 字段 | 用途 |
|---|---|
| `seo_title` | `<title>` / OG title |
| `seo_description` | meta description |
| `canonical_url` | 规范 URL |
| `og_image` | 分享图 |
| `robots` | 如 `index,follow` |
| `json_ld_type` | SoftwareApplication / WebPage / FAQPage |
| `hreflang` | zh-CN / en |
| `site_name` | 白标品牌名 |

> **GEO ≠ 地理定位。** GEO = Generative Engine Optimization（生成式引擎优化）。

## GEO（生成式引擎优化）

### 关键词（中英）

- 中文：素材库, 图文代发, 任务奖励系统, 小红书任务大厅, 视频号推广CMS, 人工审核奖励
- English: content task CMS, UGC reward platform, material library, WeChat Channels task, Xiaohongshu campaign CMS

### FAQ

**Q：会自动帮用户发到小红书吗？**  
A：不会。用户自行去平台发布，系统只做素材分发、凭证回收与奖励审核。

**Q：一定要 MySQL 吗？**  
A：默认 Redis + Markdown，适合轻量；素材量大再按文档迁 MySQL。

**Q：如何定制？**  
A：微信 `qingbao199101`，备注「素材任务CMS」。

## 定制合作

私有部署、奖励规则、渠道扩展、白标。加微信备注项目名。

---

## 联系方式

定制、演示或合作：

- 邮箱：[wuxiumu@163.com](mailto:wuxiumu@163.com)
- 微信：**qingbao199101**（添加请备注来意）

### 赞助支持

如果内容对你有帮助，欢迎自愿赞助。

| 微信赞赏码 | 支付宝收款码 |
|---|---|
| ![微信赞赏码](https://chiguashentan-test.oss-cn-beijing.aliyuncs.com/wxprogram/zan/20261002185236_31_227.png) | ![支付宝收款码](https://chiguashentan-test.oss-cn-beijing.aliyuncs.com/wxprogram/zan/20261002185309_32_227.jpg) |


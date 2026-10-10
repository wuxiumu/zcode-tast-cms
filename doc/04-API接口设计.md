# 04 API 接口设计

## 1. 全局约定

- Base URL：`/api/v1`；用户端 `/api/v1/*`，管理端 `/api/v1/admin/*`。
- 格式：`Content-Type: application/json`（上传接口除外）。
- 鉴权：`Authorization: Bearer <jwt>`；登录类接口免鉴权。
- 响应统一结构：

```json
{ "code": 0, "msg": "ok", "data": {} }
```

- 分页参数：`page`（1 起）、`page_size`（默认 20，最大 50）；响应 `data.list[]` + `data.total`。
- 时间：`Y-m-d H:i:s`，服务器时区 Asia/Shanghai。
- 金额：字符串两位小数（`"3.00"`），避免浮点误差。

### 错误码

| code | 含义 | 典型场景 |
|------|------|----------|
| 0 | 成功 | - |
| 1001 | 参数错误 | 缺字段/格式错，msg 带具体字段 |
| 1002 | 未登录或 token 失效 | 前端跳登录 |
| 1003 | 无权限 | token aud 不符、越权访问 |
| 2001 | 素材不存在或已下架 | 领取时素材刚被下架 |
| 2002 | 配额不足 | 领取时 quota 用尽 |
| 2003 | 重复领取 | 超过 per_user_limit |
| 2004 | 任务状态不允许该操作 | 如重复提交凭证 |
| 2005 | 提交被拒 | 链接重复/截图重复/过于频繁 |
| 2006 | 超过提交截止时间 | deadline 已过 |
| 5000 | 服务器内部错误 | msg 统一"稍后再试" |

## 2. 通用上传

### POST /uploads

`multipart/form-data`，字段 `file`；参数 `scene=image|screenshot|video`。
校验：image/screenshot ≤10MB（jpg/png/webp），video ≤200MB（mp4/mov）。

```json
{ "code": 0, "msg": "ok", "data": { "url": "/uploads/2026/10/01/a1b2c3.jpg" } }
```

## 3. 用户端 API

### 3.1 登录 POST /auth/login

M1 简化版：手机号 + 验证码（验证码先接固定/mock，短信通道后置）。预留 `wx_code` 走微信授权。

请求：`{ "phone": "13800000000", "code": "1234" }`
响应 data：`{ "token": "...", "user": { "uid": "u_000001", "nickname": "发布者0001", "total_reward": "12.50" } }`

### 3.2 GET /me — 当前用户信息

### 3.3 GET /categories — 分类树

响应 data：

```json
[
  { "id": "cat_1001", "name": "游戏", "children": [
      { "id": "cat_2001", "name": "蛋仔派对", "material_count": 12 }
  ]},
  { "id": "cat_3001", "name": "影视", "children": [
      { "id": "cat_3002", "name": "斗破苍穹", "material_count": 5 }
  ]}
]
```

### 3.4 GET /categories/{id}/tags — 该分类下可用标签

返回该分类下所有上架素材涉及到的标签（去重），前端做筛选 tab：`[{ "key": "xhs", "name": "小红书", "material_count": 8 }]`

### 3.5 GET /materials — 任务大厅列表

Query：`category_id`（必填，二级分类）、`tag`（可选，标签 key）、`page`、`page_size`。
响应 data.list 元素：

```json
{
  "id": "mat_10001",
  "title": "蛋仔派对夏日皮肤安利",
  "type": "image_text",
  "cover": "/uploads/2026/10/01/a1b2c3.jpg",
  "tags": [{ "key": "xhs", "name": "小红书" }],
  "reward_first": "3.00",
  "reward_max": "6.50",
  "quota_left": 37,
  "claimed": false,
  "my_task_id": null
}
```

> `reward_max` = first + 数据奖励理论最大值，用于列表吸引力展示；`claimed/my_task_id` 便于「已领取」态直接跳任务。

### 3.6 GET /materials/{id} — 素材详情（已领取者可见完整内容）

响应 data：素材全字段 + `text`（可复制文案）+ `images[]/video` + `notice`（md 正文渲染 HTML）+ `guide`（该素材选中标签的发布引导文案）+ `reward` 规则 + `my_task`（若已领取，含 task_id 与状态）。

> 未领取时是否可见完整内容由素材字段 `hide_content_until_claim`（预留，默认 false）控制，本期默认发布前可预览。

### 3.7 POST /tasks/claim — 领取任务（支持多选）

请求：`{ "material_ids": ["mat_10001", "mat_10002", "mat_10003"], "tag": "xhs" }`
原子性由 Lua 保证；**部分成功时整体回滚**（任一素材失败则全部不领取，返回明细），保证「选择 1,2,3」要么都拿到要么明确提示哪个不行。

成功响应 data：`{ "tasks": [{ "task_id": "t_10000001", "material_id": "mat_10001" }, ...] }`
失败响应：code=2001/2002/2003 之一，data 附 `failed: [{ "material_id": "mat_10002", "reason": "配额不足" }]`

### 3.8 GET /tasks — 我的任务列表

Query：`status=claimed|submitted|bonus_open|finished|expired`（可逗号分隔多个）。
响应 data.list 元素：

```json
{
  "task_id": "t_10000001",
  "status": "bonus_open",
  "material": { "id": "mat_10001", "title": "...", "cover": "...", "type": "image_text" },
  "tag": { "key": "xhs", "name": "小红书" },
  "claim_at": "2026-10-01 12:30:00",
  "deadline_at": "2026-10-03 12:30:00",
  "bonus_round": 1,
  "bonus_max_rounds": 3,
  "total_reward": "6.00"
}
```

### 3.9 GET /tasks/{id} — 任务详情

任务全字段 + 素材内容（同 3.6）+ `submissions[]`（各次提交及审核结果、驳回原因）+ `rewards[]`（本任务奖励明细）。

### 3.10 POST /tasks/{id}/submit — 提交首发凭证

请求：

```json
{ "platform_url": "https://www.xiaohongshu.com/explore/xxxx", "screenshots": ["/uploads/2026/10/02/b2c3d4.jpg"] }
```

约束：状态须为 `claimed`；未过 deadline；URL 全局去重（2005）；截图哈希去重。
成功：code=0，任务转 `submitted`。

### 3.11 POST /tasks/{id}/stats-submit — 补发数据截图

请求：

```json
{ "round": 1, "metrics": { "views": 6200, "likes": 320, "comments": 45 }, "screenshots": ["/uploads/2026/10/03/c3d4e5.jpg"] }
```

约束：状态须为 `bonus_open`；round ≤ max_bonus_rounds 且 = 已通过轮次+1；无 pending 中的数据凭证。
成功：任务转 `bonus_pending`。响应附当前规则供前端展示。

### 3.12 GET /rewards — 奖励记录

响应 data.list 元素：`{ "rid": "r_000001", "task_id": "t_10000001", "material_title": "...", "type": "stats", "round": 1, "amount": "3.00", "status": "granted", "created_at": "..." }`

## 4. 管理端 API

### 4.1 POST /admin/auth/login

请求：`{ "username": "op01", "password": "***" }`；账号密码在 `config/admin.php`（bcrypt hash）。响应同用户端 token（aud=admin）。

### 4.2 分类 / 标签 CRUD

- `GET /admin/categories` / `POST /admin/categories` / `PUT /admin/categories/{id}` / `DELETE /admin/categories/{id}`
- `GET /admin/tags` / `POST /admin/tags` / `PUT /admin/tags/{key}` / `DELETE /admin/tags/{key}`
- 删除校验：分类下有素材、标签被素材引用时拒绝（1001 + msg）。
- 写操作直接落 md 文件 + 更新 Redis 缓存（`sc:idx:cat`、`sc:tag:all`）。

### 4.3 素材管理

| 接口 | 说明 |
|------|------|
| GET /admin/materials | 列表，query: `category_id` / `tag` / `status` / `keyword` / 分页；返回含配额消耗（claim_count） |
| POST /admin/materials | 创建（draft），字段见 03 §4 |
| PUT /admin/materials/{id} | 编辑（published 状态编辑奖励规则需二次确认，前端做） |
| POST /admin/materials/{id}/publish | 上架：写文件 + 建索引 + 初始化配额 |
| POST /admin/materials/{id}/offline | 下架：删索引；已领取任务不受影响（照常走流程） |
| DELETE /admin/materials/{id} | 仅 draft/offline 且无任务时允许 |
| GET /admin/materials/{id}/stats | 单素材：领取/提交/通过/奖励支出 |

### 4.4 审核中心

- `GET /admin/submissions`：query `status=pending`（默认）、`type=first|stats`、分页；pending 队列来自 `sc:z:sub:pending`。
- `POST /admin/submissions/{id}/review`：

```json
{ "action": "approve", "reward_amount": "3.00" }
{ "action": "reject",  "reason": "截图中笔记发布时间晚于任务领取时间不足1小时，请确认后重交" }
```

- approve：核定奖励（type=first 默认取素材 `reward.first`；type=stats 按规则 + 管理员可调，改动需填理由）、任务状态推进、写奖励流水。
- 响应附任务最新状态。

### 4.5 奖励与统计

- `GET /admin/rewards`：全站流水，query: `uid` / `status=granted|paid` / 日期区间。
- `POST /admin/rewards/{rid}/pay`：标记已打款（人工转账后点确认）。
- `GET /admin/stats/overview`：今日领取/提交/待审数、累计奖励支出、素材消耗 Top10。

## 5. 接口-页面映射速查

| 页面 | 用到的接口 |
|------|-----------|
| H5 任务大厅 | 3.3 / 3.4 / 3.5 / 3.7 |
| H5 素材详情 | 3.6 / 3.7 / 2 |
| H5 任务详情 | 3.9 / 3.10 / 3.11 / 2 |
| H5 我的任务/奖励 | 3.8 / 3.12 |
| Admin 素材编辑 | 4.3 / 2 |
| Admin 审核中心 | 4.4 |

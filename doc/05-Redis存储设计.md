# 05 Redis 存储设计

## 1. 总则

- 键前缀：`sc:`（.env `KEY_PREFIX` 可改），逻辑庘认为单 db 0 即可（规模小）。
- 内容库（素材/分类/标签）在 Redis 里是**缓存/索引**，可由文件重建；状态库（任务/凭证/奖励/用户）只有 Redis 一份，靠持久化保命。
- 所有金额以「分」为单位存整数（如 300 = 3.00 元），API 层转字符串，避免浮点问题。

## 2. 键设计总表

### 2.1 序列与缓存

| 键 | 类型 | 说明 | TTL |
|----|------|------|-----|
| `sc:seq:material` / `sc:seq:task` / `sc:seq:sub` / `sc:seq:reward` | string | ID 自增（INCR） | - |
| `sc:idx:cat` | string(JSON) | 分类树缓存，管理端改分类时重写 | - |
| `sc:tag:all` | string(JSON) | 标签列表缓存 | - |
| `sc:mat:{mid}` | string(JSON) | 素材元数据（含 reward 规则、quota 等展示字段） | - |
| `sc:idx:mat:cat:{cid}` | zset | 分类→素材，score=上架时间戳 | - |
| `sc:idx:mat:cattag:{cid}:{tag}` | zset | 分类×标签组合索引，任务大厅查这个 | - |
| `sc:quota:mat:{mid}` | string(int) | 剩余配额；-1 表示不限 | - |
| `sc:stat:mat:{mid}` | hash | `claim` / `submit` / `approve` 计数 | - |

### 2.2 用户

| 键 | 类型 | 说明 |
|----|------|------|
| `sc:user:{uid}` | hash | 用户资料（03 §5） |
| `sc:uidx:phone:{phone}` | string → uid | 登录索引 |
| `sc:uidx:tasks:{uid}` | zset | 用户的任务，member=tid，score=领取时间戳 |

### 2.3 任务与凭证

| 键 | 类型 | 说明 |
|----|------|------|
| `sc:task:{tid}` | hash | 任务全字段（03 §6） |
| `sc:uq:claim:{uid}:{mid}` | string → tid | 唯一领取标记（唯一索引 + 防重） |
| `sc:z:deadline` | zset | member=tid，score=deadline 时间戳 → 兜底扫描过期任务 |
| `sc:sub:{sid}` | hash | 凭证全字段（03 §7） |
| `sc:z:sub:pending` | zset | 待审核队列，member=sid，score=提交时间 |
| `sc:dedup:url` | hash | field=url 的 sha1 → tid，首发链接全局唯一 |
| `sc:dedup:img:{md5}` | string | 截图哈希粗查重，TTL 30 天 |

### 2.4 奖励、限流、锁

| 键 | 类型 | 说明 |
|----|------|------|
| `sc:reward:{uid}` | zset | 用户奖励流水，member=`r_{rid}`，score=时间戳 |
| `sc:reward:detail:{rid}` | hash | 流水明细 |
| `sc:z:reward:paid` | zset | 全站待打款/已打款标记（管理端用） |
| `sc:rate:{uid}:{action}:{Ymd}` | string | 日限流计数，TTL 当日剩余秒+60 |
| `sc:lock:claim:{uid}:{mid}` | string | Lua 之外的兜底锁，一般用不到（Lua 已原子），TTL 10s |

## 3. 索引维护规则

| 动作 | 同步操作 |
|------|----------|
| 素材上架 | 写 `sc:mat:{mid}`、`sc:quota:mat:{mid}`、加入两个 zset 索引 |
| 素材下架 | 从两个 zset 移除（已领任务不受影响） |
| 素材改标签/分类 | 先下架式清理旧索引，再按新值重建 |
| 素材删除 | 清 `sc:mat:{mid}` 与全部索引（仅允许无任务时） |
| 分类/标签改动 | 重写 `sc:idx:cat` / `sc:tag:all` |

重建脚本 `scripts/rebuild-index.php`：扫描 `storage/materials/*.md` 中 `status=published` 的文件，全量重建以上键。**改 Redis 结构时先更新该脚本再改代码。**

## 4. 典型读写路径

- 任务大厅：`ZRANGE sc:idx:mat:cattag:{cid}:{tag} (page-1)*n page*n-1 REV` → MGET `sc:mat:{mid}` → 过滤 `status=on` 分类 → 拼 `sc:quota:mat:{mid}`、用户领取态（`EXISTS sc:uq:claim:{uid}:{mid}`）。
- 我的任务：`ZRANGE sc:uidx:tasks:{uid} 0 -1 REV` → HGETALL 各任务。
- 待审队列：`ZRANGE sc:z:sub:pending 0 -1 REV`（量小不分页，或按需）。

## 5. 领取任务的原子 Lua

```lua
-- KEYS[1]=sc:uq:claim:{uid}:{mid}  KEYS[2]=sc:quota:mat:{mid}
-- KEYS[3]=sc:task:{tid}  KEYS[4]=sc:uidx:tasks:{uid}  KEYS[5]=sc:stat:mat:{mid}
-- ARGV: tid, uid, mid, cid, tag, now, deadline, windowHours
if redis.call('EXISTS', KEYS[1]) == 1 then return {err='DUP'} end
local quota = redis.call('GET', KEYS[2])
if quota ~= false and tonumber(quota) == 0 then return {err='NOQUOTA'} end
redis.call('HSET', KEYS[3],
  'task_id', ARGV[1], 'user_id', ARGV[2], 'material_id', ARGV[3],
  'category_id', ARGV[4], 'tag_key', ARGV[5], 'status', 'claimed',
  'claim_at', ARGV[6], 'deadline_at', ARGV[7],
  'bonus_round', 0, 'bonus_paid', 0, 'total_reward', 0)
redis.call('SET', KEYS[1], ARGV[1])
redis.call('ZADD', KEYS[4], ARGV[6], ARGV[1])
redis.call('ZADD', KEYS['sc:z:deadline'] or '', ARGV[7], ARGV[1]) -- 伪码,实际单独 KEYS 传入
if quota ~= false then redis.call('DECR', KEYS[2]) end
redis.call('HINCRBY', KEYS[5], 'claim', 1)
return {ok='OK'}
```

多素材领取（1,2,3）在 PHP 层循环调用该脚本，**任一失败则反向补偿**（删除已建任务、恢复配额、删标记），对外表现为整体成功或整体失败。

## 6. 过期与兜底

- 用户端读任务时**惰性判断**：`now > deadline_at && status=claimed` → 置 `expired`、`INCR sc:quota:mat:{mid}`（若配额有限）、移出 deadline zset。
- 定时兜底：每 5 分钟 `ZRANGEBYSCORE sc:z:deadline 0 now` 扫描，处理漏网任务（crontab 或 systemd timer 调 `scripts/expire-sweep.php`）。

## 7. 持久化与备份

```
# redis.conf 关键项
appendonly yes
appendfsync everysec
save 900 1
save 300 10
dir /var/lib/redis
```

- 状态数据（任务/凭证/奖励）不允许关闭 AOF。
- 每日 03:00 备份：`redis-cli BGSAVE` 后拷贝 dump.rdb + 打包 `storage/`、`uploads/` 到 `/srv/sucaiku/backup/`，保留 14 天（`scripts/backup.sh` + crontab）。
- **恢复优先级**：Redis 宕机恢复依赖 AOF；极端情况丢最近 1 秒数据，可接受（审核操作低频）。
- 内容库永不丢：md 文件在 git/备份里。

## 8. 容量估算（100 素材 / 1000 用户 / 1 万任务）

| 项 | 估算 |
|----|------|
| 任务 hash | 1 万 × ~0.8KB ≈ 8MB |
| 索引/缓存/计数 | < 2MB |
| 截图去重/限流 | < 1MB |
| 合计 | **< 15MB**，单机 Redis 毫无压力 |

## 9. 升级 MySQL 的触发与迁移

触发信号（.env `MAX_MATERIALS` 告警）：素材 > 100、任务量使 zset 操作出现慢日志、需要跨实体事务/复杂报表。

迁移方案：
1. 建 `materials / categories / tags / tasks / submissions / rewards / users` 表（结构即 03 文档字段）。
2. `scripts/import-to-mysql.php`：解析 md frontmatter → 内容表；`DUMP` Redis hash → 状态表。
3. `Store` 层新增 `MysqlStore` 实现，切换配置；接口与前端零改动。

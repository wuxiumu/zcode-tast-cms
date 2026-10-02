<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\FileStore;
use App\Store\RedisStore;
use App\Support\Config;
use App\Support\Money;
use App\Support\TaskException;
use App\Support\Url;

final class TaskService
{
    // ==================== 领取 ====================

    /** 多选领取：任一失败整体补偿回滚 */
    public static function claimMulti(string $uid, array $mids, string $tag): array
    {
        if (!$mids || count($mids) > 20) {
            throw new TaskException(1001, 'material_ids 必填且单次不超过 20 个');
        }
        $tagRow = TagService::find($tag);
        if (!$tagRow || ($tagRow['status'] ?? 'on') !== 'on') {
            throw new TaskException(1001, '标签不存在或未启用');
        }
        $claimed = [];
        $failed = [];
        foreach (array_values(array_unique(array_map('strval', $mids))) as $mid) {
            try {
                $claimed[] = self::claimOne($uid, $mid, $tag);
            } catch (TaskException $e) {
                $failed[] = ['material_id' => $mid, 'code' => $e->getCode(), 'reason' => $e->getMessage()];
            }
        }
        if ($failed) {
            foreach ($claimed as $t) {
                self::compensate($uid, $t['task_id'], $t['material_id']);
            }
            throw new TaskException($failed[0]['code'], $failed[0]['reason'], ['failed' => $failed]);
        }
        return $claimed;
    }

    private static function claimOne(string $uid, string $mid, string $tag): array
    {
        $m = MaterialService::find($mid);
        if (!$m || $m['status'] !== 'published') {
            throw new TaskException(2001, '素材不存在或已下架');
        }
        if (!in_array($tag, $m['tag_keys'] ?? [], true)) {
            throw new TaskException(1001, '素材不包含该标签');
        }
        $r = RedisStore::client();
        if ($r->exists(RedisStore::k("uq:claim:$uid:$mid"))) {
            throw new TaskException(2003, '该素材已领取过');
        }
        $quotaKey = RedisStore::k("quota:mat:$mid");
        $quota = $r->get($quotaKey);
        if ($quota !== false && (int)$quota <= 0) {
            throw new TaskException(2002, '配额不足');
        }
        $tid = 't_' . str_pad((string)$r->incr(RedisStore::k('seq:task')), 8, '0', STR_PAD_LEFT);
        $now = time();
        $deadline = $now + max(1, (int)($m['window_hours'] ?? 48)) * 3600;
        $res = $r->eval(
            self::claimLua(),
            [
                RedisStore::k("uq:claim:$uid:$mid"),
                $quotaKey,
                RedisStore::k("task:$tid"),
                RedisStore::k("uidx:tasks:$uid"),
                RedisStore::k("stat:mat:$mid"),
                RedisStore::k('z:deadline'),
                $tid, $uid, $mid, $m['category_id'], $tag, $now, $deadline,
            ],
            6
        );
        if ($res !== 'OK') {
            throw new TaskException(5000, '领取失败，请重试');
        }
        $r->zAdd(RedisStore::k('z:claims'), $now, $tid);
        $r->hIncrBy(RedisStore::k('stat:total'), 'claims', 1);
        return ['task_id' => $tid, 'material_id' => $mid];
    }

    private static function claimLua(): string
    {
        return <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 then return redis.error_reply('DUP') end
local quota = redis.call('GET', KEYS[2])
if quota and tonumber(quota) <= 0 then return redis.error_reply('NOQUOTA') end
redis.call('HSET', KEYS[3],
  'task_id', ARGV[1], 'user_id', ARGV[2], 'material_id', ARGV[3],
  'category_id', ARGV[4], 'tag_key', ARGV[5], 'status', 'claimed',
  'claim_at', ARGV[6], 'deadline_at', ARGV[7],
  'bonus_round', '0', 'bonus_paid_cents', '0', 'first_reward_cents', '0', 'total_reward_cents', '0')
redis.call('SET', KEYS[1], ARGV[1])
redis.call('ZADD', KEYS[4], ARGV[6], ARGV[1])
redis.call('ZADD', KEYS[6], ARGV[7], ARGV[1])
if quota then redis.call('DECR', KEYS[2]) end
redis.call('HINCRBY', KEYS[5], 'claim', 1)
return 'OK'
LUA;
    }

    private static function compensate(string $uid, string $tid, string $mid): void
    {
        $r = RedisStore::client();
        $r->del(RedisStore::k("uq:claim:$uid:$mid"), RedisStore::k("task:$tid"));
        $r->zRem(RedisStore::k("uidx:tasks:$uid"), $tid);
        $r->zRem(RedisStore::k('z:deadline'), $tid);
        $r->zRem(RedisStore::k('z:claims'), $tid);
        if ($r->exists(RedisStore::k("quota:mat:$mid"))) {
            $r->incr(RedisStore::k("quota:mat:$mid"));
        }
        $r->hIncrBy(RedisStore::k("stat:mat:$mid"), 'claim', -1);
        $r->hIncrBy(RedisStore::k('stat:total'), 'claims', -1);
    }

    // ==================== 查询 ====================

    public static function get(string $tid): ?array
    {
        $t = RedisStore::client()->hGetAll(RedisStore::k("task:$tid"));
        return $t ?: null;
    }

    /** claimed 且超时 → expired 并释放配额（Lua 防并发重复释放） */
    public static function lazyExpire(array $t): array
    {
        if (($t['status'] ?? '') !== 'claimed' || time() <= (int)($t['deadline_at'] ?? 0)) {
            return $t;
        }
        $r = RedisStore::client();
        $tid = $t['task_id'];
        $r->eval(
            <<<'LUA'
if redis.call('HGET', KEYS[1], 'status') == 'claimed' then
  redis.call('HSET', KEYS[1], 'status', 'expired')
  if redis.call('EXISTS', KEYS[2]) == 1 then redis.call('INCR', KEYS[2]) end
  redis.call('ZREM', KEYS[3], ARGV[1])
  return 1
end
return 0
LUA,
            [
                RedisStore::k("task:$tid"),
                RedisStore::k('quota:mat:' . $t['material_id']),
                RedisStore::k('z:deadline'),
                $tid,
            ],
            3
        );
        return self::get($tid) ?? $t;
    }

    /** 兜底扫描（crontab 每 5 分钟），返回本次过期数 */
    public static function expireSweep(): int
    {
        $r = RedisStore::client();
        $tids = $r->zRangeByScore(RedisStore::k('z:deadline'), '-inf', (string)time(), ['limit' => [0, 500]]);
        $n = 0;
        foreach ($tids ?: [] as $tid) {
            $t = self::get((string)$tid);
            if (!$t) {
                $r->zRem(RedisStore::k('z:deadline'), $tid);
                continue;
            }
            $before = $t['status'] ?? '';
            $t = self::lazyExpire($t);
            if (($t['status'] ?? '') === 'expired' && $before !== 'expired') {
                $n++;
            }
        }
        return $n;
    }

    public static function listByUser(string $uid, ?string $statusFilter): array
    {
        $r = RedisStore::client();
        $tids = $r->zRevRange(RedisStore::k("uidx:tasks:$uid"), 0, -1) ?: [];
        $out = [];
        foreach ($tids as $tid) {
            $t = self::get((string)$tid);
            if (!$t) {
                continue;
            }
            $t = self::lazyExpire($t);
            if ($statusFilter !== null && !in_array($t['status'] ?? '', $statusFilter, true)) {
                continue;
            }
            $out[] = self::present($t);
        }
        return $out;
    }

    public static function detail(string $uid, string $tid): array
    {
        $task = self::get($tid);
        if (!$task || $task['user_id'] !== $uid) {
            throw new TaskException(2004, '任务不存在');
        }
        $task = self::lazyExpire($task);
        $r = RedisStore::client();

        $material = null;
        $notice = '';
        $m = MaterialService::find($task['material_id']);
        if ($m) {
            $material = MaterialService::publicMeta($m);
            [, $notice] = FileStore::readMd(FileStore::materialPath($task['material_id']));
        }
        $tag = TagService::find($task['tag_key'] ?? '');

        // 该任务的全部凭证
        $subs = [];
        foreach ($r->zRevRange(RedisStore::k("z:sub:task:$tid"), 0, -1) ?: [] as $sid) {
            $s = $r->hGetAll(RedisStore::k("sub:$sid"));
            if ($s) {
                $subs[] = self::presentSub($s);
            }
        }
        // 该任务的奖励
        $rewards = [];
        foreach ($r->zRevRange(RedisStore::k("z:reward:task:$tid"), 0, -1) ?: [] as $rid) {
            $d = $r->hGetAll(RedisStore::k("reward:detail:$rid"));
            if ($d) {
                $rewards[] = RewardService::present($d);
            }
        }

        $data = self::present($task);
        $data['material'] = $material;
        $data['notice'] = $notice;
        $data['tag'] = $tag ? ['key' => $tag['key'], 'name' => $tag['name'], 'guide' => $tag['guide'] ?? ''] : null;
        $data['submissions'] = $subs;
        $data['rewards'] = $rewards;
        return $data;
    }

    // ==================== 提交 ====================

    public static function submitFirst(string $uid, string $tid, string $url, array $shots): array
    {
        $task = self::get($tid);
        if (!$task || $task['user_id'] !== $uid) {
            throw new TaskException(2004, '任务不存在');
        }
        $task = self::lazyExpire($task);
        if (($task['status'] ?? '') !== 'claimed') {
            throw new TaskException(2004, '当前状态不可提交首发凭证');
        }
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TaskException(1001, '发布链接格式不正确');
        }
        $shots = self::checkShots($shots);
        $r = RedisStore::client();
        $urlSha = sha1($url);
        if (!$r->hSetNx(RedisStore::k('dedup:url'), $urlSha, $tid)) {
            throw new TaskException(2005, '该发布链接已被使用');
        }
        try {
            self::dedupShots(array_values(array_unique($shots)), $tid);
        } catch (\Throwable $e) {
            $r->hDel(RedisStore::k('dedup:url'), $urlSha);
            throw $e;
        }

        $m = MaterialService::find($task['material_id']);
        $snapshot = (string)json_encode(['reward' => $m['reward'] ?? []], JSON_UNESCAPED_UNICODE);
        $now = time();
        $sid = self::newSid($r);
        $r->hMSet(RedisStore::k("sub:$sid"), [
            'sub_id' => $sid, 'task_id' => $tid, 'user_id' => $uid,
            'material_id' => $task['material_id'], 'type' => 'first', 'round' => 0,
            'platform_url' => $url,
            'screenshots' => (string)json_encode($shots, JSON_UNESCAPED_SLASHES),
            'metrics' => '', 'status' => 'pending', 'reward_snapshot' => $snapshot,
            'reward_amount_cents' => 0, 'reviewer' => '', 'reason' => '',
            'created_at' => $now, 'reviewed_at' => 0,
        ]);
        $r->zAdd(RedisStore::k("z:sub:task:$tid"), $now, $sid);
        $r->zAdd(RedisStore::k('z:sub:pending'), $now, $sid);
        $r->zAdd(RedisStore::k('z:sub:all'), $now, $sid);
        $r->hMSet(RedisStore::k("task:$tid"), [
            'status' => 'submitted', 'platform_url' => $url,
            'submit_at' => $now, 'sub_first_id' => $sid, 'last_reject_reason' => '',
        ]);
        $r->zRem(RedisStore::k('z:deadline'), $tid);
        $r->hIncrBy(RedisStore::k('stat:mat:' . $task['material_id']), 'submit', 1);
        $r->hIncrBy(RedisStore::k('stat:total'), 'submissions', 1);
        return ['task_id' => $tid, 'sub_id' => $sid, 'status' => 'submitted'];
    }

    public static function submitStats(string $uid, string $tid, array $input): array
    {
        $task = self::get($tid);
        if (!$task || $task['user_id'] !== $uid) {
            throw new TaskException(2004, '任务不存在');
        }
        $task = self::lazyExpire($task);
        if (($task['status'] ?? '') !== 'bonus_open') {
            throw new TaskException(2004, '当前状态不可补发数据截图');
        }
        $m = MaterialService::find($task['material_id']);
        $maxR = (int)($m['reward']['max_bonus_rounds'] ?? 0);
        if ($maxR <= 0) {
            throw new TaskException(2004, '该任务未开启数据奖励');
        }
        $nextRound = (int)($task['bonus_round'] ?? 0) + 1;
        $round = (int)($input['round'] ?? 0);
        if ($round !== $nextRound) {
            throw new TaskException(1001, "本次应提交第 {$nextRound} 轮数据");
        }
        if ($round > $maxR) {
            throw new TaskException(2004, '已达到补发轮次上限');
        }
        $metrics = [
            'views'    => max(0, (int)($input['metrics']['views'] ?? 0)),
            'likes'    => max(0, (int)($input['metrics']['likes'] ?? 0)),
            'comments' => max(0, (int)($input['metrics']['comments'] ?? 0)),
        ];
        $shots = self::checkShots($input['screenshots'] ?? []);
        self::dedupShots(array_values(array_unique($shots)), $tid);

        $snapshot = (string)json_encode(['reward' => $m['reward'] ?? []], JSON_UNESCAPED_UNICODE);
        $now = time();
        $r = RedisStore::client();
        $sid = self::newSid($r);
        $r->hMSet(RedisStore::k("sub:$sid"), [
            'sub_id' => $sid, 'task_id' => $tid, 'user_id' => $uid,
            'material_id' => $task['material_id'], 'type' => 'stats', 'round' => $round,
            'platform_url' => $task['platform_url'] ?? '',
            'screenshots' => (string)json_encode($shots, JSON_UNESCAPED_SLASHES),
            'metrics' => (string)json_encode($metrics), 'status' => 'pending',
            'reward_snapshot' => $snapshot,
            'reward_amount_cents' => 0, 'reviewer' => '', 'reason' => '',
            'created_at' => $now, 'reviewed_at' => 0,
        ]);
        $r->zAdd(RedisStore::k("z:sub:task:$tid"), $now, $sid);
        $r->zAdd(RedisStore::k('z:sub:pending'), $now, $sid);
        $r->zAdd(RedisStore::k('z:sub:all'), $now, $sid);
        $r->hSet(RedisStore::k("task:$tid"), 'status', 'bonus_pending');
        $r->hIncrBy(RedisStore::k('stat:mat:' . $task['material_id']), 'submit', 1);
        $r->hIncrBy(RedisStore::k('stat:total'), 'submissions', 1);
        return ['task_id' => $tid, 'sub_id' => $sid, 'status' => 'bonus_pending', 'round' => $round];
    }

    // ==================== 审核 ====================

    public static function review(string $sid, string $action, mixed $amountYuan, string $reason, string $reviewer): array
    {
        $r = RedisStore::client();
        $sub = $r->hGetAll(RedisStore::k("sub:$sid"));
        if (!$sub) {
            throw new TaskException(2004, '凭证不存在');
        }
        if (($sub['status'] ?? '') !== 'pending') {
            throw new TaskException(2004, '该凭证已处理，请刷新');
        }
        $tid = $sub['task_id'];
        $task = self::get($tid);
        if (!$task) {
            throw new TaskException(2004, '任务不存在');
        }
        $uid = $sub['user_id'];
        $now = time();
        $snap = json_decode($sub['reward_snapshot'] ?: '{}', true) ?: [];
        $rewardCfg = $snap['reward'] ?? [];
        $maxR = (int)($rewardCfg['max_bonus_rounds'] ?? 0);

        if ($action === 'approve') {
            if ($sub['type'] === 'first') {
                $cents = $amountYuan !== null ? Money::toCents($amountYuan) : Money::toCents($rewardCfg['first'] ?? 0);
                if ($cents > 0) {
                    RewardService::grant($uid, $tid, $sid, 'first', 0, $cents);
                }
                $status = $maxR > 0 ? 'bonus_open' : 'finished';
                $r->hMSet(RedisStore::k("task:$tid"), [
                    'status'              => $status,
                    'first_reward_cents'  => $cents,
                    'total_reward_cents'  => $cents + (int)($task['bonus_paid_cents'] ?? 0),
                    'review_at'           => $now,
                ]);
            } else {
                $round = (int)($sub['round'] ?? 0);
                $metrics = json_decode($sub['metrics'] ?: '{}', true) ?: [];
                $cents = $amountYuan !== null
                    ? Money::toCents($amountYuan)
                    : RewardService::calcStatsCents($rewardCfg, $metrics);
                if ($cents > 0) {
                    RewardService::grant($uid, $tid, $sid, 'stats', $round, $cents);
                }
                $status = $round < $maxR ? 'bonus_open' : 'finished';
                $r->hMSet(RedisStore::k("task:$tid"), [
                    'status'             => $status,
                    'bonus_round'        => $round,
                    'bonus_paid_cents'   => (int)($task['bonus_paid_cents'] ?? 0) + $cents,
                    'total_reward_cents' => (int)($task['total_reward_cents'] ?? 0) + $cents,
                    'review_at'          => $now,
                ]);
            }
            $r->hMSet(RedisStore::k("sub:$sid"), [
                'status' => 'approved', 'reward_amount_cents' => $cents,
                'reviewer' => $reviewer, 'reviewed_at' => $now, 'reason' => '',
            ]);
            $r->hIncrBy(RedisStore::k('stat:mat:' . $sub['material_id']), 'approve', 1);
            $r->hIncrBy(RedisStore::k('stat:total'), 'reward_cents', $cents);
        } elseif ($action === 'reject') {
            if ($reason === '') {
                throw new TaskException(1001, '驳回必须填写原因');
            }
            $r->hMSet(RedisStore::k("sub:$sid"), [
                'status' => 'rejected', 'reason' => $reason,
                'reviewer' => $reviewer, 'reviewed_at' => $now,
            ]);
            $back = $sub['type'] === 'first' ? 'claimed' : 'bonus_open';
            $r->hMSet(RedisStore::k("task:$tid"), ['status' => $back, 'last_reject_reason' => $reason]);
        } else {
            throw new TaskException(1001, 'action 须为 approve 或 reject');
        }
        $r->zRem(RedisStore::k('z:sub:pending'), $sid);
        $task = self::get($tid) ?? $task;
        return ['task' => self::present($task), 'sub_status' => $action === 'approve' ? 'approved' : 'rejected'];
    }

    // ==================== 展示 ====================

    public static function present(array $t): array
    {
        $meta = null;
        if (!empty($t['material_id'])) {
            $meta = MaterialService::cachedMeta($t['material_id']);
        }
        $dt = fn($v) => $v ? date('Y-m-d H:i:s', (int)$v) : null;
        return [
            'task_id'      => $t['task_id'] ?? '',
            'material_id'  => $t['material_id'] ?? '',
            'category_id'  => $t['category_id'] ?? '',
            'tag_key'      => $t['tag_key'] ?? '',
            'status'       => $t['status'] ?? '',
            'platform_url' => (string)($t['platform_url'] ?? ''),
            'claim_at'     => $dt($t['claim_at'] ?? 0),
            'deadline_at'  => $dt($t['deadline_at'] ?? 0),
            'submit_at'    => $dt($t['submit_at'] ?? 0),
            'review_at'    => $dt($t['review_at'] ?? 0),
            'bonus_round'      => (int)($t['bonus_round'] ?? 0),
            'bonus_max_rounds' => (int)($meta['reward']['max_bonus_rounds'] ?? 0),
            'first_reward'     => Money::fmt((int)($t['first_reward_cents'] ?? 0)),
            'bonus_paid'       => Money::fmt((int)($t['bonus_paid_cents'] ?? 0)),
            'total_reward'     => Money::fmt((int)($t['total_reward_cents'] ?? 0)),
            'last_reject_reason' => (string)($t['last_reject_reason'] ?? ''),
            'material'     => $meta ? [
                'id' => $meta['id'], 'title' => $meta['title'],
                'cover' => $meta['cover'], 'type' => $meta['type'],
            ] : null,
        ];
    }

    public static function presentSub(array $s): array
    {
        $dt = fn($v) => $v ? date('Y-m-d H:i:s', (int)$v) : null;
        return [
            'sub_id'       => $s['sub_id'] ?? '',
            'task_id'      => $s['task_id'] ?? '',
            'type'         => $s['type'] ?? '',
            'round'        => (int)($s['round'] ?? 0),
            'platform_url' => (string)($s['platform_url'] ?? ''),
            'screenshots'  => array_map(fn($u) => Url::asset((string)$u), json_decode($s['screenshots'] ?? '[]', true) ?: []),
            'metrics'      => json_decode($s['metrics'] ?? '{}', true) ?: [],
            'status'       => $s['status'] ?? '',
            'reward'       => Money::fmt((int)($s['reward_amount_cents'] ?? 0)),
            'reviewer'     => (string)($s['reviewer'] ?? ''),
            'reason'       => (string)($s['reason'] ?? ''),
            'created_at'   => $dt($s['created_at'] ?? 0),
            'reviewed_at'  => $dt($s['reviewed_at'] ?? 0),
        ];
    }

    // ==================== 内部 ====================

    private static function newSid(\Redis $r): string
    {
        return 's_' . str_pad((string)$r->incr(RedisStore::k('seq:sub')), 8, '0', STR_PAD_LEFT);
    }

    /** @return string[] */
    private static function checkShots(array $shots): array
    {
        $shots = array_values(array_filter(array_map(fn($u) => Url::toPath((string)$u), $shots), fn($u) => $u !== ''));
        if (count($shots) < 1 || count($shots) > 3) {
            throw new TaskException(1001, '截图须为 1~3 张');
        }
        foreach ($shots as $u) {
            if (!str_starts_with($u, '/uploads/') || !is_file(BASE_PATH . $u)) {
                throw new TaskException(1001, '截图文件无效，请重新上传');
            }
        }
        return $shots;
    }

    private static function dedupShots(array $shots, string $tid): void
    {
        $r = RedisStore::client();
        $set = [];
        try {
            foreach ($shots as $u) {
                $p = BASE_PATH . $u;
                if (!is_file($p)) {
                    continue;
                }
                $key = RedisStore::k('dedup:img:' . md5_file($p));
                if (!$r->set($key, $tid, ['nx' => true, 'ex' => 2592000])) {
                    throw new TaskException(2005, '截图与历史提交重复');
                }
                $set[] = $key;
            }
        } catch (\Throwable $e) {
            foreach ($set as $key) {
                $r->del($key);
            }
            throw $e;
        }
    }
}

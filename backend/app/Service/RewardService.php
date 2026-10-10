<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\RedisStore;
use App\Support\Money;
use App\Support\TaskException;

final class RewardService
{
    public static function grant(string $uid, string $tid, string $sid, string $type, int $round, int $cents): string
    {
        $r = RedisStore::client();
        $rid = 'r_' . str_pad((string)$r->incr(RedisStore::k('seq:reward')), 6, '0', STR_PAD_LEFT);
        $now = time();
        $r->hMSet(RedisStore::k("reward:detail:$rid"), [
            'rid' => $rid, 'user_id' => $uid, 'task_id' => $tid, 'sub_id' => $sid,
            'type' => $type, 'round' => $round, 'amount_cents' => $cents,
            'status' => 'granted', 'created_at' => $now, 'paid_at' => 0,
        ]);
        $r->zAdd(RedisStore::k("reward:$uid"), $now, $rid);
        $r->zAdd(RedisStore::k("z:reward:task:$tid"), $now, $rid);
        $r->zAdd(RedisStore::k('z:reward:all'), $now, $rid);
        $r->hIncrBy(RedisStore::k("user:$uid"), 'total_reward_cents', $cents);
        return $rid;
    }

    /** 按规则核算数据奖励（分）；命中取最高或叠加 */
    public static function calcStatsCents(array $rewardCfg, array $metrics): int
    {
        $rules = $rewardCfg['stats_rules'] ?? [];
        $mode = $rewardCfg['stack_mode'] ?? 'highest';
        $hits = [];
        foreach ($rules as $r) {
            $metric = (string)($r['metric'] ?? '');
            if ($metric !== '' && isset($metrics[$metric]) && $metrics[$metric] >= (int)($r['gte'] ?? 0)) {
                $hits[] = Money::toCents($r['reward'] ?? 0);
            }
        }
        if (!$hits) {
            return 0;
        }
        return $mode === 'all' ? (int)array_sum($hits) : (int)max($hits);
    }

    public static function present(array $d): array
    {
        $task = TaskService::get($d['task_id'] ?? '');
        $mTitle = '';
        if ($task) {
            $m = MaterialService::find($task['material_id']);
            $mTitle = $m['title'] ?? '';
        }
        return [
            'rid'        => $d['rid'] ?? '',
            'task_id'    => $d['task_id'] ?? '',
            'user_id'    => $d['user_id'] ?? '',
            'material_title' => $mTitle,
            'type'       => $d['type'] ?? '',
            'round'      => (int)($d['round'] ?? 0),
            'amount'     => Money::fmt((int)($d['amount_cents'] ?? 0)),
            'status'     => $d['status'] ?? '',
            'created_at' => date('Y-m-d H:i:s', (int)($d['created_at'] ?? 0)),
            'paid_at'    => (int)($d['paid_at'] ?? 0) ? date('Y-m-d H:i:s', (int)$d['paid_at']) : null,
        ];
    }

    public static function listByUser(string $uid): array
    {
        $r = RedisStore::client();
        $ids = $r->zRevRange(RedisStore::k("reward:$uid"), 0, -1) ?: [];
        $out = [];
        foreach ($ids as $rid) {
            $d = $r->hGetAll(RedisStore::k("reward:detail:$rid"));
            if ($d) {
                $out[] = self::present($d);
            }
        }
        return $out;
    }

    public static function listAdmin(?string $uid, ?string $status): array
    {
        $r = RedisStore::client();
        $ids = $r->zRevRange(RedisStore::k('z:reward:all'), 0, -1) ?: [];
        $out = [];
        foreach ($ids as $rid) {
            $d = $r->hGetAll(RedisStore::k("reward:detail:$rid"));
            if (!$d) {
                continue;
            }
            if ($uid !== null && ($d['user_id'] ?? '') !== $uid) {
                continue;
            }
            if ($status !== null && ($d['status'] ?? '') !== $status) {
                continue;
            }
            $out[] = self::present($d);
        }
        return $out;
    }

    public static function markPaid(string $rid): void
    {
        $r = RedisStore::client();
        $d = $r->hGetAll(RedisStore::k("reward:detail:$rid"));
        if (!$d) {
            throw new TaskException(1001, '奖励记录不存在');
        }
        if (($d['status'] ?? '') === 'paid') {
            return;
        }
        $r->hMSet(RedisStore::k("reward:detail:$rid"), ['status' => 'paid', 'paid_at' => time()]);
    }
}

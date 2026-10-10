<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Store\FileStore;
use App\Store\RedisStore;
use App\Service\RewardService;
use App\Support\Resp;

final class RewardController
{
    public static function list(array $req): void
    {
        $uid = trim((string)($req['query']['uid'] ?? ''));
        $status = trim((string)($req['query']['status'] ?? ''));
        Resp::ok(['list' => RewardService::listAdmin($uid !== '' ? $uid : null, $status !== '' ? $status : null)]);
    }

    public static function pay(array $req): void
    {
        RewardService::markPaid((string)$req['params']['rid']);
        Resp::ok(null, '已标记打款');
    }

    public static function overview(array $req): void
    {
        $r = RedisStore::client();
        $total = $r->hGetAll(RedisStore::k('stat:total'));
        $todayStart = strtotime(date('Y-m-d'));
        $published = 0;
        $draft = 0;
        foreach (FileStore::allMaterials() as $m) {
            if (($m['status'] ?? '') === 'published') {
                $published++;
            } else {
                $draft++;
            }
        }
        Resp::ok([
            'claims_total'     => (int)($total['claims'] ?? 0),
            'claims_today'     => (int)($r->zCount(RedisStore::k('z:claims'), (string)$todayStart, '+inf') ?: 0),
            'submissions_total'=> (int)($total['submissions'] ?? 0),
            'pending_reviews'  => (int)($r->zCard(RedisStore::k('z:sub:pending')) ?: 0),
            'reward_total'     => number_format(((int)($total['reward_cents'] ?? 0)) / 100, 2, '.', ''),
            'users_total'      => (int)($r->get(RedisStore::k('seq:user')) ?: 0),
            'materials_published' => $published,
            'materials_other'  => $draft,
        ]);
    }
}

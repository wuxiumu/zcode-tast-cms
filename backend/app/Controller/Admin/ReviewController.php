<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Store\RedisStore;
use App\Service\MaterialService;
use App\Service\TaskService;
use App\Support\Http;
use App\Support\Resp;

final class ReviewController
{
    /** 待审列表（type=first|stats 可选；status 仅支持 pending，历史凭证看任务详情） */
    public static function list(array $req): void
    {
        $type = trim((string)($req['query']['type'] ?? ''));
        $r = RedisStore::client();
        $ids = $r->zRevRange(RedisStore::k('z:sub:pending'), 0, -1) ?: [];
        $out = [];
        foreach ($ids as $sid) {
            $s = $r->hGetAll(RedisStore::k("sub:$sid"));
            if (!$s || ($type !== '' && ($s['type'] ?? '') !== $type)) {
                continue;
            }
            $row = TaskService::presentSub($s);
            $meta = MaterialService::cachedMeta((string)($s['material_id'] ?? ''));
            $row['material_title'] = $meta['title'] ?? '';
            $row['material_cover'] = $meta['cover'] ?? '';
            $row['material_text'] = $meta['text'] ?? '';
            $row['reward_cfg'] = $meta['reward'] ?? [];
            $task = TaskService::get((string)($s['task_id'] ?? ''));
            $row['task_status'] = $task['status'] ?? '';
            $row['claim_at'] = isset($task['claim_at']) ? date('Y-m-d H:i:s', (int)$task['claim_at']) : null;
            $user = $task ? $r->hGetAll(RedisStore::k('user:' . $task['user_id'])) : [];
            $row['user'] = ['uid' => $user['uid'] ?? '', 'nickname' => $user['nickname'] ?? '', 'phone' => $user['phone'] ?? ''];
            $out[] = $row;
        }
        Resp::ok(['list' => $out, 'total' => count($out)]);
    }

    public static function review(array $req): void
    {
        $sid = (string)$req['params']['id'];
        $action = Http::str($req['input'], 'action');
        $amount = $req['input']['reward_amount'] ?? null;
        $amount = ($amount === null || $amount === '') ? null : (float)$amount;
        $reason = Http::str($req['input'], 'reason');
        $reviewer = (string)($req['ctx']['admin'] ?? '');
        Resp::ok(TaskService::review($sid, $action, $amount, $reason, $reviewer));
    }
}

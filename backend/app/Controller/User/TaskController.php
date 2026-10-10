<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Service\RewardService;
use App\Service\TaskService;
use App\Support\Http;
use App\Support\RateLimit;
use App\Support\Resp;
use App\Support\TaskException;

final class TaskController
{
    public static function claim(array $req): void
    {
        $uid = $req['ctx']['uid'];
        RateLimit::check($uid, 'claim');
        $mids = $req['input']['material_ids'] ?? null;
        if (!is_array($mids)) {
            throw new TaskException(1001, 'material_ids 须为数组');
        }
        $tag = Http::str($req['input'], 'tag');
        $tasks = TaskService::claimMulti($uid, $mids, $tag);
        Resp::ok(['tasks' => $tasks]);
    }

    public static function myList(array $req): void
    {
        $status = trim((string)($req['query']['status'] ?? ''));
        $filter = $status === '' ? null : array_values(array_filter(array_map('trim', explode(',', $status))));
        Resp::ok(['list' => TaskService::listByUser($req['ctx']['uid'], $filter)]);
    }

    public static function detail(array $req): void
    {
        Resp::ok(TaskService::detail($req['ctx']['uid'], (string)$req['params']['id']));
    }

    public static function submitFirst(array $req): void
    {
        $uid = $req['ctx']['uid'];
        RateLimit::check($uid, 'submit');
        $shots = $req['input']['screenshots'] ?? [];
        if (!is_array($shots)) {
            throw new TaskException(1001, 'screenshots 须为数组');
        }
        $url = Http::str($req['input'], 'platform_url');
        Resp::ok(TaskService::submitFirst($uid, (string)$req['params']['id'], $url, $shots));
    }

    public static function submitStats(array $req): void
    {
        $uid = $req['ctx']['uid'];
        RateLimit::check($uid, 'submit');
        Resp::ok(TaskService::submitStats($uid, (string)$req['params']['id'], $req['input']));
    }
}

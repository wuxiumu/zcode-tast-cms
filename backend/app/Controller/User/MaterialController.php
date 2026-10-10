<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Store\FileStore;
use App\Store\RedisStore;
use App\Service\MaterialService;
use App\Service\TaskService;
use App\Support\Resp;
use App\Support\TaskException;

final class MaterialController
{
    /** 任务大厅列表 */
    public static function list(array $req): void
    {
        $q = $req['query'];
        $cid = trim((string)($q['category_id'] ?? ''));
        if ($cid === '') {
            throw new TaskException(1001, 'category_id 必填');
        }
        $tag = trim((string)($q['tag'] ?? ''));
        $page = max(1, (int)($q['page'] ?? 1));
        $size = min(50, max(1, (int)($q['page_size'] ?? 20)));
        $uid = $req['ctx']['uid'];
        $r = RedisStore::client();

        $key = $tag !== '' ? "idx:mat:cattag:$cid:$tag" : "idx:mat:cat:$cid";
        $ids = $r->zRevRange(RedisStore::k($key), 0, -1) ?: [];
        $total = count($ids);
        $slice = array_slice($ids, ($page - 1) * $size, $size);

        $list = [];
        foreach ($slice as $mid) {
            $m = MaterialService::cachedMeta((string)$mid);
            if (!$m) {
                continue;
            }
            $quota = $r->get(RedisStore::k("quota:mat:{$m['id']}"));
            $myTid = $r->get(RedisStore::k("uq:claim:$uid:{$m['id']}"));
            $tags = [];
            foreach ($m['tag_keys'] as $tk) {
                $t = \App\Service\TagService::find($tk);
                if ($t) {
                    $tags[] = ['key' => $t['key'], 'name' => $t['name']];
                }
            }
            $list[] = [
                'id'           => $m['id'],
                'title'        => $m['title'],
                'type'         => $m['type'],
                'cover'        => $m['cover'],
                'tags'         => $tags,
                'reward_first' => $m['reward_first'],
                'reward_max'   => $m['reward_max'],
                'quota_left'   => $quota === false ? -1 : (int)$quota,
                'claimed'      => $myTid !== false,
                'my_task_id'   => $myTid !== false ? $myTid : null,
            ];
        }
        Resp::ok(['list' => $list, 'total' => $total]);
    }

    /** 素材详情（含文案/图片/视频/发布说明/标签引导） */
    public static function detail(array $req): void
    {
        $mid = (string)$req['params']['id'];
        $uid = $req['ctx']['uid'];
        $m = MaterialService::find($mid);
        if (!$m) {
            throw new TaskException(2001, '素材不存在');
        }
        $r = RedisStore::client();
        $myTid = $r->get(RedisStore::k("uq:claim:$uid:$mid"));
        if ($m['status'] !== 'published' && $myTid === false) {
            throw new TaskException(2001, '素材不存在或已下架');
        }
        [, $notice] = FileStore::readMd(FileStore::materialPath($mid));
        $data = MaterialService::publicMeta($m);
        $data['notice'] = $notice;
        $data['tags'] = [];
        foreach ($m['tag_keys'] as $tk) {
            $t = \App\Service\TagService::find($tk);
            if ($t) {
                $data['tags'][] = ['key' => $t['key'], 'name' => $t['name'], 'guide' => $t['guide'] ?? ''];
            }
        }
        $quota = $r->get(RedisStore::k("quota:mat:$mid"));
        $data['quota_left'] = $quota === false ? -1 : (int)$quota;
        $data['my_task'] = $myTid !== false ? TaskService::present(TaskService::get((string)$myTid) ?: []) : null;
        Resp::ok($data);
    }
}

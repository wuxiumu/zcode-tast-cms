<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\CategoryService;
use App\Service\MaterialService;
use App\Support\Http;
use App\Support\Resp;
use App\Support\TaskException;

final class MaterialController
{
    public static function list(array $req): void
    {
        $q = $req['query'];
        $status = trim((string)($q['status'] ?? ''));
        $cid = trim((string)($q['category_id'] ?? ''));
        $tag = trim((string)($q['tag'] ?? ''));
        $kw = trim((string)($q['keyword'] ?? ''));
        $page = max(1, (int)($q['page'] ?? 1));
        $size = min(50, max(1, (int)($q['page_size'] ?? 20)));

        $rows = [];
        foreach (\App\Store\FileStore::allMaterials() as $m) {
            if ($status !== '' && ($m['status'] ?? '') !== $status) {
                continue;
            }
            if ($cid !== '' && ($m['category_id'] ?? '') !== $cid) {
                continue;
            }
            if ($tag !== '' && !in_array($tag, $m['tag_keys'] ?? [], true)) {
                continue;
            }
            if ($kw !== '' && !str_contains((string)$m['title'], $kw)) {
                continue;
            }
            $row = $m;
            $row['stats'] = MaterialService::stats((string)$m['id']);
            $row['category_name'] = CategoryService::find((string)$m['category_id'])['name'] ?? '';
            $rows[] = $row;
        }
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $size, $size);
        Resp::ok(['list' => $rows, 'total' => $total]);
    }

    public static function detail(array $req): void
    {
        $id = (string)$req['params']['id'];
        $m = MaterialService::find($id);
        if (!$m) {
            throw new TaskException(2001, '素材不存在');
        }
        [, $notice] = \App\Store\FileStore::readMd(\App\Store\FileStore::materialPath($id));
        $row = $m;
        $row['notice'] = $notice;
        $row['category_name'] = CategoryService::find((string)$m['category_id'])['name'] ?? '';
        // 编辑表单需要可直接回显的资源地址
        $row['images'] = array_map(fn($u) => \App\Support\Url::asset((string)$u), $m['images'] ?? []);
        $row['video'] = \App\Support\Url::asset((string)($m['video'] ?? ''));
        $row['cover'] = \App\Support\Url::asset((string)($m['cover'] ?? ''));
        $row['stats'] = MaterialService::stats($id);
        Resp::ok($row);
    }

    public static function create(array $req): void
    {
        $m = MaterialService::create($req['input']);
        if (($req['input']['auto_publish'] ?? false) === true) {
            $m = MaterialService::publish((string)$m['id']);
        }
        Resp::ok($m);
    }

    public static function update(array $req): void
    {
        Resp::ok(MaterialService::update((string)$req['params']['id'], $req['input']));
    }

    public static function publish(array $req): void
    {
        Resp::ok(MaterialService::publish((string)$req['params']['id']));
    }

    public static function offline(array $req): void
    {
        Resp::ok(MaterialService::offline((string)$req['params']['id']));
    }

    public static function delete(array $req): void
    {
        MaterialService::delete((string)$req['params']['id']);
        Resp::ok(null, '已删除');
    }

    public static function stats(array $req): void
    {
        $id = (string)$req['params']['id'];
        if (!MaterialService::find($id)) {
            throw new TaskException(2001, '素材不存在');
        }
        Resp::ok(MaterialService::stats($id));
    }
}

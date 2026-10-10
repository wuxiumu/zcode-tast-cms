<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Store\RedisStore;
use App\Service\MaterialService;
use App\Service\TagService;
use App\Support\Resp;
use App\Support\TaskException;

final class CategoryController
{
    public static function tree(array $req): void
    {
        Resp::ok(\App\Service\CategoryService::tree());
    }

    /** 分类下可用标签（来自在架素材实际使用的标签） */
    public static function tags(array $req): void
    {
        $cid = (string)$req['params']['id'];
        $r = RedisStore::client();
        $ids = $r->zRevRange(RedisStore::k("idx:mat:cat:$cid"), 0, -1) ?: [];
        $counts = [];
        foreach ($ids as $mid) {
            $meta = MaterialService::cachedMeta((string)$mid);
            if (!$meta) {
                continue;
            }
            foreach ($meta['tag_keys'] as $tk) {
                $counts[$tk] = ($counts[$tk] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counts as $tk => $cnt) {
            $t = TagService::find($tk);
            if ($t && ($t['status'] ?? 'on') === 'on') {
                $out[] = ['key' => $t['key'], 'name' => $t['name'], 'material_count' => $cnt];
            }
        }
        usort($out, fn($a, $b) => $b['material_count'] <=> $a['material_count']);
        Resp::ok($out);
    }
}

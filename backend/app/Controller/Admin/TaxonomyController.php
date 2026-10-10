<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\CategoryService;
use App\Service\TagService;
use App\Support\Resp;

final class TaxonomyController
{
    public static function categories(array $req): void
    {
        Resp::ok(['list' => CategoryService::all()]);
    }

    public static function saveCategory(array $req): void
    {
        $in = $req['input'];
        if (isset($req['params']['id'])) {
            $in['id'] = $req['params']['id'];
        }
        Resp::ok(CategoryService::save($in));
    }

    public static function deleteCategory(array $req): void
    {
        CategoryService::delete((string)$req['params']['id']);
        Resp::ok(null, '已删除');
    }

    public static function tags(array $req): void
    {
        Resp::ok(['list' => TagService::all()]);
    }

    public static function saveTag(array $req): void
    {
        $in = $req['input'];
        if (isset($req['params']['key'])) {
            $in['key'] = $req['params']['key'];
        } else {
            $in['new_key'] = (string)($in['key'] ?? '');
            unset($in['key']);
        }
        Resp::ok(TagService::save($in));
    }

    public static function deleteTag(array $req): void
    {
        TagService::delete((string)$req['params']['key']);
        Resp::ok(null, '已删除');
    }
}

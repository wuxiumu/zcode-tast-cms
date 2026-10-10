<?php

declare(strict_types=1);

/** 清理冒烟测试残留：下架冒烟素材、停用冒烟分类/标签（保留数据用于追溯） */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\CategoryService;
use App\Service\MaterialService;
use App\Service\TagService;

$n = 0;
foreach (\App\Store\FileStore::allMaterials() as $m) {
    if (str_contains($m['title'], '冒烟素材') && $m['status'] === 'published') {
        MaterialService::offline($m['id']);
        $n++;
    }
}
foreach (CategoryService::all() as $c) {
    if (str_contains($c['name'], '冒烟分类') && $c['status'] === 'on') {
        CategoryService::save(['id' => $c['id'], 'name' => $c['name'], 'status' => 'off', 'sort' => $c['sort']]);
    }
}
foreach (TagService::all() as $t) {
    if (str_starts_with($t['key'], 'smk') && ($t['status'] ?? 'on') === 'on') {
        TagService::save([
            'key' => $t['key'], 'name' => $t['name'], 'status' => 'off',
            'guide' => $t['guide'] ?? '', 'type' => $t['type'] ?? 'channel',
        ]);
    }
}
echo "cleaned: {$n} smoke materials offline\n";

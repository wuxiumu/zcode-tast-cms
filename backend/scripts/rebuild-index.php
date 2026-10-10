<?php

declare(strict_types=1);

/** 从 storage/*.md 全量重建 Redis 索引与素材缓存（内容库是事实源，Redis 可随时重建） */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\MaterialService;
use App\Store\FileStore;
use App\Store\RedisStore;

$n = 0;
foreach (FileStore::allMaterials() as $m) {
    if (($m['status'] ?? '') === 'published') {
        MaterialService::refreshIndex($m);
        $n++;
    } else {
        MaterialService::removeIndex($m);
    }
}
echo "rebuilt index for {$n} published materials\n";

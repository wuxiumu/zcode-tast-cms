<?php

declare(strict_types=1);

/** 兜底过期扫描：crontab 每 5 分钟执行一次
 *  \*\/5 * * * * php /path/to/backend/scripts/expire-sweep.php >> /tmp/sucaiku-sweep.log 2>&1
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\TaskService;

echo date('Y-m-d H:i:s'), ' expired: ', TaskService::expireSweep(), PHP_EOL;

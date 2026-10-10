<?php

declare(strict_types=1);

namespace App\Support;

use App\Store\RedisStore;

final class RateLimit
{
    /** 固定窗口计数：check('u_000001', 'claim') */
    public static function check(string $bucket, string $action): void
    {
        $conf = Config::get("app.rate.$action");
        if (!is_array($conf)) {
            return;
        }
        [$limit, $window] = $conf;
        $r = RedisStore::client();
        $key = RedisStore::k("rate:$action:$bucket:" . intdiv(time(), max(1, (int)$window)));
        $n = $r->incr($key);
        if ($n === 1) {
            $r->expire($key, (int)$window + 60);
        }
        if ($n > (int)$limit) {
            throw new TaskException(2005, '操作过于频繁，请稍后再试');
        }
    }
}

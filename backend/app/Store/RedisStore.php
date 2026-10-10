<?php

declare(strict_types=1);

namespace App\Store;

use App\Support\Config;

final class RedisStore
{
    private static ?\Redis $client = null;

    public static function client(): \Redis
    {
        if (self::$client === null) {
            $c = new \Redis();
            $c->pconnect(
                (string)Config::get('redis.host', '127.0.0.1'),
                (int)Config::get('redis.port', 6379),
                2.0
            );
            $auth = Config::get('redis.password');
            if ($auth) {
                $c->auth((string)$auth);
            }
            $c->setOption(\Redis::OPT_PREFIX, '');
            self::$client = $c;
        }
        return self::$client;
    }

    /** 业务键统一前缀：RedisStore::k('task:t_1') => 'sc:task:t_1' */
    public static function k(string $key): string
    {
        return Config::get('redis.prefix', 'sc:') . $key;
    }
}

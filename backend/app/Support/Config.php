<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static array $items = [];

    public static function init(array $items): void
    {
        self::$items = $items;
    }

    /** 点号取值：Config::get('app.jwt_secret') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $val = self::$items;
        foreach (explode('.', $key) as $p) {
            if (!is_array($val) || !array_key_exists($p, $val)) {
                return $default;
            }
            $val = $val[$p];
        }
        return $val;
    }
}

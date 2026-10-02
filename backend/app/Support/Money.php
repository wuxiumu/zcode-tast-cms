<?php

declare(strict_types=1);

namespace App\Support;

/** 金额工具：存储一律用「分」（整数），出入参用两位小数字符串 */
final class Money
{
    public static function toCents(mixed $yuan): int
    {
        if ($yuan === null || $yuan === '') {
            return 0;
        }
        return (int)round(((float)$yuan) * 100);
    }

    public static function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

final class Http
{
    public static function jsonInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function str(array $input, string $key, string $default = ''): string
    {
        $v = $input[$key] ?? $default;
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public static function int(array $input, string $key, int $default = 0): int
    {
        $v = $input[$key] ?? $default;
        return is_numeric($v) ? (int)$v : $default;
    }
}

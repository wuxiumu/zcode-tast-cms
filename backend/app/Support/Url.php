<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 资源 URL 助手：
 * - 存储（md/Redis）统一存相对路径（/uploads/...），换 OSS 时只改 ASSET_BASE_URL + rebuild-index；
 * - API 出参统一经 asset() 输出完整地址；
 * - 入参经 toPath() 归一化回相对路径。
 */
final class Url
{
    public static function asset(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = (string)Config::get('app.asset_base_url', '');
        return $base === '' ? $path : rtrim($base, '/') . $path;
    }

    public static function toPath(string $url): string
    {
        $u = trim($url);
        if (preg_match('#^https?://[^/]+(/.*)$#', $u, $m)) {
            return $m[1];
        }
        return $u;
    }
}

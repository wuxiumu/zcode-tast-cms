<?php

declare(strict_types=1);

namespace App\Store;

/**
 * 内容库（source of truth）：Markdown 文件 + JSON frontmatter。
 * 结构化字段全部在 frontmatter；Markdown 正文为展示给用户的说明。
 */
final class FileStore
{
    public static function storageDir(): string
    {
        return BASE_PATH . '/storage';
    }

    /** @return array{0:?array,1:string} [frontmatter, 正文] */
    public static function readMd(string $path): array
    {
        if (!is_file($path)) {
            return [null, ''];
        }
        $text = (string)file_get_contents($path);
        if (!str_starts_with($text, "---\n")) {
            return [null, $text];
        }
        $end = strpos($text, "\n---", 4);
        if ($end === false) {
            return [null, ''];
        }
        $meta = json_decode(substr($text, 4, $end - 4), true);
        $body = substr($text, $end + 4);
        return [is_array($meta) ? $meta : null, ltrim($body, "\r\n")];
    }

    public static function writeMd(string $path, array $meta, string $body = ''): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = @file_put_contents($path, "---\n$json\n---\n" . rtrim($body) . "\n", LOCK_EX);
        if ($bytes === false) {
            // 写入失败必须显式报错：服务器常见于 storage 目录属主不是 php-fpm 运行用户（宝塔一般为 www）
            throw new \App\Support\TaskException(
                5000,
                '内容库写入失败（storage 目录权限不足）。服务器执行：chown -R www:www ' . basename(dirname($dir, 2)) . '/storage'
            );
        }
    }

    public static function materialPath(string $id): string
    {
        return self::storageDir() . "/materials/$id.md";
    }

    /** @return array<string,array> id => meta，新素材在前 */
    public static function allMaterials(): array
    {
        $out = [];
        foreach (glob(self::storageDir() . '/materials/*.md') ?: [] as $f) {
            [$meta] = self::readMd($f);
            if (is_array($meta) && !empty($meta['id'])) {
                $out[(string)$meta['id']] = $meta;
            }
        }
        krsort($out);
        return $out;
    }

    public static function loadCategories(): array
    {
        [$m] = self::readMd(self::storageDir() . '/categories.md');
        return is_array($m['categories'] ?? null) ? $m['categories'] : [];
    }

    public static function saveCategories(array $list): void
    {
        self::writeMd(
            self::storageDir() . '/categories.md',
            ['categories' => $list],
            "# 分类数据\n\nfrontmatter 为结构化数据（JSON），请勿手工改格式。删除二级分类前须先下架其下全部素材。\n"
        );
    }

    public static function loadTags(): array
    {
        [$m] = self::readMd(self::storageDir() . '/tags.md');
        return is_array($m['tags'] ?? null) ? $m['tags'] : [];
    }

    public static function saveTags(array $list): void
    {
        self::writeMd(
            self::storageDir() . '/tags.md',
            ['tags' => $list],
            "# 标签数据\n\nfrontmatter 为结构化数据（JSON）。key 为素材引用标识，创建后不要修改。\n"
        );
    }
}

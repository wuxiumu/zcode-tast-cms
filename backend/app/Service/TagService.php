<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\FileStore;
use App\Support\TaskException;

final class TagService
{
    public static function all(): array
    {
        return FileStore::loadTags();
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $t) {
            if (($t['key'] ?? '') === $key) {
                return $t;
            }
        }
        return null;
    }

    public static function save(array $in): array
    {
        $key = trim((string)($in['key'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new TaskException(1001, '标签名称必填');
        }
        $all = self::all();
        if ($key === '') {
            $key = (string)($in['new_key'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $key)) {
                throw new TaskException(1001, '标签 key 须为 2~20 位小写字母/数字/下划线，字母开头');
            }
            if (self::find($key)) {
                throw new TaskException(1001, '标签 key 已存在');
            }
            $row = [
                'key'    => $key,
                'name'   => $name,
                'type'   => in_array($in['type'] ?? 'channel', ['channel', 'other'], true) ? ($in['type'] ?? 'channel') : 'channel',
                'guide'  => trim((string)($in['guide'] ?? '')),
                'status' => ($in['status'] ?? 'on') === 'off' ? 'off' : 'on',
            ];
            $all[] = $row;
            FileStore::saveTags($all);
            return $row;
        }

        $row = null;
        foreach ($all as $i => $t) {
            if (($t['key'] ?? '') === $key) {
                $all[$i]['name'] = $name;
                $all[$i]['guide'] = trim((string)($in['guide'] ?? $t['guide'] ?? ''));
                $all[$i]['status'] = ($in['status'] ?? $t['status']) === 'off' ? 'off' : 'on';
                if (isset($in['type'])) {
                    $all[$i]['type'] = in_array($in['type'], ['channel', 'other'], true) ? $in['type'] : ($t['type'] ?? 'channel');
                }
                $row = $all[$i];
                break;
            }
        }
        if ($row === null) {
            throw new TaskException(1001, '标签不存在');
        }
        FileStore::saveTags($all);
        return $row;
    }

    public static function delete(string $key): void
    {
        $all = self::all();
        $found = false;
        $rest = [];
        foreach ($all as $t) {
            if (($t['key'] ?? '') === $key) {
                $found = true;
            } else {
                $rest[] = $t;
            }
        }
        if (!$found) {
            throw new TaskException(1001, '标签不存在');
        }
        foreach (FileStore::allMaterials() as $m) {
            if (in_array($key, $m['tag_keys'] ?? [], true)) {
                throw new TaskException(1001, '标签已被素材引用，请先解除引用');
            }
        }
        FileStore::saveTags($rest);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\FileStore;
use App\Store\RedisStore;
use App\Support\TaskException;

final class CategoryService
{
    public static function all(): array
    {
        return FileStore::loadCategories();
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $c) {
            if (($c['id'] ?? '') === $id) {
                return $c;
            }
        }
        return null;
    }

    /** 用户端分类树（含二级分类的素材数） */
    public static function tree(): array
    {
        $r = RedisStore::client();
        $roots = [];
        $children = [];
        foreach (self::all() as $c) {
            if (($c['status'] ?? 'on') !== 'on') {
                continue;
            }
            if (($c['parent_id'] ?? '0') === '0') {
                $roots[] = $c;
            } else {
                $children[(string)$c['parent_id']][] = $c;
            }
        }
        usort($roots, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
        $tree = [];
        foreach ($roots as $root) {
            $kids = $children[$root['id']] ?? [];
            usort($kids, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
            $list = [];
            foreach ($kids as $kid) {
                $list[] = [
                    'id'   => $kid['id'],
                    'name' => $kid['name'],
                    'material_count' => (int)($r->zCard(RedisStore::k('idx:mat:cat:' . $kid['id'])) ?: 0),
                ];
            }
            $tree[] = ['id' => $root['id'], 'name' => $root['name'], 'children' => $list];
        }
        return $tree;
    }

    /** 新建或更新（有 id 即更新） */
    public static function save(array $in): array
    {
        $id = trim((string)($in['id'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        $parentId = trim((string)($in['parent_id'] ?? '0'));
        if ($name === '') {
            throw new TaskException(1001, '分类名称必填');
        }
        $all = self::all();
        if ($id === '') {
            if ($parentId !== '0') {
                $parent = self::find($parentId);
                if (!$parent || ($parent['parent_id'] ?? '0') !== '0') {
                    throw new TaskException(1001, '父分类不存在（仅支持两级分类）');
                }
            }
            $maxNo = 0;
            foreach ($all as $c) {
                if (preg_match('/^cat_(\d+)$/', (string)$c['id'], $m)) {
                    $maxNo = max($maxNo, (int)$m[1]);
                }
            }
            $row = [
                'id'        => 'cat_' . str_pad((string)($maxNo + 1), 4, '0', STR_PAD_LEFT),
                'name'      => $name,
                'parent_id' => $parentId,
                'sort'      => (int)($in['sort'] ?? 0),
                'status'    => ($in['status'] ?? 'on') === 'off' ? 'off' : 'on',
            ];
            $all[] = $row;
            FileStore::saveCategories($all);
            return $row;
        }

        $row = null;
        foreach ($all as $i => $c) {
            if (($c['id'] ?? '') === $id) {
                $all[$i]['name'] = $name;
                $all[$i]['sort'] = (int)($in['sort'] ?? $c['sort'] ?? 0);
                $all[$i]['status'] = ($in['status'] ?? $c['status']) === 'off' ? 'off' : 'on';
                $row = $all[$i];
                break;
            }
        }
        if ($row === null) {
            throw new TaskException(1001, '分类不存在');
        }
        FileStore::saveCategories($all);
        return $row;
    }

    public static function delete(string $id): void
    {
        $all = self::all();
        $row = null;
        $rest = [];
        foreach ($all as $c) {
            if (($c['id'] ?? '') === $id) {
                $row = $c;
            } else {
                $rest[] = $c;
            }
        }
        if ($row === null) {
            throw new TaskException(1001, '分类不存在');
        }
        if (($row['parent_id'] ?? '0') === '0') {
            foreach ($rest as $c) {
                if (($c['parent_id'] ?? '') === $id) {
                    throw new TaskException(1001, '请先删除该分类下的子分类');
                }
            }
        } else {
            foreach (FileStore::allMaterials() as $m) {
                if (($m['category_id'] ?? '') === $id) {
                    throw new TaskException(1001, '该分类下仍有素材，请先删除或移动');
                }
            }
        }
        FileStore::saveCategories($rest);
    }
}

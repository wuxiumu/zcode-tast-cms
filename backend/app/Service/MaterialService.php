<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\FileStore;
use App\Store\RedisStore;
use App\Support\Money;
use App\Support\TaskException;
use App\Support\Url;

final class MaterialService
{
    public static function find(string $id): ?array
    {
        [$meta, ] = FileStore::readMd(FileStore::materialPath($id));
        return is_array($meta) ? $meta : null;
    }

    /** 用户端优先走 Redis 缓存 */
    public static function cachedMeta(string $id): ?array
    {
        $r = RedisStore::client();
        $raw = $r->get(RedisStore::k("mat:$id"));
        if (is_string($raw) && $raw !== '') {
            $m = json_decode($raw, true);
            if (is_array($m)) {
                return $m;
            }
        }
        $file = self::find($id);
        if (!$file) {
            return null;
        }
        $meta = self::publicMeta($file);
        if ($file['status'] === 'published') {
            $r->set(RedisStore::k("mat:$id"), (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $meta;
    }

    public static function create(array $in): array
    {
        $m = self::normalize($in, null);
        $r = RedisStore::client();
        $id = 'mat_' . str_pad((string)$r->incr(RedisStore::k('seq:material')), 5, '0', STR_PAD_LEFT);
        $m['id'] = $id;
        $m['status'] = 'draft';
        $now = date('Y-m-d H:i:s');
        $m['created_at'] = $now;
        $m['updated_at'] = $now;
        FileStore::writeMd(FileStore::materialPath($id), $m, self::noticeOf($in, ''));
        return $m;
    }

    public static function update(string $id, array $in): array
    {
        $old = self::find($id);
        if (!$old) {
            throw new TaskException(2001, '素材不存在');
        }
        [, $oldBody] = FileStore::readMd(FileStore::materialPath($id));
        $m = self::normalize($in, $old);
        $m['id'] = $id;
        $m['status'] = $old['status'];
        $m['created_at'] = $old['created_at'] ?? date('Y-m-d H:i:s');
        $m['updated_at'] = date('Y-m-d H:i:s');
        if (isset($old['published_at'])) {
            $m['published_at'] = $old['published_at'];
        }
        FileStore::writeMd(FileStore::materialPath($id), $m, self::noticeOf($in, $oldBody));
        if ($m['status'] === 'published') {
            self::removeIndex($old);
            self::refreshIndex($m);
        }
        return $m;
    }

    public static function publish(string $id): array
    {
        $m = self::find($id);
        if (!$m) {
            throw new TaskException(2001, '素材不存在');
        }
        if ($m['status'] === 'published') {
            return $m;
        }
        $m['status'] = 'published';
        $m['published_at'] = date('Y-m-d H:i:s');
        [, $body] = FileStore::readMd(FileStore::materialPath($id));
        FileStore::writeMd(FileStore::materialPath($id), $m, $body);
        self::refreshIndex($m);
        return $m;
    }

    public static function offline(string $id): array
    {
        $m = self::find($id);
        if (!$m) {
            throw new TaskException(2001, '素材不存在');
        }
        if ($m['status'] !== 'offline') {
            $m['status'] = 'offline';
            [, $body] = FileStore::readMd(FileStore::materialPath($id));
            FileStore::writeMd(FileStore::materialPath($id), $m, $body);
            self::removeIndex($m);
        }
        return $m;
    }

    public static function delete(string $id): void
    {
        $m = self::find($id);
        if (!$m) {
            throw new TaskException(2001, '素材不存在');
        }
        if ($m['status'] === 'published') {
            throw new TaskException(1001, '请先下架再删除');
        }
        $stat = RedisStore::client()->hGetAll(RedisStore::k("stat:mat:$id"));
        if ((int)($stat['claim'] ?? 0) > 0) {
            throw new TaskException(1001, '已有用户领取过该素材，不可删除');
        }
        if (!@unlink(FileStore::materialPath($id))) {
            throw new TaskException(5000, '删除素材文件失败（uploads/storage 目录权限不足）');
        }
        self::removeIndex($m);
    }

    /** 素材计数（管理端列表/详情用） */
    public static function stats(string $id): array
    {
        $r = RedisStore::client();
        $stat = $r->hGetAll(RedisStore::k("stat:mat:$id"));
        $quota = $r->get(RedisStore::k("quota:mat:$id"));
        return [
            'claim'   => (int)($stat['claim'] ?? 0),
            'submit'  => (int)($stat['submit'] ?? 0),
            'approve' => (int)($stat['approve'] ?? 0),
            'quota_left' => $quota === false ? -1 : (int)$quota,
        ];
    }

    // ------------------------------------------------------------------

    private static function noticeOf(array $in, string $fallback): string
    {
        if (array_key_exists('notice', $in)) {
            return (string)$in['notice'];
        }
        return $fallback;
    }

    private static function normalize(array $in, ?array $base): array
    {
        $get = function (string $k, mixed $d = null) use ($in, $base): mixed {
            if (array_key_exists($k, $in)) {
                return $in[$k];
            }
            return $base[$k] ?? $d;
        };

        $title = trim((string)$get('title', ''));
        if ($title === '') {
            throw new TaskException(1001, '标题必填');
        }
        $type = (string)$get('type', 'image_text');
        if (!in_array($type, ['image_text', 'video_text'], true)) {
            throw new TaskException(1001, 'type 须为 image_text 或 video_text');
        }
        $cid = trim((string)$get('category_id', ''));
        $cat = CategoryService::find($cid);
        if (!$cat || ($cat['parent_id'] ?? '0') === '0') {
            throw new TaskException(1001, '请选择二级分类');
        }
        $tags = $get('tag_keys', []);
        if (!is_array($tags)) {
            throw new TaskException(1001, 'tag_keys 须为数组（预留标签位，可多选）');
        }
        $tags = array_values(array_unique(array_map('strval', $tags)));
        if (!$tags) {
            throw new TaskException(1001, '请至少选择一个标签');
        }
        foreach ($tags as $t) {
            if (!TagService::find($t)) {
                throw new TaskException(1001, "标签 {$t} 不存在");
            }
        }
        $text = trim((string)$get('text', ''));
        if ($text === '') {
            throw new TaskException(1001, '发布文案必填');
        }
        $images = $get('images', []);
        $images = is_array($images)
            ? array_values(array_filter(array_map(fn($u) => Url::toPath((string)$u), $images), fn($u) => $u !== ''))
            : [];
        if ($type === 'image_text' && (count($images) < 1 || count($images) > 9)) {
            throw new TaskException(1001, '图文素材需上传 1~9 张图片');
        }
        $video = Url::toPath(trim((string)$get('video', '')));
        if ($type === 'video_text' && $video === '') {
            throw new TaskException(1001, '视频素材需上传视频文件');
        }

        $reward = $get('reward', []);
        $reward = is_array($reward) ? $reward : [];
        $firstYuan = $reward['first'] ?? 0;
        if (!is_numeric($firstYuan) || (float)$firstYuan < 0) {
            throw new TaskException(1001, '首发奖励金额非法');
        }
        $rules = $reward['stats_rules'] ?? [];
        $rules = is_array($rules) ? array_values($rules) : [];
        $cleanRules = [];
        foreach ($rules as $r) {
            if (!is_array($r)) {
                continue;
            }
            $metric = (string)($r['metric'] ?? '');
            $gte = (int)($r['gte'] ?? -1);
            $rw = $r['reward'] ?? -1;
            if (!in_array($metric, ['views', 'likes', 'comments'], true) || $gte < 0 || !is_numeric($rw) || (float)$rw < 0) {
                throw new TaskException(1001, '数据奖励规则非法（metric/gte/reward）');
            }
            $cleanRules[] = ['metric' => $metric, 'gte' => $gte, 'reward' => Money::fmt(Money::toCents($rw))];
        }
        $stackMode = ($reward['stack_mode'] ?? 'highest') === 'all' ? 'all' : 'highest';
        $maxRounds = max(0, min(10, (int)($reward['max_bonus_rounds'] ?? 0)));

        $quota = (int)$get('quota', -1);
        if ($quota < -1) {
            throw new TaskException(1001, 'quota 须 >= -1（-1 表示不限）');
        }

        return [
            'title'          => $title,
            'type'           => $type,
            'category_id'    => $cid,
            'tag_keys'       => $tags,
            'cover'          => Url::toPath(trim((string)$get('cover', ''))),
            'images'         => $images,
            'video'          => $video,
            'text'           => $text,
            'reward'         => [
                'first'            => Money::fmt(Money::toCents($firstYuan)),
                'stats_rules'      => $cleanRules,
                'stack_mode'       => $stackMode,
                'max_bonus_rounds' => $maxRounds,
            ],
            'quota'          => $quota,
            'per_user_limit' => max(1, (int)$get('per_user_limit', 1)),
            'window_hours'   => max(1, (int)$get('window_hours', 48)),
        ];
    }

    public static function refreshIndex(array $m): void
    {
        $r = RedisStore::client();
        $id = $m['id'];
        $meta = self::publicMeta($m);
        $r->set(RedisStore::k("mat:$id"), (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)$m['quota'] >= 0) {
            $claimed = (int)($r->hGet(RedisStore::k("stat:mat:$id"), 'claim') ?: 0);
            $r->set(RedisStore::k("quota:mat:$id"), (string)max(0, (int)$m['quota'] - $claimed));
        } else {
            $r->del(RedisStore::k("quota:mat:$id"));
        }
        $score = (float)strtotime($m['published_at'] ?? 'now');
        $cid = $m['category_id'];
        $r->zAdd(RedisStore::k("idx:mat:cat:$cid"), $score, $id);
        foreach ($m['tag_keys'] as $t) {
            $r->zAdd(RedisStore::k("idx:mat:cattag:$cid:$t"), $score, $id);
        }
    }

    public static function removeIndex(array $m): void
    {
        $r = RedisStore::client();
        $id = $m['id'];
        $r->del(RedisStore::k("mat:$id"));
        $cid = $m['category_id'] ?? '';
        if ($cid === '') {
            return;
        }
        $r->zRem(RedisStore::k("idx:mat:cat:$cid"), $id);
        foreach ($m['tag_keys'] ?? [] as $t) {
            $r->zRem(RedisStore::k("idx:mat:cattag:$cid:$t"), $id);
        }
    }

    /** 对外（用户端）安全字段 */
    public static function publicMeta(array $m): array
    {
        $reward = $m['reward'] ?? [];
        $first = Money::toCents($reward['first'] ?? 0);
        $vals = array_map(fn($r) => Money::toCents($r['reward'] ?? 0), $reward['stats_rules'] ?? []);
        $bonus = ($reward['stack_mode'] ?? 'highest') === 'all'
            ? (int)array_sum($vals)
            : (int)max($vals ?: [0]);
        $cover = Url::asset(trim((string)($m['cover'] ?? '')) ?: ($m['images'][0] ?? ''));
        return [
            'id'               => $m['id'],
            'title'            => $m['title'],
            'type'             => $m['type'],
            'category_id'      => $m['category_id'],
            'tag_keys'         => $m['tag_keys'] ?? [],
            'cover'            => $cover,
            'images'           => array_map(fn($u) => Url::asset((string)$u), $m['images'] ?? []),
            'video'            => Url::asset((string)($m['video'] ?? '')),
            'text'             => $m['text'] ?? '',
            'reward'           => $reward,
            'reward_first'     => Money::fmt($first),
            'reward_max'       => Money::fmt($first + $bonus),
            'quota'            => (int)($m['quota'] ?? -1),
            'per_user_limit'   => (int)($m['per_user_limit'] ?? 1),
            'window_hours'     => (int)($m['window_hours'] ?? 48),
            'status'           => $m['status'],
            'published_at'     => $m['published_at'] ?? null,
        ];
    }
}

<?php

declare(strict_types=1);

/**
 * 全流程冒烟测试（不需要 MySQL，需要 Redis + PHP 服务已启动）
 * 用法: php scripts/smoke.php [base_url]
 */

$base = $argv[1] ?? 'http://127.0.0.1:8080';
$rand = substr(md5((string)mt_rand()), 0, 6);

function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $fileField = null, ?string $filePath = null): array
{
    global $base;
    $ch = curl_init($base . $path);
    $headers = [];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    if ($fileField) {
        $post = $body ?? [];
        $post[$fileField] = new CURLFile($filePath);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        fwrite(STDERR, 'CURL ERR: ' . curl_error($ch) . PHP_EOL);
    }
    curl_close($ch);
    $data = json_decode((string)$res, true);
    return is_array($data) ? $data : ['code' => -1, 'msg' => (string)$res];
}

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, mixed $detail = null): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name  " . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// 生成测试图片（GD）
function makeImage(string $path): void
{
    $im = imagecreatetruecolor(120, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    imagepng($im, $path);
    imagedestroy($im);
}

echo "== 1. 基础 ==\n";
$r = call('GET', '/api/v1/ping');
check('ping', ($r['code'] ?? -1) === 0, $r);

$r = call('POST', '/api/v1/admin/auth/login', ['username' => 'admin', 'password' => 'admin123']);
$admin = $r['data']['token'] ?? '';
check('管理员登录', $admin !== '', $r);

echo "== 2. 分类/标签/素材 ==\n";
$r = call('POST', '/api/v1/admin/categories', ['name' => "冒烟分类-$rand", 'parent_id' => 'cat_1001'], $admin);
$cid = $r['data']['id'] ?? '';
check('创建二级分类', $cid !== '', $r);

$r = call('POST', '/api/v1/admin/tags', ['key' => "smk$rand", 'name' => "冒烟标签-$rand", 'guide' => '测试引导'], $admin);
check('创建标签', ($r['code'] ?? -1) === 0, $r);
$tagKey = "smk$rand";

$img1 = sys_get_temp_dir() . "/smoke1_$rand.png";
$img2 = sys_get_temp_dir() . "/smoke2_$rand.png";
$shot = sys_get_temp_dir() . "/smokeshot_$rand.png";
makeImage($img1);
makeImage($img2);
makeImage($shot);

$r = call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $img1);
$u1 = $r['data']['url'] ?? '';
check('上传图片1', $u1 !== '', $r);
$r = call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $img2);
$u2 = $r['data']['url'] ?? '';
check('上传图片2', $u2 !== '', $r);

$r = call('POST', '/api/v1/admin/materials', [
    'title' => "冒烟素材-$rand", 'type' => 'image_text', 'category_id' => $cid,
    'tag_keys' => ['xhs', $tagKey], 'images' => [$u1, $u2],
    'text' => '这是一条冒烟测试文案 #测试',
    'notice' => '## 注意\n- 冒烟测试',
    'reward' => [
        'first' => '3.00',
        'stats_rules' => [
            ['metric' => 'views', 'gte' => 1000, 'reward' => '1.00'],
            ['metric' => 'views', 'gte' => 5000, 'reward' => '3.00'],
        ],
        'stack_mode' => 'highest',
        'max_bonus_rounds' => 3,
    ],
    'quota' => 10,
    'per_user_limit' => 1,
    'window_hours' => 48,
], $admin);
$mid = $r['data']['id'] ?? '';
check('创建素材(draft)', $mid !== '', $r);

$r = call('POST', "/api/v1/admin/materials/$mid/publish", null, $admin);
check('上架素材', ($r['data']['status'] ?? '') === 'published', $r);

echo "== 3. 用户端：浏览与领取 ==\n";
$phone = '139' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
$r = call('POST', '/api/v1/auth/login', ['phone' => $phone, 'code' => '123456']);
$user = $r['data']['token'] ?? '';
check('用户登录(自动注册)', $user !== '', $r);
$uid = $r['data']['user']['uid'] ?? '';

$r = call('POST', '/api/v1/auth/login', ['phone' => $phone, 'code' => '000000']);
check('错误验证码被拒', ($r['code'] ?? 0) === 1001, $r);

$r = call('GET', '/api/v1/categories', null, $user);
check('分类树', ($r['code'] ?? -1) === 0 && is_array($r['data']), $r);

$r = call('GET', "/api/v1/categories/$cid/tags", null, $user);
$tagKeys = array_column($r['data'] ?? [], 'key');
check('分类下标签含 xhs/冒烟标签', in_array('xhs', $tagKeys, true) && in_array($tagKey, $tagKeys, true), $r);

$r = call('GET', "/api/v1/materials?category_id=$cid&tag=$tagKey", null, $user);
$list = $r['data']['list'] ?? [];
check('任务大厅列表可见', count($list) === 1, $r);

// 多选领取：一个有效 + 一个无效 → 整体回滚
$r = call('POST', '/api/v1/tasks/claim', ['material_ids' => [$mid, 'mat_fake'], 'tag' => $tagKey], $user);
check('多选含无效素材→整体失败', ($r['code'] ?? 0) === 2001, $r);
$r = call('GET', '/api/v1/tasks', null, $user);
check('回滚后无任务', count($r['data']['list'] ?? []) === 0, $r);

$r = call('POST', '/api/v1/tasks/claim', ['material_ids' => [$mid], 'tag' => $tagKey], $user);
$tid = $r['data']['tasks'][0]['task_id'] ?? '';
check('领取成功', $tid !== '', $r);
if ($tid === '') {
    exit("领取失败，中止后续用例\n");
}

$r = call('POST', '/api/v1/tasks/claim', ['material_ids' => [$mid], 'tag' => $tagKey], $user);
check('重复领取被拒(2003)', ($r['code'] ?? 0) === 2003, $r);

echo "== 4. 提交首发凭证 ==\n";
$r = call('POST', "/api/v1/tasks/$tid/submit", ['platform_url' => 'notaurl'], $user);
check('非法链接被拒', ($r['code'] ?? 0) === 1001, $r);

$r = call('POST', "/api/v1/admin/uploads", null, $admin, 'file', $shot);
$shotUrl = $r['data']['url'] ?? '';

$r = call('POST', "/api/v1/tasks/$tid/submit", [
    'platform_url' => "https://www.xiaohongshu.com/explore/$rand",
    'screenshots' => [$shotUrl],
], $user);
check('提交首发凭证', ($r['data']['status'] ?? '') === 'submitted', $r);

$r = call('POST', "/api/v1/tasks/$tid/submit", [
    'platform_url' => "https://www.xiaohongshu.com/explore/other-$rand",
    'screenshots' => [$shotUrl],
], $user);
check('状态不允许重复提交(2004)', ($r['code'] ?? 0) === 2004, $r);

echo "== 5. 审核：首发奖励 ==\n";
$r = call('GET', '/api/v1/admin/submissions?type=first', null, $admin);
$subs = $r['data']['list'] ?? [];
$firstSub = null;
foreach ($subs as $s) {
    if (($s['task_id'] ?? '') === $tid) {
        $firstSub = $s;
    }
}
check('待审列表含该凭证', $firstSub !== null, $r);
$sid = $firstSub['sub_id'] ?? '';

$r = call('POST', "/api/v1/admin/submissions/$sid/review", ['action' => 'approve'], $admin);
check('审核通过→bonus_open', ($r['data']['task']['status'] ?? '') === 'bonus_open', $r);
check('首发奖励 3.00', ($r['data']['task']['first_reward'] ?? '') === '3.00', $r);

echo "== 6. 数据补发三轮 ==\n";
// 第 1 轮：views 6000 → 命中 5000 档 3.00（取最高）
$r = call('POST', "/api/v1/tasks/$tid/stats-submit", [
    'round' => 1, 'metrics' => ['views' => 6000, 'likes' => 200, 'comments' => 30],
    'screenshots' => [$shotUrl],
], $user);
check('补发第1轮→bonus_pending', ($r['data']['status'] ?? '') === 'bonus_pending', $r);
// 重复同轮截图 → 2005
$r = call('POST', "/api/v1/tasks/$tid/stats-submit", [
    'round' => 1, 'metrics' => ['views' => 9999], 'screenshots' => [$shotUrl],
], $user);
check('状态 pending 不可再交(2004)', ($r['code'] ?? 0) === 2004, $r);

$r = call('GET', '/api/v1/admin/submissions?type=stats', null, $admin);
$sid1 = null;
foreach ($r['data']['list'] ?? [] as $s) {
    if (($s['task_id'] ?? '') === $tid && ($s['round'] ?? 0) === 1) {
        $sid1 = $s['sub_id'];
    }
}
$r = call('POST', "/api/v1/admin/submissions/$sid1/review", ['action' => 'approve'], $admin);
check('第1轮奖励 3.00(取最高档)', ($r['data']['task']['bonus_paid'] ?? '') === '3.00', $r);
check('第1轮后仍 bonus_open', ($r['data']['task']['status'] ?? '') === 'bonus_open', $r);

// 第 2 轮：数据未达标 → 通过但 0 奖励
$shot2 = sys_get_temp_dir() . "/smokeshot2_$rand.png";
makeImage($shot2);
call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $shot2);
$shot2Url = '';
$r = call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $shot2);
$shot2Url = $r['data']['url'] ?? '';
call('POST', "/api/v1/tasks/$tid/stats-submit", [
    'round' => 2, 'metrics' => ['views' => 100], 'screenshots' => [$shot2Url],
], $user);
$r = call('GET', '/api/v1/admin/submissions?type=stats', null, $admin);
$sid2 = null;
foreach ($r['data']['list'] ?? [] as $s) {
    if (($s['task_id'] ?? '') === $tid && ($s['round'] ?? 0) === 2) {
        $sid2 = $s['sub_id'];
    }
}
$r = call('POST', "/api/v1/admin/submissions/$sid2/review", ['action' => 'approve'], $admin);
check('第2轮未达标 0 奖励', ($r['data']['task']['bonus_paid'] ?? '') === '3.00', $r);

// 第 3 轮：views 8000 → 3.00，第 3 轮后 finished
$shot3 = sys_get_temp_dir() . "/smokeshot3_$rand.png";
makeImage($shot3);
$r = call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $shot3);
$shot3Url = $r['data']['url'] ?? '';
call('POST', "/api/v1/tasks/$tid/stats-submit", [
    'round' => 3, 'metrics' => ['views' => 8000], 'screenshots' => [$shot3Url],
], $user);
$r = call('GET', '/api/v1/admin/submissions?type=stats', null, $admin);
$sid3 = null;
foreach ($r['data']['list'] ?? [] as $s) {
    if (($s['task_id'] ?? '') === $tid && ($s['round'] ?? 0) === 3) {
        $sid3 = $s['sub_id'];
    }
}
$r = call('POST', "/api/v1/admin/submissions/$sid3/review", ['action' => 'approve'], $admin);
check('第3轮后 finished', ($r['data']['task']['status'] ?? '') === 'finished', $r);
check('总奖励 9.00', ($r['data']['task']['total_reward'] ?? '') === '9.00', $r);

$r = call('GET', '/api/v1/rewards', null, $user);
$amounts = array_sum(array_map(fn($x) => (float)$x['amount'], $r['data']['list'] ?? []));
check('用户奖励流水合计 9.00', abs($amounts - 9.0) < 0.001, $r);

echo "== 7. 驳回与过期 ==\n";
// 另一个用户领取同素材并提交 → 驳回 → 回到 claimed
$phone2 = '137' . str_pad((string)mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
$r = call('POST', '/api/v1/auth/login', ['phone' => $phone2, 'code' => '123456']);
$user2 = $r['data']['token'] ?? '';
call('POST', '/api/v1/tasks/claim', ['material_ids' => [$mid], 'tag' => 'xhs'], $user2);
$r = call('GET', '/api/v1/tasks', null, $user2);
$tid2 = $r['data']['list'][0]['task_id'] ?? '';
$shot4 = sys_get_temp_dir() . "/smokeshot4_$rand.png";
makeImage($shot4);
$r = call('POST', '/api/v1/admin/uploads', null, $admin, 'file', $shot4);
call('POST', "/api/v1/tasks/$tid2/submit", [
    'platform_url' => "https://www.xiaohongshu.com/explore/u2-$rand",
    'screenshots' => [$r['data']['url']],
], $user2);
$r = call('GET', '/api/v1/admin/submissions?type=first', null, $admin);
$sid4 = null;
foreach ($r['data']['list'] ?? [] as $s) {
    if (($s['task_id'] ?? '') === $tid2) {
        $sid4 = $s['sub_id'];
    }
}
$r = call('POST', "/api/v1/admin/submissions/$sid4/review", ['action' => 'reject', 'reason' => '截图与链接不符'], $admin);
check('驳回→claimed+原因', ($r['data']['task']['status'] ?? '') === 'claimed'
    && ($r['data']['task']['last_reject_reason'] ?? '') === '截图与链接不符', $r);

// 过期：直接把 deadline 改到过去，再读列表触发惰性过期
if ($tid2 !== '') {
    require dirname(__DIR__) . '/app/bootstrap.php';
    \App\Store\RedisStore::client()->hSet(\App\Store\RedisStore::k("task:$tid2"), 'deadline_at', time() - 10);
}
$r = call('GET', '/api/v1/tasks', null, $user2);
$statuses = array_column($r['data']['list'] ?? [], 'status', 'task_id');
check('超时任务过期', ($statuses[$tid2] ?? '') === 'expired', $statuses);

echo "== 8. 管理端统计 ==\n";
$r = call('GET', '/api/v1/admin/stats/overview', null, $admin);
check('overview 可用', ($r['code'] ?? -1) === 0 && ($r['data']['claims_total'] ?? 0) >= 2, $r);
$r = call('GET', '/api/v1/admin/rewards', null, $admin);
check('奖励流水≥2笔', count($r['data']['list'] ?? []) >= 2, $r);

echo "\n================================\n";
echo $fail === 0 ? "ALL PASS ($pass checks)\n" : "FAILED: $fail / " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);

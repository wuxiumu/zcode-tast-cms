<?php

declare(strict_types=1);

/**
 * 演示数据补充脚本（幂等，可重复执行）：
 * - 分类：游戏/影视/资源/AI工具 及其二级分类（按名称判重）
 * - 素材：每个二级分类 1 条示范素材（seed_key 判重）
 *   · 游戏/影视/AI工具 → 直接上架（正式示范文案）
 *   · 资源类（破解软件/天涯神贴/赚钱秘籍）→ 仅建草稿占位，提示内容合规自审后再上架
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\CategoryService;
use App\Service\MaterialService;
use App\Store\FileStore;

// ---------- 分类 ----------
$catTree = [
    '游戏'   => ['蛋仔派对', '王者荣耀', '恋与深空'],
    '影视'   => ['斗破苍穹', '斗罗大陆', '吞噬星空'],
    '资源'   => ['破解软件', '天涯神贴', '赚钱秘籍'],
    'AI工具' => ['AI视频', 'AI图片', 'AI游戏'],
];

$allCats = CategoryService::all();
$rootId = function (string $name) use (&$allCats): string {
    foreach ($allCats as $c) {
        if ($c['name'] === $name && $c['parent_id'] === '0') {
            return $c['id'];
        }
    }
    $row = CategoryService::save(['name' => $name, 'parent_id' => '0', 'sort' => count($allCats) + 1]);
    $allCats = CategoryService::all();
    return $row['id'];
};
$childId = function (string $root, string $name) use (&$allCats, $rootId): string {
    $rid = $rootId($root);
    foreach ($allCats as $c) {
        if ($c['name'] === $name && $c['parent_id'] === $rid) {
            return $c['id'];
        }
    }
    $row = CategoryService::save(['name' => $name, 'parent_id' => $rid, 'sort' => 0]);
    $allCats = CategoryService::all();
    return $row['id'];
};

// ---------- 图片生成 ----------
$seq = 0;
$mk = function (array $rgb, string $label) use (&$seq): string {
    $seq++;
    $rel = '/uploads/' . date('Y/m/d') . '/seed_' . bin2hex(random_bytes(5)) . '.png';
    $im = imagecreatetruecolor(750, 1000);
    $bg = imagecolorallocate($im, ...$rgb);
    imagefill($im, 0, 0, $bg);
    imagestring($im, 5, 40, 480, $label, imagecolorallocate($im, 255, 255, 255));
    imagepng($im, BASE_PATH . $rel);
    imagedestroy($im);
    return $rel;
};

// ---------- 素材定义 ----------
// publish=false 的仅建草稿（资源类：内容由运营自审合规后再写再上架）
$seeds = [
    ['cat' => '王者荣耀', 'tags' => ['douyin', 'xhs'], 'publish' => true,
     'title' => '王者荣耀新赛季上分攻略｜打野节奏 3 分钟教学',
     'text' => "新赛季打野怎么带节奏？记住三件事：\n1. 开局 30 秒看敌方打野动向，反野或守野二选一\n2. 中期跟着经济最高的一条路打推塔\n3. 大龙团之前别单带，抱团才是版本答案\n#王者荣耀 #上分攻略 #MOBA",
     'notice' => "## 发布说明\n- 带话题 #王者荣耀\n- 不得使用代练/外挂等违规词汇",
     'reward' => ['first' => '2.50', 'stats_rules' => [['metric' => 'views', 'gte' => 2000, 'reward' => '1.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 2],
     'quota' => 50, 'palette' => [[50, 205, 50], [34, 139, 34], [0, 100, 0]]],

    ['cat' => '恋与深空', 'tags' => ['xhs', 'wb'], 'publish' => true,
     'title' => '恋与深空新活动预告｜这次的卡面也太会了',
     'text' => "恋与深空又双叒上新活动啦！\n这次的五星卡面光影绝了，剧情演出直接电影级\n肝活动之余记得领免费体力，评论区蹲一个同担～\n#恋与深空 #乙女游戏 #手游推荐",
     'notice' => "## 发布说明\n- 带话题 #恋与深空\n- 卡面图使用官方物料",
     'reward' => ['first' => '3.00', 'stats_rules' => [['metric' => 'likes', 'gte' => 200, 'reward' => '1.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 2],
     'quota' => 40, 'palette' => [[255, 182, 193], [221, 160, 221], [255, 192, 203]]],

    ['cat' => '斗罗大陆', 'tags' => ['douyin', 'bili'], 'publish' => true,
     'title' => '斗罗大陆动画高光混剪｜昊天锤名场面一剑破防',
     'text' => "唐三昊天锤一出，弹幕全屏刷爆！\n这季动画打斗分镜又进化了，配乐踩点封神\n你心中斗罗最燃的名场面是哪个？评论区聊聊～\n#斗罗大陆 #国漫推荐 #国漫崛起",
     'notice' => "## 发布说明\n- 使用官方物料，勿自行加水印\n- 带话题 #斗罗大陆",
     'reward' => ['first' => '4.00', 'stats_rules' => [['metric' => 'views', 'gte' => 3000, 'reward' => '2.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 3],
     'quota' => 30, 'palette' => [[25, 25, 112], [72, 61, 139], [123, 104, 238]]],

    ['cat' => '吞噬星空', 'tags' => ['douyin', 'xhs'], 'publish' => true,
     'title' => '吞噬星空名场面混剪｜罗峰的每一次进化都燃到炸',
     'text' => "从武者到行星级，罗峰的每次进化都是排面！\n宇宙级视效+硬核设定，科幻国漫天花板不解释\n还没入坑的真心推荐补番～\n#吞噬星空 #科幻国漫 #国漫推荐",
     'notice' => "## 发布说明\n- 带话题 #吞噬星空\n- 使用官方物料",
     'reward' => ['first' => '4.00', 'stats_rules' => [['metric' => 'views', 'gte' => 3000, 'reward' => '2.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 3],
     'quota' => 30, 'palette' => [[0, 0, 139], [25, 25, 112], [47, 79, 79]]],

    ['cat' => 'AI视频', 'tags' => ['xhs', 'douyin', 'sph'], 'publish' => true,
     'title' => '5 个免费 AI 视频工具盘点：文字一键生成短片',
     'text' => "不会拍摄也能做视频？这 5 个 AI 工具帮你搞定：\n1. 即梦：文生视频，运镜自然\n2. 可灵：图生视频，动态效果丝滑\n3. 剪映「图文成片」：文案直接变视频\n4. 度加创作工具：口播一键成片\n5. 通义听悟：视频转文案神器\n收藏起来慢慢试，效率直接翻倍！\n#AI工具 #AI视频 #效率工具",
     'notice' => "## 发布说明\n- 工具信息以官方为准，勿夸大宣传\n- 带话题 #AI工具",
     'reward' => ['first' => '3.50', 'stats_rules' => [['metric' => 'views', 'gte' => 2000, 'reward' => '1.50'], ['metric' => 'likes', 'gte' => 150, 'reward' => '0.50']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 3],
     'quota' => 60, 'palette' => [[30, 144, 255], [70, 130, 180], [100, 149, 237]]],

    ['cat' => 'AI图片', 'tags' => ['xhs', 'wb'], 'publish' => true,
     'title' => 'AI 绘图实测：一句话生成商用海报，附提示词',
     'text' => "AI 绘图到底能不能干活？实测三款：\n· 即梦 AI：中文理解强，海报排版直接能用\n· 通义万相：国风插画惊艳\n· Midjourney：质感天花板（需付费）\n文末附我常用的 3 条海报提示词，自取～\n#AI绘画 #AI工具 #设计",
     'notice' => "## 发布说明\n- 提示词可自由分享\n- 带话题 #AI绘画",
     'reward' => ['first' => '3.00', 'stats_rules' => [['metric' => 'likes', 'gte' => 200, 'reward' => '1.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 2],
     'quota' => 50, 'palette' => [[138, 43, 226], [186, 85, 211], [147, 112, 219]]],

    ['cat' => 'AI游戏', 'tags' => ['xhs', 'douyin'], 'publish' => true,
     'title' => 'AI 互动游戏推荐｜和 AI 玩文字冒险是什么体验',
     'text' => "试了几款 AI 互动游戏，直接上头：\n· AI 文字冒险：剧情随你选择实时生成，千人千面\n· AI 桌游裁判：规则吵不清楚让 AI 来判\n· AI 剧本杀：无主持人也能开一车\n游戏＋AI，玩法真的被打开了！\n#AI游戏 #游戏推荐 #文字冒险",
     'notice' => "## 发布说明\n- 带话题 #AI游戏\n- 客观体验，勿刷好评",
     'reward' => ['first' => '2.50', 'stats_rules' => [['metric' => 'views', 'gte' => 1500, 'reward' => '1.00']], 'stack_mode' => 'highest', 'max_bonus_rounds' => 2],
     'quota' => 40, 'palette' => [[0, 128, 128], [32, 178, 170], [95, 158, 160]]],

    // 资源类：仅建草稿占位，合规自审后再上架（不写推广文案）
    ['cat' => '破解软件', 'tags' => ['xhs'], 'publish' => false,
     'title' => '【草稿占位】破解软件类素材（上架前请自审）',
     'text' => '占位素材：发布前请替换为实际内容，并自行确认拥有分发权利、符合各平台社区规范与法律法规（盗版/破解软件推广会被平台限流封号，也有法律风险）。删除本条说明后再上架。',
     'notice' => "## 上架前检查\n- 内容合法性自审\n- 删除本占位说明",
     'reward' => ['first' => '0', 'stats_rules' => [], 'stack_mode' => 'highest', 'max_bonus_rounds' => 0],
     'quota' => -1, 'palette' => [[128, 128, 128], [169, 169, 169], [105, 105, 105]]],

    ['cat' => '天涯神贴', 'tags' => ['xhs'], 'publish' => false,
     'title' => '【草稿占位】天涯神贴类素材（上架前请自审）',
     'text' => '占位素材：天涯帖子版权归原作者与平台所有，整理转载请注明来源并确认授权；发布前请替换为实际内容并自审合规。删除本条说明后再上架。',
     'notice' => "## 上架前检查\n- 版权与来源标注\n- 删除本占位说明",
     'reward' => ['first' => '0', 'stats_rules' => [], 'stack_mode' => 'highest', 'max_bonus_rounds' => 0],
     'quota' => -1, 'palette' => [[112, 128, 144], [119, 136, 153], [176, 196, 222]]],

    ['cat' => '赚钱秘籍', 'tags' => ['xhs'], 'publish' => false,
     'title' => '【草稿占位】赚钱秘籍类素材（上架前请自审）',
     'text' => '占位素材：收益类内容各平台审核严格，请勿承诺收益、勿夸大宣传；发布前替换为实际内容并自审合规（违规易被平台下架处罚）。删除本条说明后再上架。',
     'notice' => "## 上架前检查\n- 不承诺收益、不夸大\n- 删除本占位说明",
     'reward' => ['first' => '0', 'stats_rules' => [], 'stack_mode' => 'highest', 'max_bonus_rounds' => 0],
     'quota' => -1, 'palette' => [[184, 134, 11], [218, 165, 32], [255, 215, 0]]],
];

// ---------- 执行 ----------
$matCreated = 0;
$matSkipped = 0;

// 判重：读取现有素材的 seed_key
$existingKeys = [];
foreach (glob(FileStore::storageDir() . '/materials/*.md') ?: [] as $f) {
    if (preg_match('/"seed_key":\s*"([^"]+)"/', (string)file_get_contents($f), $m)) {
        $existingKeys[$m[1]] = true;
    }
}

foreach ($seeds as $s) {
    // 按映射找到所属一级分类，再定位/创建二级分类
    $cid = null;
    foreach ($catTree as $root => $children) {
        if (in_array($s['cat'], $children, true)) {
            $cid = $childId($root, $s['cat']);
            break;
        }
    }
    if ($cid === null) {
        echo "!! 未知分类 {$s['cat']}，跳过\n";
        continue;
    }
    $key = 'seed_' . md5($s['title']);
    if (isset($existingKeys[$key])) {
        $matSkipped++;
        continue;
    }
    $images = [$mk($s['palette'][0], 'SEED ' . $s['cat']), $mk($s['palette'][1], 'SEED CONTENT')];
    $m = MaterialService::create([
        'title'          => $s['title'],
        'type'           => 'image_text',
        'category_id'    => $cid,
        'tag_keys'       => $s['tags'],
        'images'         => $images,
        'text'           => $s['text'],
        'notice'         => $s['notice'],
        'reward'         => $s['reward'],
        'quota'          => $s['quota'],
        'per_user_limit' => 1,
        'window_hours'   => 48,
    ]);
    $path = FileStore::materialPath($m['id']);
    [$meta, $body] = FileStore::readMd($path);
    $meta['seed_key'] = $key;
    FileStore::writeMd($path, $meta, $body);
    if ($s['publish']) {
        MaterialService::publish($m['id']);
    }
    $matCreated++;
}

echo "分类树与素材种子完成：新建素材 {$matCreated} 条（跳过已存在 {$matSkipped} 条），资源类 3 条为草稿不上架\n";

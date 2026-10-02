<?php

declare(strict_types=1);

/** 演示数据：在种子分类下创建并上架示例素材（幂等：已存在则跳过） */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Service\MaterialService;
use App\Store\FileStore;

foreach (glob(FileStore::storageDir() . '/materials/*.md') ?: [] as $f) {
    if (str_contains((string)file_get_contents($f), '"demo_marker"')) {
        echo "demo materials already seeded\n";
        exit(0);
    }
}

function demoImage(string $rel, array $rgb, string $label): void
{
    $im = imagecreatetruecolor(750, 1000);
    $bg = imagecolorallocate($im, ...$rgb);
    imagefill($im, 0, 0, $bg);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, 40, 480, $label, $white);
    imagepng($im, BASE_PATH . $rel);
    imagedestroy($im);
}

$today = date('Y/m/d');
$seq = 0;
$mk = function (array $rgb, string $label) use (&$seq, $today): string {
    $seq++;
    $rel = "/uploads/{$today}/demo_" . bin2hex(random_bytes(5)) . '.png';
    demoImage($rel, $rgb, $label);
    return $rel;
};

$demoSet = [
    [
        'category_id' => 'cat_2001', // 游戏-蛋仔派对
        'tag_keys'    => ['xhs'],
        'title'       => '蛋仔派对夏日新皮肤抢先看 | 果冻甜妹穿搭',
        'text'        => "夏天来啦！蛋仔派对新出的果冻皮肤也太可爱了趴🥹\n粉粉嫩嫩的配色，配上小裙子直接甜度爆表！\n姐妹们快冲，商城限时上新～\n#蛋仔派对 #夏日穿搭 #新皮肤",
        'notice'      => "## 发布说明\n- 必须带话题 #蛋仔派对\n- 三张图按顺序发布，不要加滤镜\n- 首图不要裁剪",
        'reward'      => ['first' => '3.00', 'stats_rules' => [
            ['metric' => 'views', 'gte' => 1000, 'reward' => '1.00'],
            ['metric' => 'views', 'gte' => 5000, 'reward' => '3.00'],
            ['metric' => 'likes', 'gte' => 100, 'reward' => '0.50'],
        ], 'stack_mode' => 'highest', 'max_bonus_rounds' => 3],
        'quota'       => 50,
        'palette'     => [[245, 180, 200], [255, 210, 220], [250, 160, 180]],
    ],
    [
        'category_id' => 'cat_2001', // 游戏-蛋仔派对
        'tag_keys'    => ['douyin'],
        'title'       => '蛋仔派对巅峰派对上分小技巧（附地图点位）',
        'text'        => "巅峰派对总是落地成盒？记住这3个点位就够了！\n1. 开局贴边走，避开人群\n2. 中期占高台，视野为王\n3. 决赛圈留一个滚蛋保命\n#蛋仔派对 #游戏攻略",
        'notice'      => "## 发布说明\n- 带话题 #蛋仔派对\n- 文案可改写但保留话题",
        'reward'      => ['first' => '2.00', 'stats_rules' => [
            ['metric' => 'views', 'gte' => 5000, 'reward' => '2.00'],
        ], 'stack_mode' => 'highest', 'max_bonus_rounds' => 2],
        'quota'       => 30,
        'palette'     => [[120, 170, 230], [100, 150, 210], [80, 130, 190]],
    ],
    [
        'category_id' => 'cat_3002', // 影视-斗破苍穹
        'tag_keys'    => ['xhs'],
        'title'       => '斗破苍穹年番名场面混剪｜三十年河东三十年河西',
        'text'        => "“三十年河东，三十年河西，莫欺少年穷！”\n萧炎这段找回场子的戏，看一次燃一次🔥\n你们最喜欢斗破哪个名场面？评论区聊聊～\n#斗破苍穹 #国漫推荐",
        'notice'      => "## 发布说明\n- 使用官方物料，勿自行加水印\n- 带话题 #斗破苍穹",
        'reward'      => ['first' => '5.00', 'stats_rules' => [
            ['metric' => 'views', 'gte' => 2000, 'reward' => '2.00'],
            ['metric' => 'comments', 'gte' => 50, 'reward' => '1.00'],
        ], 'stack_mode' => 'all', 'max_bonus_rounds' => 3],
        'quota'       => 20,
        'palette'     => [[90, 90, 110], [60, 60, 80], [130, 100, 160]],
    ],
];

$n = 0;
foreach ($demoSet as $d) {
    $palette = $d['palette'];
    unset($d['palette']);
    $images = [
        $mk($palette[0], 'DEMO COVER ' . ($n + 1)),
        $mk($palette[1], 'DEMO CONTENT'),
        $mk($palette[2], 'DEMO TAIL'),
    ];
    $m = MaterialService::create([
        'title'          => $d['title'],
        'type'           => 'image_text',
        'category_id'    => $d['category_id'],
        'tag_keys'       => $d['tag_keys'],
        'images'         => $images,
        'text'           => $d['text'],
        'notice'         => $d['notice'],
        'reward'         => $d['reward'],
        'quota'          => $d['quota'],
        'per_user_limit' => 1,
        'window_hours'   => 48,
    ]);
    $path = FileStore::materialPath($m['id']);
    [$meta, $body] = FileStore::readMd($path);
    $meta['demo_marker'] = true;
    FileStore::writeMd($path, $meta, $body);
    MaterialService::publish($m['id']);
    $n++;
}
echo "seeded {$n} demo materials\n";

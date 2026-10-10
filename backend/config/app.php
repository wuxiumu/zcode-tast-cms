<?php

declare(strict_types=1);

use App\Support\Env;

return [
    // 环境与调试
    'debug'        => Env::get('APP_DEBUG', '1') === '1',

    // 资源完整地址（图片/视频出参拼接）；留空 = 同源相对路径
    'asset_base_url' => (string)Env::get('ASSET_BASE_URL', ''),


    // JWT
    'jwt_secret'   => (string)\App\Support\Env::get('JWT_SECRET', 'sucaiku-dev-secret-change-me'),
    'jwt_ttl_user' => 7 * 86400,   // 用户端 7 天
    'jwt_ttl_admin'=> 12 * 3600,   // 管理端 12 小时

    // 模拟短信验证码（接真实短信前所有手机号统一用此码）
    'mock_sms_code' => (string)\App\Support\Env::get('MOCK_SMS_CODE', ''),

    // 上传限制
    'upload' => [
        'image_ext'  => ['jpg', 'jpeg', 'png', 'webp'],
        'image_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'image_max'  => 10 * 1024 * 1024,
        'video_ext'  => ['mp4', 'mov'],
        'video_mime' => ['video/mp4', 'video/quicktime'],
        'video_max'  => 200 * 1024 * 1024,
    ],

    // 限流：[动作 => 次数/窗口秒]
    'rate' => [
        'login'   => [5, 60],
        'claim'   => [20, 86400],
        'submit'  => [10, 86400],
    ],
];

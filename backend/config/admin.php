<?php

declare(strict_types=1);

use App\Support\Env;

// 管理员 bcrypt hash：请在 backend/.env 设置 ADMIN_PASSWORD_HASH
// 本地可用: php -r "echo password_hash('your-pass', PASSWORD_BCRYPT), PHP_EOL;"
// 勿在仓库写死明文口令（默认弱口令已移除）
$hash = (string)Env::get('ADMIN_PASSWORD_HASH', '');
$users = [];
if ($hash !== '') {
    $users[(string)Env::get('ADMIN_USER', 'admin')] = $hash;
}

return [
    'users' => $users,
];

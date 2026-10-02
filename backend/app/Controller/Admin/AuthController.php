<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Support\Config;
use App\Support\Http;
use App\Support\Jwt;
use App\Support\RateLimit;
use App\Support\Resp;

final class AuthController
{
    public static function login(array $req): void
    {
        $username = Http::str($req['input'], 'username');
        $password = (string)($req['input']['password'] ?? '');
        if ($username !== '') {
            RateLimit::check($username, 'login');
        }
        $hash = Config::get("admin.users.$username");
        if (!$hash || !password_verify($password, (string)$hash)) {
            Resp::error(1001, '账号或密码错误');
        }
        $token = Jwt::sign(['aud' => 'admin', 'sub' => $username], (int)Config::get('app.jwt_ttl_admin'));
        Resp::ok(['token' => $token, 'username' => $username]);
    }
}

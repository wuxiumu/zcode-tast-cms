<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Service\AuthService;
use App\Support\Http;
use App\Support\RateLimit;
use App\Support\Resp;

final class AuthController
{
    public static function login(array $req): void
    {
        $phone = Http::str($req['input'], 'phone');
        $code = Http::str($req['input'], 'code');
        if ($phone !== '') {
            RateLimit::check($phone, 'login');
        }
        Resp::ok(AuthService::login($phone, $code));
    }

    public static function me(array $req): void
    {
        Resp::ok(AuthService::present($req['ctx']['user']));
    }
}

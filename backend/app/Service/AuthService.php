<?php

declare(strict_types=1);

namespace App\Service;

use App\Store\RedisStore;
use App\Support\Config;
use App\Support\Jwt;
use App\Support\Money;
use App\Support\TaskException;

final class AuthService
{
    public static function login(string $phone, string $code): array
    {
        if (!preg_match('/^1\d{10}$/', $phone)) {
            throw new TaskException(1001, '手机号格式不正确');
        }
        if ($code !== (string)Config::get('app.mock_sms_code')) {
            throw new TaskException(1001, '验证码错误');
        }
        $r = RedisStore::client();
        $uid = $r->get(RedisStore::k("uidx:phone:$phone"));
        if (!is_string($uid) || $uid === '') {
            $uid = 'u_' . str_pad((string)$r->incr(RedisStore::k('seq:user')), 6, '0', STR_PAD_LEFT);
            $r->hMSet(RedisStore::k("user:$uid"), [
                'uid'                => $uid,
                'phone'              => $phone,
                'nickname'           => '发布者' . substr($uid, -4),
                'avatar'             => '',
                'wx_openid'          => '',
                'status'             => 'normal',
                'total_reward_cents' => 0,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);
            $r->set(RedisStore::k("uidx:phone:$phone"), $uid);
        }
        $user = $r->hGetAll(RedisStore::k("user:$uid"));
        $token = Jwt::sign(['aud' => 'user', 'uid' => $uid], (int)Config::get('app.jwt_ttl_user'));
        return ['token' => $token, 'user' => self::present($user)];
    }

    public static function present(array $u): array
    {
        return [
            'uid'          => (string)($u['uid'] ?? ''),
            'nickname'     => (string)($u['nickname'] ?? ''),
            'avatar'       => (string)($u['avatar'] ?? ''),
            'phone'        => (string)($u['phone'] ?? ''),
            'total_reward' => Money::fmt((int)($u['total_reward_cents'] ?? 0)),
        ];
    }
}

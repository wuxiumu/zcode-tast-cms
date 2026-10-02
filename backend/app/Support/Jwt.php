<?php

declare(strict_types=1);

namespace App\Support;

/** HS256 JWT，零依赖实现 */
final class Jwt
{
    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }

    public static function sign(array $claims, int $ttl): string
    {
        $now = time();
        $claims = array_merge(['iat' => $now, 'exp' => $now + $ttl], $claims);
        $head = self::b64((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64((string)json_encode($claims, JSON_UNESCAPED_UNICODE));
        $sig = self::b64(hash_hmac('sha256', "$head.$body", (string)Config::get('app.jwt_secret'), true));
        return "$head.$body.$sig";
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$head, $body, $sig] = $parts;
        $expect = self::b64(hash_hmac('sha256', "$head.$body", (string)Config::get('app.jwt_secret'), true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $claims = json_decode(self::unb64($body), true);
        if (!is_array($claims) || (int)($claims['exp'] ?? 0) < time()) {
            return null;
        }
        return $claims;
    }
}

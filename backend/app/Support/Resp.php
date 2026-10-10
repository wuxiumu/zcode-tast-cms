<?php

declare(strict_types=1);

namespace App\Support;

final class Resp
{
    public static function ok(mixed $data = null, string $msg = 'ok'): never
    {
        self::emit(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    public static function error(int $code, string $msg, mixed $data = null): never
    {
        self::emit(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    private static function emit(array $payload): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

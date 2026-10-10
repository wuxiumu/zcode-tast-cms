<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// 低配服务器兼容守卫：代码使用了 8.1 语法（never 返回类型等），版本不足时给出明确提示
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('当前 PHP ' . PHP_VERSION . '，本系统需要 PHP >= 8.1（并启用 redis 扩展）');
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

App\Support\Env::load(BASE_PATH . '/.env');

App\Support\Config::init([
    'app'   => require BASE_PATH . '/config/app.php',
    'redis' => require BASE_PATH . '/config/redis.php',
    'admin' => require BASE_PATH . '/config/admin.php',
]);

date_default_timezone_set('Asia/Shanghai');

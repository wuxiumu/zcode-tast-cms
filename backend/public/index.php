<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Support\Http;
use App\Support\Jwt;
use App\Support\Resp;
use App\Support\TaskException;

$method = $_SERVER['REQUEST_METHOD'];
$uri = (string)(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// 开发态：入口同时托管上传静态文件（生产由 Nginx 直出，见 doc/02 §5）
if (str_starts_with($uri, '/uploads/')) {
    $file = realpath(BASE_PATH . $uri);
    $root = realpath(BASE_PATH . '/uploads');
    if ($file && $root && str_starts_with($file, $root) && is_file($file)) {
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
        exit;
    }
    Resp::error(404, '文件不存在');
}

// 跨域（CORS）由 Nginx 层处理（宝塔面板配置，见 deploy/nginx.conf），PHP 不再输出跨域头

if ($uri === '/api/v1/ping') {
    Resp::ok(['time' => date('Y-m-d H:i:s')]);
}

/**
 * 路由表：[method, pattern, auth, class, action]
 * auth: public | user | admin；pattern 中 {x} 为路径参数
 */
$routes = [
    // ---- 用户端 ----
    ['POST', '/api/v1/auth/login', 'public', 'App\Controller\User\AuthController', 'login'],
    ['GET',  '/api/v1/me', 'user', 'App\Controller\User\AuthController', 'me'],
    ['GET',  '/api/v1/categories', 'user', 'App\Controller\User\CategoryController', 'tree'],
    ['GET',  '/api/v1/categories/{id}/tags', 'user', 'App\Controller\User\CategoryController', 'tags'],
    ['GET',  '/api/v1/materials', 'user', 'App\Controller\User\MaterialController', 'list'],
    ['GET',  '/api/v1/materials/{id}', 'user', 'App\Controller\User\MaterialController', 'detail'],
    ['POST', '/api/v1/tasks/claim', 'user', 'App\Controller\User\TaskController', 'claim'],
    ['GET',  '/api/v1/tasks', 'user', 'App\Controller\User\TaskController', 'myList'],
    ['GET',  '/api/v1/tasks/{id}', 'user', 'App\Controller\User\TaskController', 'detail'],
    ['POST', '/api/v1/tasks/{id}/submit', 'user', 'App\Controller\User\TaskController', 'submitFirst'],
    ['POST', '/api/v1/tasks/{id}/stats-submit', 'user', 'App\Controller\User\TaskController', 'submitStats'],
    ['GET',  '/api/v1/rewards', 'user', 'App\Controller\User\RewardController', 'list'],
    ['POST', '/api/v1/uploads', 'user', 'App\Controller\Common\UploadController', 'save'],

    // ---- 管理端 ----
    ['POST', '/api/v1/admin/auth/login', 'public', 'App\Controller\Admin\AuthController', 'login'],
    ['GET',  '/api/v1/admin/categories', 'admin', 'App\Controller\Admin\TaxonomyController', 'categories'],
    ['POST', '/api/v1/admin/categories', 'admin', 'App\Controller\Admin\TaxonomyController', 'saveCategory'],
    ['PUT',  '/api/v1/admin/categories/{id}', 'admin', 'App\Controller\Admin\TaxonomyController', 'saveCategory'],
    ['DELETE', '/api/v1/admin/categories/{id}', 'admin', 'App\Controller\Admin\TaxonomyController', 'deleteCategory'],
    ['GET',  '/api/v1/admin/tags', 'admin', 'App\Controller\Admin\TaxonomyController', 'tags'],
    ['POST', '/api/v1/admin/tags', 'admin', 'App\Controller\Admin\TaxonomyController', 'saveTag'],
    ['PUT',  '/api/v1/admin/tags/{key}', 'admin', 'App\Controller\Admin\TaxonomyController', 'saveTag'],
    ['DELETE', '/api/v1/admin/tags/{key}', 'admin', 'App\Controller\Admin\TaxonomyController', 'deleteTag'],
    ['GET',  '/api/v1/admin/materials', 'admin', 'App\Controller\Admin\MaterialController', 'list'],
    ['GET',  '/api/v1/admin/materials/{id}', 'admin', 'App\Controller\Admin\MaterialController', 'detail'],
    ['POST', '/api/v1/admin/materials', 'admin', 'App\Controller\Admin\MaterialController', 'create'],
    ['PUT',  '/api/v1/admin/materials/{id}', 'admin', 'App\Controller\Admin\MaterialController', 'update'],
    ['POST', '/api/v1/admin/materials/{id}/publish', 'admin', 'App\Controller\Admin\MaterialController', 'publish'],
    ['POST', '/api/v1/admin/materials/{id}/offline', 'admin', 'App\Controller\Admin\MaterialController', 'offline'],
    ['DELETE', '/api/v1/admin/materials/{id}', 'admin', 'App\Controller\Admin\MaterialController', 'delete'],
    ['GET',  '/api/v1/admin/materials/{id}/stats', 'admin', 'App\Controller\Admin\MaterialController', 'stats'],
    ['GET',  '/api/v1/admin/submissions', 'admin', 'App\Controller\Admin\ReviewController', 'list'],
    ['POST', '/api/v1/admin/submissions/{id}/review', 'admin', 'App\Controller\Admin\ReviewController', 'review'],
    ['GET',  '/api/v1/admin/rewards', 'admin', 'App\Controller\Admin\RewardController', 'list'],
    ['POST', '/api/v1/admin/rewards/{rid}/pay', 'admin', 'App\Controller\Admin\RewardController', 'pay'],
    ['GET',  '/api/v1/admin/stats/overview', 'admin', 'App\Controller\Admin\RewardController', 'overview'],
    ['POST', '/api/v1/admin/uploads', 'admin', 'App\Controller\Common\UploadController', 'save'],
];

foreach ($routes as [$m, $pattern, $auth, $class, $action]) {
    if ($m !== $method) {
        continue;
    }
    $params = matchPath($pattern, $uri);
    if ($params === null) {
        continue;
    }
    $ctx = [];
    if ($auth !== 'public') {
        $claims = Jwt::verify((string)(Http::bearer() ?? ''));
        if (!$claims || ($claims['aud'] ?? '') !== $auth) {
            Resp::error(1002, '未登录或登录已过期');
        }
        if ($auth === 'user') {
            $user = \App\Store\RedisStore::client()->hGetAll(\App\Store\RedisStore::k('user:' . $claims['uid']));
            if (!$user) {
                Resp::error(1002, '用户不存在');
            }
            if (($user['status'] ?? '') === 'banned') {
                Resp::error(1003, '账号已被封禁');
            }
            $ctx = ['uid' => (string)$claims['uid'], 'user' => $user];
        } else {
            $ctx = ['admin' => (string)($claims['sub'] ?? '')];
        }
    }
    $req = [
        'input'  => Http::jsonInput(),
        'query'  => $_GET,
        'params' => $params,
        'files'  => $_FILES,
        'ctx'    => $ctx,
    ];
    try {
        $class::$action($req);
    } catch (TaskException $e) {
        Resp::error($e->getCode(), $e->getMessage(), $e->getData());
    } catch (\RedisException $e) {
        Resp::error(5000, '缓存服务异常，请稍后再试');
    } catch (\Throwable $e) {
        if (App\Support\Config::get('app.debug')) {
            Resp::error(5000, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        Resp::error(5000, '服务器开小差了，请稍后再试');
    }
    return;
}

Resp::error(404, '接口不存在');

/** 模式匹配：'/api/v1/tasks/{id}' vs 实际路径，返回参数或 null */
function matchPath(string $pattern, string $path): ?array
{
    $ps = explode('/', trim($pattern, '/'));
    $us = explode('/', trim($path, '/'));
    if (count($ps) !== count($us)) {
        return null;
    }
    $params = [];
    foreach ($ps as $i => $seg) {
        if (preg_match('/^\{(\w+)\}$/', $seg, $m)) {
            $params[$m[1]] = urldecode($us[$i]);
        } elseif ($seg !== $us[$i]) {
            return null;
        }
    }
    return $params;
}

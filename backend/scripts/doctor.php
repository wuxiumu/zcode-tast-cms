<?php

/**
 * 素材库 · 接口体检脚本（本地/服务器通用，零扩展依赖：curl 可用则用，否则走 streams）
 *
 * 用法:
 *   php backend/scripts/doctor.php                                   # 检查本地开发服务 127.0.0.1:8090
 *   php backend/scripts/doctor.php https://sucaiku-api.chiguashentan.com
 *   php backend/scripts/doctor.php <URL> --full                      # 加测：上传闭环 + 内容库写入
 *   php backend/scripts/doctor.php <URL> --admin-user=admin --admin-pass=xxx --origin=https://front.example.com
 *
 * 退出码: 0 全部通过；1 存在失败项（可用于 CI/监控）
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

// ---------------- 参数 ----------------
$target = 'http://127.0.0.1:8090';
$full = false;
$adminUser = 'admin';
$adminPass = 'admin123';
$origin = '';
$timeout = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('#^https?://#', $arg)) {
        $target = rtrim($arg, '/');
    } elseif ($arg === '--full') {
        $full = true;
    } elseif (str_starts_with($arg, '--admin-user=')) {
        $adminUser = substr($arg, 13);
    } elseif (str_starts_with($arg, '--admin-pass=')) {
        $adminPass = substr($arg, 13);
    } elseif (str_starts_with($arg, '--origin=')) {
        $origin = substr($arg, 9);
    } elseif (str_starts_with($arg, '--timeout=')) {
        $timeout = (int)substr($arg, 10);
    } elseif ($arg === '-h' || $arg === '--help') {
        echo file_get_contents(__FILE__) ? "见文件头注释\n" : '';
        exit(0);
    }
}

$color = (function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true) && getenv('NO_COLOR') === false;
$host = (string)(parse_url($target, PHP_URL_HOST) ?: '');
$scheme = (string)(parse_url($target, PHP_URL_SCHEME) ?: 'http');
$origin = $origin !== '' ? $origin : ($scheme === 'https' ? 'https://' . str_replace('api.', '', $host) : 'http://127.0.0.1:8092');

// ---------------- 输出助手 ----------------
function out(string $s): void { echo $s . PHP_EOL; }
function c(string $s, string $code): string { global $color; return $color ? "\033[{$code}m{$s}\033[0m" : $s; }
function ok(string $s): string { return c('✓', '32') . ' ' . $s; }
function bad(string $s): string { return c('✗', '31') . ' ' . $s; }
function warn(string $s): string { return c('!', '33') . ' ' . $s; }

$GLOBALS['fails'] = 0;
$GLOBALS['warns'] = 0;

function report(string $layer, string $name, bool $pass, string $detail, ?string $hint = null, bool $isWarn = false): void
{
    $icon = $pass ? ok($detail) : ($isWarn ? warn($detail) : bad($detail));
    printf("%-6s %-22s %s\n", c("[{$layer}]", '90'), $name, $icon);
    if (!$pass && $hint !== null) {
        out('        ' . c('↳ ' . $hint, '90'));
    }
    if (!$pass) {
        if ($isWarn) { $GLOBALS['warns']++; } else { $GLOBALS['fails']++; }
    }
}

// ---------------- HTTP（curl 优先，streams 兜底）----------------
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    global $timeout;
    $t0 = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hs = $headers;
        $hs[] = 'Expect:';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => $timeout,
            CURLOPT_HTTPHEADER      => $hs,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $time = (microtime(true) - $t0) * 1000;
        if ($raw === false) {
            $res = ['http' => 0, 'headers' => [], 'body' => '', 'err' => curl_error($ch), 'errno' => curl_errno($ch), 'time' => $time, 'ip' => ''];
            curl_close($ch);
            return $res;
        }
        $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ip = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $headRaw = substr((string)$raw, 0, $size);
        curl_close($ch);
        $headers = [];
        foreach (explode("\r\n", $headRaw) as $h) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) {
                $headers[strtolower(trim($p[0]))] = trim($p[1]);
            }
        }
        return ['http' => $code, 'headers' => $headers, 'body' => substr((string)$raw, $size), 'err' => '', 'errno' => 0, 'time' => $time, 'ip' => $ip];
    }

    // streams 兜底（服务器无 curl 扩展时）
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => $timeout,
        'ignore_errors' => true, 'follow_location' => 0, 'content' => $body ?? '',
    ]]);
    $t0 = microtime(true);
    $resp = @file_get_contents($url, false, $ctx);
    $time = (microtime(true) - $t0) * 1000;
    if ($resp === false) {
        $msg = (string)(error_get_last()['message'] ?? '连接失败');
        return ['http' => 0, 'headers' => [], 'body' => '', 'err' => preg_replace('/^[^:]+:\s*/', '', $msg), 'errno' => -1, 'time' => $time, 'ip' => ''];
    }
    $code = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $code = (int)$m[1];
        } else {
            $p = explode(':', $h, 2);
            if (count($p) === 2) {
                $headers[strtolower(trim($p[0]))] = trim($p[1]);
            }
        }
    }
    return ['http' => $code, 'headers' => $headers, 'body' => (string)$resp, 'err' => '', 'errno' => 0, 'time' => $time, 'ip' => ''];
}

function json_body(array $res): array
{
    $d = json_decode($res['body'], true);
    return is_array($d) ? $d : [];
}

out(c('════════ 素材库接口体检 ════════', '1;36'));
out('目标: ' . $target . '   时间: ' . date('Y-m-d H:i:s') . '   模式: ' . ($full ? '完整(--full)' : '基础'));
out('');

// ---------------- [网络] DNS ----------------
$ip = gethostbyname($host);
$dnsOk = $ip !== $host || filter_var($host, FILTER_VALIDATE_IP);
report('网络', 'DNS 解析', $dnsOk, $dnsOk ? "→ {$ip}" : '解析失败', $dnsOk ? null : '域名未生效：检查 DNS 解析，或本机测试需在 /etc/hosts 加 "' . $ip . ' ' . $host . '"');

// ---------------- [网络] 连接 + [Web] 服务器 + [API] ping ----------------
$res = http('GET', $target . '/api/v1/ping');
$pingBody = json_body($res);
$server = $res['headers']['server'] ?? '';

if ($res['http'] === 0) {
    $hintMap = [
        7  => '连接被拒：Web 服务未启动 / 端口不对 / 宝塔安全组或防火墙未放行该端口',
        28 => '连接超时：防火墙 DROP 或服务假死',
        35 => 'SSL 握手失败：证书未配置或 TLS 版本问题',
        60 => 'SSL 证书校验失败：证书过期/自签（curl 可加 -k 绕过测试，生产应换有效证书）',
        6  => '域名解析失败',
    ];
    report('网络', 'HTTP 连接', false, "失败: {$res['err']}", $hintMap[$res['errno']] ?? '检查服务是否运行、端口是否可达');
} else {
    report('网络', 'HTTP 连接', true, "HTTP {$res['http']}（" . round($res['time']) . "ms）");
    report('Web', '服务器软件', $server !== '', $server !== '' ? $server : '未返回 Server 头');
    if ($res['http'] === 200 && ($pingBody['code'] ?? -1) === 0) {
        report('API', '/api/v1/ping', true, 'code=0，后端运行正常');
    } elseif ($res['http'] === 404 || str_contains($res['body'], '<html')) {
        report('API', '/api/v1/ping', false, "HTTP {$res['http']}，返回的是网页而非接口", 'Nginx 未把请求转发到 PHP：确认站点根目录指向 backend/public，且伪静态/反代把所有请求交到 index.php（见 deploy/nginx.conf）');
    } elseif (in_array($res['http'], [502, 503, 504], true)) {
        report('API', '/api/v1/ping', false, "HTTP {$res['http']}", 'php-fpm 未运行或 sock 路径不对：systemctl status php-fpm，核对 nginx fastcgi_pass');
    } elseif ($res['http'] === 500) {
        $detail = mb_substr(trim(strip_tags($res['body'])), 0, 120);
        report('API', '/api/v1/ping', false, "HTTP 500 {$detail}", 'PHP 致命错误：查 PHP error_log；常见原因：redis 扩展未装（php -m | grep redis）或 PHP 版本 < 8.1');
    } else {
        $detail = ($pingBody['msg'] ?? '') !== '' ? ('code=' . $pingBody['code'] . ' ' . $pingBody['msg']) : mb_substr($res['body'], 0, 80);
        report('API', '/api/v1/ping', false, "HTTP {$res['http']} {$detail}", '非预期响应');
    }

    // ---------------- [API] 路由与鉴权中间件 ----------------
    $res2 = http('GET', $target . '/api/v1/me');
    $b2 = json_body($res2);
    if (($b2['code'] ?? -1) === 1002) {
        report('API', '鉴权中间件', true, '未登录返回 1002（路由/响应格式正常）');
    } elseif ($res2['http'] === 0) {
        report('API', '鉴权中间件', false, '请求失败: ' . $res2['err']);
    } else {
        report('API', '鉴权中间件', false, "预期 code=1002，实际 HTTP {$res2['http']} code=" . ($b2['code'] ?? '?'), '路由或 JWT 中间件异常');
    }

    // ---------------- [CORS] 跨域（严格模拟浏览器预检）----------------
    $res3 = http('OPTIONS', $target . '/api/v1/auth/login', ['Origin: ' . $origin, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: authorization,content-type']);
    $acao = $res3['headers']['access-control-allow-origin'] ?? '';
    $acah = strtolower($res3['headers']['access-control-allow-headers'] ?? '');
    $acam = strtolower($res3['headers']['access-control-allow-methods'] ?? '');
    $acaoIsStar = trim($acao) === '*';
    $headersOk = str_contains($acah, 'authorization');
    $methodsOk = str_contains($acam, 'post');
    if ($acao === '') {
        report('CORS', '预检 + 跨域头', false, "HTTP {$res3['http']}，未返回任何跨域头", '宝塔站点配置缺少跨域片段（deploy/nginx.conf location / 内 4 行 add_header）；若前端与 API 同域部署可忽略此项', true);
    } elseif (!$headersOk || !$methodsOk || $res3['http'] !== 204) {
        $missing = [];
        if (!$headersOk) { $missing[] = '缺 Access-Control-Allow-Headers: Authorization（没有它前端上传/所有带登录态的请求都会被浏览器拦截）'; }
        if (!$methodsOk) { $missing[] = "缺 Access-Control-Allow-Methods POST（当前: {$acam}）"; }
        if ($res3['http'] !== 204) { $missing[] = "预检应返回 204，实际 {$res3['http']}"; }
        report('CORS', '预检 + 跨域头', false, implode('；', $missing), '完整片段见 deploy/nginx.conf location / 内的 4 行 add_header + OPTIONS 返回 204');
    } elseif ($acaoIsStar) {
        report('CORS', '预检 + 跨域头', false, 'ACAO: *（Bearer 无 cookie 时规范允许，浏览器可用）', '生产建议把 * 改为指定前端域名，防止任意网站调用接口', true);
    } else {
        report('CORS', '预检 + 跨域头', true, "ACAO: {$acao}，允许 Authorization");
    }

    // ---------------- [业务] 管理员登录 + Redis/存储读取 ----------------
    $res4 = http('POST', $target . '/api/v1/admin/auth/login', ['Content-Type: application/json'], json_encode(['username' => $adminUser, 'password' => $adminPass], JSON_UNESCAPED_UNICODE));
    $b4 = json_body($res4);
    if (($b4['code'] ?? -1) === 0 && !empty($b4['data']['token'])) {
        report('业务', '管理员登录', true, "用户 {$adminUser} 认证通过");
        $token = (string)$b4['data']['token'];
        $res5 = http('GET', $target . '/api/v1/admin/stats/overview', ['Authorization: Bearer ' . $token]);
        $b5 = json_body($res5);
        if (($b5['code'] ?? -1) === 0) {
            report('业务', 'Redis + 内容库读取', true, "用户 {$b5['data']['users_total']} · 在架素材 {$b5['data']['materials_published']} · 待审 {$b5['data']['pending_reviews']} · 累计奖励 ¥{$b5['data']['reward_total']}");
        } else {
            report('业务', 'Redis + 内容库读取', false, 'code=' . ($b5['code'] ?? '?') . ' ' . ($b5['msg'] ?? ''), 'Redis 未启动/扩展缺失，或 storage 目录不可读');
        }
    } elseif (($b4['code'] ?? -1) === 1001) {
        report('业务', '管理员登录', false, '账号或密码错误（admin/' . $adminUser . '）', '登录本身通了；若是你改过密码，请加 --admin-user=xxx --admin-pass=xxx 重跑');
    } elseif ($res4['http'] === 404) {
        report('业务', '管理员登录', false, '接口 404', 'Nginx 未转发 /api/v1/admin/* 路由');
    } else {
        report('业务', '管理员登录', false, 'HTTP ' . $res4['http'] . ' ' . ($b4['msg'] ?? $res4['body'] !== '' ? mb_substr($res4['body'], 0, 60) : ''), '异常响应');
    }

    // ---------------- [--full] 上传闭环 + 内容库写入 ----------------
    if ($full) {
        if (isset($token)) {
            // 1x1 PNG
            $png = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            $b = '----doctor' . md5(uniqid('', true));
            $mp = "--{$b}\r\nContent-Disposition: form-data; name=\"scene\"\r\n\r\nimage\r\n"
                . "--{$b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"doctor.png\"\r\nContent-Type: image/png\r\n\r\n{$png}\r\n--{$b}--";
            $res6 = http('POST', $target . '/api/v1/admin/uploads', ['Authorization: Bearer ' . $token, 'Content-Type: multipart/form-data; boundary=' . $b], $mp);
            $b6 = json_body($res6);
            $assetUrl = (string)($b6['data']['url'] ?? '');
            if (($b6['code'] ?? -1) === 0 && $assetUrl !== '') {
                // 后端未配 ASSET_BASE_URL 时返回相对路径，拼上目标域名再测可达性
                $assetAbs = str_starts_with($assetUrl, '/') ? $target . $assetUrl : $assetUrl;
                $get = http('GET', $assetAbs);
                $ctype = $get['headers']['content-type'] ?? '';
                if ($get['http'] === 200 && str_starts_with($ctype, 'image/')) {
                    $relNote = str_starts_with($assetUrl, '/') ? '（注意：返回的是相对路径，跨域前端页面将无法展示图片——服务器 backend/.env 需配置 ASSET_BASE_URL）' : '';
                    report('业务', '上传闭环', $relNote === '', "上传→{$assetAbs}→" . ($relNote === '' ? "资源可访问（{$ctype}）" : '资源可访问') . $relNote, $relNote === '' ? null : '在服务器 backend/.env 增加 ASSET_BASE_URL=https://当前API域名 后无需重启（php 每请求加载）', $relNote !== '');
                } else {
                    $hint = 'nginx 缺 /uploads/ 直出或被宝塔默认静态正则块抢占：改用 location ^~ /uploads/ { alias 站点根/uploads/; }（^~ 可压过正则块），改完 nginx -t && nginx -s reload';
                    if ($get['http'] === 404 && str_starts_with($ctype, 'image/')) {
                        $hint = '文件内容能读出但状态码 404：多为伪静态/静态正则块干扰，改用 location ^~ /uploads/ 并检查站点 error_page 配置';
                    }
                    report('业务', '上传闭环', false, "上传成功但资源不可访问: {$assetAbs} HTTP {$get['http']} ({$ctype})", $hint);
                }
            } else {
                $hint = str_contains((string)($b6['msg'] ?? ''), 'fileinfo') || str_contains((string)($b6['msg'] ?? ''), '未知')
                    ? '服务器缺 fileinfo 应不受影响（已魔数降级）；此错误多为 uploads 目录不可写（chown www:www uploads）'
                    : 'uploads 目录不可写（chown 运行用户）或 nginx client_max_body_size 过小';
                report('业务', '上传闭环', false, 'code=' . ($b6['code'] ?? '?') . ' ' . ($b6['msg'] ?? '无响应'), $hint);
            }

            $tk = 'doctor_' . substr(md5(uniqid('', true)), 0, 8);
            $res7 = http('POST', $target . '/api/v1/admin/tags', ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], json_encode(['key' => $tk, 'name' => '体检临时标签'], JSON_UNESCAPED_UNICODE));
            $b7 = json_body($res7);
            if (($b7['code'] ?? -1) === 0) {
                $res8 = http('DELETE', $target . '/api/v1/admin/tags/' . $tk, ['Authorization: Bearer ' . $token]);
                $b8 = json_body($res8);
                if (($b8['code'] ?? -1) === 0) {
                    report('业务', '内容库写入', true, '标签创建/删除成功（storage 目录可写）');
                } else {
                    report('业务', '内容库写入', false, '创建成功但删除失败: ' . ($b8['msg'] ?? ''), 'storage 目录写权限不稳定，检查目录属主（chown -R 运行用户 storage）');
                }
            } else {
                $snippet = mb_substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($res7['body']))), 0, 120);
                report('业务', '内容库写入', false, "HTTP {$res7['http']} code=" . ($b7['code'] ?? '?') . ' ' . ($b7['msg'] ?? $snippet), 'storage 目录不可写：chown -R www:www 站点根/storage；响应片段: ' . ($snippet !== '' ? $snippet : '空'));
            }
        }
    }
}

// ---------------- 汇总 ----------------
out('');
$total = $GLOBALS['fails'] + $GLOBALS['warns'];
if ($GLOBALS['fails'] === 0) {
    out(c('整体状态: ' . ($total === 0 ? '✅ 全部正常' : "⚠️ 可用（{$GLOBALS['warns']} 项建议）"), '32;1'));
} else {
    out(c("整体状态: ❌ 存在 {$GLOBALS['fails']} 项异常、{$GLOBALS['warns']} 项建议（按上方 ↳ 提示逐项修复）", '31;1'));
}
exit($GLOBALS['fails'] === 0 ? 0 : 1);

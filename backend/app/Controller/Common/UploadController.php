<?php

declare(strict_types=1);

namespace App\Controller\Common;

use App\Service\UploadService;
use App\Support\Resp;
use App\Support\TaskException;

final class UploadController
{
    /** 用户端与管理端共用：multipart 字段 file，query/表单字段 scene=image|screenshot|video */
    public static function save(array $req): void
    {
        $scene = (string)($_POST['scene'] ?? ($req['query']['scene'] ?? 'image'));
        if (!isset($_FILES['file'])) {
            throw new TaskException(1001, '缺少 file 字段');
        }
        $url = UploadService::save($_FILES['file'], $scene === 'video' ? 'video' : 'image');
        Resp::ok(['url' => \App\Support\Url::asset($url)]);
    }
}

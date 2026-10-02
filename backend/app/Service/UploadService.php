<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Config;
use App\Support\TaskException;

final class UploadService
{
    public static function save(array $file, string $scene): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new TaskException(1001, '上传失败，请重试');
        }
        $cfg = Config::get('app.upload');
        $isVideo = $scene === 'video';
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowExt = $isVideo ? $cfg['video_ext'] : $cfg['image_ext'];
        $allowMime = $isVideo ? $cfg['video_mime'] : $cfg['image_mime'];
        $max = (int)($isVideo ? $cfg['video_max'] : $cfg['image_max']);
        if (!in_array($ext, $allowExt, true)) {
            throw new TaskException(1001, '不支持的文件类型：' . $ext);
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $max) {
            throw new TaskException(1001, $isVideo ? '视频不能超过 200MB' : '图片不能超过 10MB');
        }
        $mime = self::detectMime((string)$file['tmp_name']);
        if ($mime === '' || !in_array($mime, $allowMime, true)) {
            throw new TaskException(1001, '文件内容校验未通过（' . ($mime !== '' ? $mime : '未知类型') . '）');
        }
        $dir = BASE_PATH . '/uploads/' . date('Y/m/d');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new TaskException(5000, '保存文件失败');
        }
        return '/uploads/' . date('Y/m/d') . "/$name";
    }

    /**
     * 真实 MIME 探测（低配服务器兼容）：
     * fileinfo 扩展 → mime_content_type() → 内置魔数嗅探，三级自动降级，缺失任一扩展不影响功能。
     */
    public static function detectMime(string $path): string
    {
        if (class_exists('\finfo')) {
            $mime = @((new \finfo(FILEINFO_MIME_TYPE))->file($path));
            if (is_string($mime) && str_contains($mime, '/')) {
                return $mime;
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && str_contains($mime, '/') && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }
        return self::sniffMime($path);
    }

    /** 魔数嗅探：覆盖本项目允许的全部类型（jpg/png/gif/webp/mp4/mov），零扩展依赖 */
    public static function sniffMime(string $path): string
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return '';
        }
        $head = (string)fread($fp, 16);
        fclose($fp);
        if ($head === '') {
            return '';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (bin2hex(substr($head, 0, 8)) === '89504e470d0a1a0a') {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        // mp4/mov：偏移 4~7 为 'ftyp'，8~11 为品牌（qt 系为 mov，其余视为 mp4）
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return str_starts_with(substr($head, 8, 2), 'qt') ? 'video/quicktime' : 'video/mp4';
        }
        return '';
    }
}

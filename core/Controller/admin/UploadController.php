<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Upload;

/**
 * 后台上传接口（EasyMDE / Vditor 编辑器集成）。
 * 对照原项目 admin_upload_image / admin_upload_media / admin_upload_file。
 */
class UploadController extends BaseController
{
    /** POST /admin/upload/image：图片上传（字段名 image） */
    public function image(): void
    {
        Auth::requireAdmin();
        $file = $_FILES['image'] ?? null;
        [$url, $err] = Upload::save(is_array($file) ? $file : [], Upload::imageExt());
        if ($err !== '') {
            Response::json(['error' => $err], 400);
        }
        Response::json(['url' => $url]);
    }

    /** POST /admin/upload/media：视频/音频上传（字段名 file 或 media） */
    public function media(): void
    {
        Auth::requireAdmin();
        $file = $_FILES['file'] ?? $_FILES['media'] ?? null;
        [$url, $err] = Upload::save(is_array($file) ? $file : [], Upload::mediaExt());
        if ($err !== '') {
            Response::json(['error' => $err], 400);
        }
        Response::json(['url' => $url, 'name' => basename($url)]);
    }

    /** POST /admin/upload/file：附件上传（字段名 file） */
    public function file(): void
    {
        Auth::requireAdmin();
        $file = $_FILES['file'] ?? null;
        [$url, $err] = Upload::save(is_array($file) ? $file : [], Upload::fileExt());
        if ($err !== '') {
            Response::json(['error' => $err], 400);
        }
        Response::json(['url' => $url, 'name' => basename($url)]);
    }
}
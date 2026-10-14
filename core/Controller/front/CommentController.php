<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Request;
use Blog\Response;
use Blog\Service\CommentImage;

/**
 * 前台评论图片上传（POST /comment/upload）。
 *
 * 与后台 UploadController 分开：此处面向访客，不做登录校验（评论本身允许匿名），
 * 因此改走 CommentImage 的更严格防线（收窄类型 / 更小上限 / IP 频率与每日配额）。
 * 跨站伪造由根 App 的同源校验（Origin/Referer）统一覆盖，无需在此重复。
 */
class CommentController extends BaseController
{
    /** POST /comment/upload：字段名 image，成功返回 {url}，失败返回 {error} */
    public function upload(): void
    {
        if (!CommentImage::enabled()) {
            Response::json(['error' => '评论图片上传当前不可用'], 403);
        }
        $file = $_FILES['image'] ?? null;
        [$url, $err] = CommentImage::save(is_array($file) ? $file : [], Request::ip());
        if ($err !== '') {
            Response::json(['error' => $err], 400);
        }
        Response::json(['url' => $url]);
    }
}

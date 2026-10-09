<?php
declare(strict_types=1);

namespace Blog\Controller\front;

use Blog\Controller\BaseController;
use Blog\Response;
use Blog\Service\Context;
use Blog\Service\Status;

/**
 * 前台服务状态控制器（对照原项目 status_page / api_status）。
 */
class StatusController extends BaseController
{
    /** /status 页面（SSR 首批数据 + JS 轮询 /api/status） */
    public function page(): void
    {
        if (!Context::navEnabled('status')) {
            $this->abort404();
        }
        $this->render('status.html', [
            'expiry' => Status::getExpiryInfo(),
            'services' => Status::getServicesStatus(),
        ]);
    }

    /** /api/status 公开 JSON */
    public function api(): void
    {
        Response::json([
            'expiry' => Status::getExpiryInfo(),
            'services' => Status::getServicesStatus(),
            'generated_at' => time(),
        ]);
    }
}

<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Settings;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;
use Blog\Service\Status;

/**
 * 后台「服务时效」：云资源到期配置 + HTTP(S) 服务监控列表。
 * 对照原项目 admin_status / admin_status_probe / admin_status_test_cloud。
 */
class StatusController extends BaseController
{
    /** GET /admin/status：渲染配置与监控面板 */
    public function index(): void
    {
        Auth::requireAdmin();
        $this->render('admin/status.html', [
            'expiry' => Status::getExpiryInfo(),
            'services' => Status::monitorServices(),
            'status_items' => Status::getServicesStatus(),
        ]);
    }

    /** POST /admin/status：保存云资源与监控服务配置 */
    public function save(): void
    {
        Auth::requireAdmin();

        // 文本配置：key => 缺省值（空值回落到默认）
        $texts = [
            'aliyun_access_key' => '',
            'aliyun_server_type' => 'swas',
            'aliyun_access_secret' => '',
            'aliyun_region' => 'cn-hangzhou',
            'aliyun_instance_id' => '',
            'tencent_secret_id' => '',
            'tencent_secret_key' => '',
            'tencent_domain' => '',
            'expiry_aliyun' => '',
            'expiry_tencent' => '',
        ];
        foreach ($texts as $key => $default) {
            $val = trim((string) Request::post($key, ''));
            Settings::set($key, $val !== '' ? $val : $default);
        }

        // 云 API 同步结果（仅当后台点过「同步」时才随表单带回，非空才持久化）
        foreach (['aliyun', 'tencent'] as $key) {
            $apiVal = trim((string) Request::post($key . '_expiry_api', ''));
            if ($apiVal !== '') {
                Settings::set($key . '_expiry_api', $apiVal);
                Settings::set($key . '_expiry_api_ts', (string) time());
            }
        }

        // 监控服务列表：svc_name[] / svc_url[] 同名数组按顺序配对
        $names = $_POST['svc_name'] ?? [];
        $urls = $_POST['svc_url'] ?? [];
        $services = [];
        if (is_array($names) && is_array($urls)) {
            $count = min(count($names), count($urls));
            for ($i = 0; $i < $count; $i++) {
                $n = trim((string) $names[$i]);
                $u = Status::cleanMonitorUrl((string) $urls[$i]);
                if ($n !== '' && $u !== '') {
                    $services[] = ['name' => $n, 'url' => $u];
                }
            }
        }
        Settings::set('monitor_services', (string) json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // 保存后立即探测一次，避免前台要等下一次 5 分钟轮询才有数据
        try {
            Status::probeAll(true);
        } catch (\Throwable) {
            // 探测失败不阻断保存
        }

        Flash::success('服务时效配置已保存');
        Response::redirect('/admin/status');
    }

    /** POST /admin/status/probe：立即重探全部服务，返回最新结果 JSON */
    public function probe(): void
    {
        Auth::requireAdmin();
        Status::probeAll(true);
        Response::json(['ok' => true, 'services' => Status::getServicesStatus()]);
    }

    /** POST /admin/status/test-cloud：实时查询云 API 到期时间（仅预览，不落库） */
    public function testCloud(): void
    {
        Auth::requireAdmin();
        Response::json(['ok' => true, 'expiry' => Status::getExpiryInfoForce()]);
    }
}
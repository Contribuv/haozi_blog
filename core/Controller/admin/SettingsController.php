<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Db;
use Blog\Model\Settings;
use Blog\Model\User;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Avatar;
use Blog\Service\Context;
use Blog\Service\Flash;
use Blog\Service\Mail;
use Blog\Service\Otp;
use Blog\Service\Upload;
use Blog\Service\Watermark;

/**
 * 后台「博客设置」：站点信息、水印、评论通知、导航开关、账号安全、OTP 双重验证。
 * 对照原项目 admin_settings / admin_settings_test_email。
 */
class SettingsController extends BaseController
{
    /** 普通文本配置键（表单存在才保存，复选框类开关单独处理） */
    private const TEXT_KEYS = [
        'blog_name', 'blog_subtitle', 'author', 'author_bio',
        'about_intro', 'skills', 'avatar', 'github_username',
        'social_github', 'github_token', 'contact_email', 'home_title',
        'icp_beian', 'police_beian', 'home_posts_count', 'posts_per_page',
        'smtp_host', 'smtp_sender_name', 'smtp_port', 'smtp_user',
        'smtp_pass', 'notify_email', 'footer_copyright_year',
        'footer_copyright_owner', 'footer_powered_by', 'stats_code',
    ];

    /** GET /admin/settings：渲染设置页 */
    public function index(): void
    {
        Auth::requireAdmin();
        // 恢复码仅在上次启用/重绑后的首次 GET 展示
        $codes = $_SESSION['_otp_new_codes'] ?? null;
        unset($_SESSION['_otp_new_codes']);

        $this->render('admin/settings.html', [
            'admin_username' => Auth::username() !== '' ? Auth::username() : 'admin',
            'admin_avatar' => (string) Settings::get('avatar', ''),
            'avatar_exists' => Avatar::configuredAvatarExists(),
            'smtp_host' => (string) Settings::get('smtp_host', ''),
            'smtp_port' => (string) Settings::get('smtp_port', '465'),
            'smtp_user' => (string) Settings::get('smtp_user', ''),
            'smtp_pass' => (string) Settings::get('smtp_pass', ''),
            'notify_email' => (string) Settings::get('notify_email', ''),
            'smtp_sender_name' => (string) Settings::get('smtp_sender_name', ''),
            'comment_notify' => (string) Settings::get('comment_notify', '0'),
            'watermark_enabled' => (string) Settings::get('watermark_enabled', '1'),
            'watermark_text' => (string) Settings::get('watermark_text', ''),
            'watermark_position' => (string) Settings::get('watermark_position', 'br'),
            'watermark_size' => (string) Settings::get('watermark_size', 'm'),
            // 历史图刷新状态（后台队列，由本页轮询推进）
            'wm_refresh' => Watermark::state(),
            'otp_enabled' => Otp::configured(),
            'otp_secret_set' => Otp::secret() !== '',
            'otp_emergency' => Otp::emergencyOff(),
            'otp_recovery_left' => Otp::recoveryRemaining(),
            'otp_new_codes' => is_array($codes) ? $codes : null,
            'otp_mail_ok' => trim((string) Settings::get('smtp_host', '')) !== '',
        ]);
    }

    /** POST /admin/settings：保存全部设置 */
    public function save(): void
    {
        Auth::requireAdmin();

        // ── 普通文本配置（含统计代码消毒） ──
        foreach (self::TEXT_KEYS as $key) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = (string) $_POST[$key];
            if ($key === 'stats_code') {
                $val = Context::sanitizeStatsCode($val);
            }
            Settings::set($key, $val);
        }

        // ── 图片水印（开关为复选框，未勾选即关闭） ──
        // 先记录旧配置，用于判断「开关/文本/位置/字号」是否变化（变化才触发历史图后台刷新）
        $wmOldOn = Watermark::enabled();
        $wmOldText = Watermark::text();
        $wmOldPos = Watermark::position();
        $wmOldSize = Watermark::size();
        Settings::set('watermark_enabled', !empty($_POST['watermark_enabled']) ? '1' : '0');
        foreach (['watermark_text', 'watermark_position', 'watermark_size'] as $key) {
            if (array_key_exists($key, $_POST)) {
                Settings::set($key, (string) $_POST[$key]);
            }
        }
        // 水印开关/文本/位置/字号任一变化 → 登记历史图后台刷新队列（由设置页轮询推进）
        $wmNewOn = Watermark::enabled();
        $wmNewText = Watermark::text();
        $wmNewPos = Watermark::position();
        $wmNewSize = Watermark::size();
        if ($wmNewOn && $wmNewText !== ''
            && ($wmNewOn !== $wmOldOn || $wmNewText !== $wmOldText
                || $wmNewPos !== $wmOldPos || $wmNewSize !== $wmOldSize)) {
            if (Watermark::start($wmNewText, $wmNewPos, $wmNewSize)) {
                Flash::success('水印配置已变化，历史图片正在后台自动刷新…');
            } else {
                Flash::success('水印配置已保存（上一轮刷新仍在进行，完成后即按新配置生效）');
            }
        }

        // ── 评论功能 / 评论邮件通知开关 ──
        Settings::set('comments_enabled', !empty($_POST['comments_enabled']) ? '1' : '0');
        Settings::set('comment_notify', !empty($_POST['comment_notify']) ? '1' : '0');

        // ── 表单内发送 SMTP 测试邮件（配置已在上方入库） ──
        if (!empty($_POST['test_email'])) {
            $tgt = trim((string) Request::post('notify_email', '')) ?: trim((string) Request::post('contact_email', ''));
            try {
                Mail::send($tgt, '[' . self::blogName() . '] SMTP 测试邮件', self::testMailHtml(
                    (string) Request::post('smtp_host', ''),
                    (string) Request::post('smtp_port', ''),
                    (string) Request::post('smtp_user', ''),
                    $tgt
                ));
                Flash::success('测试邮件已发送（' . $tgt . '），请查收收件箱/垃圾箱');
            } catch (\Throwable $e) {
                Flash::error('测试邮件发送失败：' . $e->getMessage());
            }
        }

        // ── 导航菜单开关 ──
        foreach (Context::NAV_PAGES as [$navKey, $label, $href]) {
            Settings::set('nav_' . $navKey, (string) Request::post('nav_' . $navKey, '') === '1' ? '1' : '0');
        }

        // ── 修改管理员账号名（非空、安全字符、长度 2-30） ──
        $newUsername = trim((string) Request::post('admin_username', ''));
        $curUsername = Auth::username();
        if ($newUsername !== '' && $newUsername !== $curUsername) {
            if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fff}-]{2,30}$/u', $newUsername)) {
                Flash::error('账号名格式不合法（仅支持中英文、数字、下划线、连字符，2-30 字符）');
            } elseif (User::findByUsername($newUsername) !== null) {
                Flash::error('账号名已存在，未修改');
            } else {
                $sole = User::sole();
                if ($sole !== null) {
                    Db::query('UPDATE users SET username = ? WHERE id = ?', [$newUsername, (int) $sole['id']]);
                    $_SESSION['admin_username'] = $newUsername;
                    Flash::success('管理员账号名已修改为：' . $newUsername);
                }
            }
        }

        // ── 修改密码（需验证旧密码） ──
        $newPwd = (string) Request::post('new_password', '');
        if ($newPwd !== '' && strlen($newPwd) >= 6) {
            $oldPwd = (string) Request::post('old_password', '');
            if ($oldPwd === '') {
                Flash::error('请先输入旧密码');
            } else {
                $sole = User::sole();
                if ($sole !== null && User::verifyHash((string) $sole['password_hash'], $oldPwd)) {
                    User::updatePassword((int) $sole['id'], $newPwd);
                    Auth::clearInitialPwdFile(); // 初始密码已完成使命，删除明文文件
                    Flash::success('密码已修改');
                } else {
                    Flash::error('旧密码错误，密码未修改');
                }
            }
        }

        // ── 头像上传（固定压缩为 144px 正方形 JPEG） ──
        $avatarFile = $_FILES['avatar_file'] ?? null;
        if (is_array($avatarFile) && ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (string) ($avatarFile['name'] ?? '') !== '') {
            self::saveAvatar($avatarFile);
        }

        // ── OTP 双重验证：开关 / 绑定 / 重绑 / 关闭 ──
        // 必须用布尔判断，不可用字符串 '0'（非空字符串为真，会导致无法关闭）
        $wantOtp = !empty($_POST['otp_enabled']);
        $curOtp = Otp::enabled();
        $newSecret = strtoupper(trim((string) Request::post('otp_secret', '')));
        $confirmCode = trim((string) Request::post('otp_confirm_code', ''));

        if ($wantOtp && !$curOtp) {
            // 启用：必须扫码并输入正确验证码才落库生效，同时生成一次性恢复码
            if ($newSecret !== '' && Otp::verify($newSecret, $confirmCode)) {
                [$codes, $hashes] = Otp::genRecoveryCodes();
                Settings::setMany([
                    'otp_enabled' => '1',
                    'otp_secret' => $newSecret,
                    'otp_recovery_codes' => (string) json_encode($hashes),
                ]);
                $_SESSION['_otp_new_codes'] = $codes; // 仅本次 GET 展示
                Flash::success('OTP 双重验证已启用，请立即保存恢复码');
            } else {
                Flash::error('OTP 启用失败：请先点击「生成二维码」扫码，再输入验证码确认绑定');
            }
        } elseif (!$wantOtp && Otp::configured()) {
            // 关闭：清空密钥与恢复码，下次启用走全新绑定
            Otp::reset();
            Flash::success('OTP 双重验证已关闭');
        } elseif ($wantOtp && $curOtp && !empty($_POST['otp_rotate']) && $newSecret !== '') {
            // 已启用状态下重新绑定（换新密钥 + 新恢复码）
            if (Otp::verify($newSecret, $confirmCode)) {
                [$codes, $hashes] = Otp::genRecoveryCodes();
                Settings::setMany([
                    'otp_secret' => $newSecret,
                    'otp_recovery_codes' => (string) json_encode($hashes),
                ]);
                $_SESSION['_otp_new_codes'] = $codes;
                Flash::success('OTP 已重新绑定，恢复码已更新，请保存新恢复码');
            } else {
                Flash::error('重新绑定失败：请用新二维码扫码并输入对应验证码');
            }
        }

        // 钩子位 setting.save：插件可在设置保存后同步自身配置
        \Blog\Hook::emit('setting.save', ['keys' => array_keys($_POST)]);
        Flash::success('设置已保存');
        Response::redirect('/admin/settings');
    }

    /** GET /admin/settings/wm-refresh：推进历史图水印刷新队列并返回状态（供设置页轮询） */
    public function wmRefresh(): void
    {
        Auth::requireAdmin();
        Response::json(Watermark::advance());
    }

    /** POST /admin/settings/test-email：以表单当前 SMTP 配置试发（不落库） */
    public function testEmail(): void
    {
        Auth::requireAdmin();
        $tgt = trim((string) Request::post('notify_email', '')) ?: trim((string) Request::post('contact_email', ''));
        $cfg = [
            'smtp_host' => trim((string) Request::post('smtp_host', '')),
            'smtp_port' => trim((string) Request::post('smtp_port', '')),
            'smtp_user' => trim((string) Request::post('smtp_user', '')),
            'smtp_pass' => (string) Request::post('smtp_pass', ''),
            'smtp_sender_name' => trim((string) Request::post('smtp_sender_name', '')),
        ];
        try {
            Mail::send(
                $tgt,
                '[' . self::blogName() . '] SMTP 测试邮件',
                self::testMailHtml($cfg['smtp_host'], $cfg['smtp_port'], $cfg['smtp_user'], $tgt),
                $cfg
            );
            Response::json(['ok' => true, 'msg' => '测试邮件已发送（' . $tgt . '），请查收收件箱/垃圾箱']);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'msg' => '发送失败：' . $e->getMessage()]);
        }
    }

    /** 保存头像：居中裁剪为正方形并压缩为 144px JPEG */
    private static function saveAvatar(array $file): void
    {
        $ext = '.' . strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, Upload::IMAGE_EXT, true)) {
            Flash::error('头像格式不支持（仅 png/jpg/jpeg/gif/webp/heic）');
            return;
        }
        if (in_array($ext, ['.heic', '.heif'], true)) {
            Flash::error('服务器缺少 HEIC 解码支持，无法处理 HEIC 头像');
            return;
        }

        $dir = Upload::root() . '/avatar';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            Flash::error('头像目录创建失败');
            return;
        }
        $savePath = $dir . '/avatar.jpg';

        $ok = false;
        if (function_exists('imagecreatefromstring')) {
            $data = @file_get_contents((string) $file['tmp_name']);
            $img = $data !== false ? @imagecreatefromstring($data) : false;
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $s = max(1, min($w, $h));
                $crop = imagecreatetruecolor($s, $s);
                imagecopy($crop, $img, 0, 0, (int) (($w - $s) / 2), (int) (($h - $s) / 2), $s, $s);
                $dst = imagecreatetruecolor(144, 144);
                imagecopyresampled($dst, $crop, 0, 0, 0, 0, 144, 144, $s, $s);
                $ok = imagejpeg($dst, $savePath, 85);
                imagedestroy($img);
                imagedestroy($crop);
                imagedestroy($dst);
            }
        }
        if (!$ok) {
            // GD 不可用或解码失败：保留原文件兜底
            $ok = move_uploaded_file((string) $file['tmp_name'], $savePath);
        }
        if (!$ok) {
            Flash::error('头像保存失败');
            return;
        }

        Settings::set('avatar', '/uploads/avatar/avatar.jpg');
        Flash::success('头像已更新');
    }

    /** 站点名（空则回退 Blog） */
    private static function blogName(): string
    {
        $n = trim((string) Settings::get('blog_name', ''));
        return $n !== '' ? $n : 'Blog';
    }

    /** SMTP 测试邮件 HTML 正文（对照原项目内联模板） */
    private static function testMailHtml(string $host, string $port, string $user, string $to): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . Mail::NOTIFY_CSS . '</style></head><body>'
            . '<div class="wrap"><div class="card">'
            . '<div class="head">SMTP 配置测试</div>'
            . '<div class="meta">服务器：' . $e($host) . ':' . $e($port) . '</div>'
            . '<div class="meta">发件账号：' . $e($user) . '</div>'
            . '<div class="meta">收件人：' . $e($to) . '</div>'
            . '<div class="foot">收到本邮件说明 SMTP 配置可用，评论互动通知可正常送达。</div>'
            . '</div></div></body></html>';
    }
}
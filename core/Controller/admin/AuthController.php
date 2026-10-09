<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Model\Settings;
use Blog\Model\User;
use Blog\Request;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Flash;
use Blog\Service\LoginGuard;
use Blog\Service\Mail;
use Blog\Service\Otp;
use Blog\Url;

/**
 * 后台认证：登录、退出、OTP 双重验证、OTP 找回、忘记密码、OTP 绑定与紧急标记清除。
 * 对照原项目 app.py 的 admin_index / admin_login / admin_logout / admin_otp / admin_otp_recover /
 * admin_forgot / admin_otp_setup / admin_otp_clear_emergency。
 */
class AuthController extends BaseController
{
    /** GET /admin：已登录进仪表盘，等 OTP 进验证页，否则进登录页 */
    public function index(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect('/admin/dashboard');
        }
        if (Auth::pendingOtpUser() !== '') {
            Response::redirect('/admin/otp');
        }
        Response::redirect('/admin/login');
    }

    /** ANY /admin/login：账号密码登录（含验证码与防爆破） */
    public function login(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect('/admin/dashboard');
        }
        if (Auth::pendingOtpUser() !== '') {
            Response::redirect('/admin/otp');
        }

        $ip = Request::ip();
        if (LoginGuard::blocked($ip)) {
            $this->render('admin/login.html', [
                'error' => '尝试次数过多，请 ' . (LoginGuard::LOCK_SECONDS / 60) . ' 分钟后再试',
            ]);
            return;
        }

        $captchaRequired = LoginGuard::captchaRequired($ip);
        // 仅在需要验证码、且 session 尚未持有题目时才生成（避免 POST 覆盖正确答案）
        if ($captchaRequired && !isset($_SESSION['captcha_answer'])) {
            LoginGuard::genCaptcha();
        }
        $captchaQuestion = $captchaRequired ? ($_SESSION['_captcha_q'] ?? null) : null;

        $error = null;
        if (Request::method() === 'POST') {
            if ($captchaRequired) {
                $userAns = $_POST['captcha'] ?? '';
                $userAns = is_string($userAns) && trim($userAns) !== '' ? (int) trim($userAns) : null;
                if ($userAns !== ($_SESSION['captcha_answer'] ?? null)) {
                    LoginGuard::registerFail($ip);
                    $this->render('admin/login.html', [
                        'error' => '验证码错误',
                        'captcha_required' => true,
                        'captcha_question' => LoginGuard::genCaptcha(),
                    ]);
                    return;
                }
            }

            $username = (string) ($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $user = User::findByUsername($username);
            if ($user !== null && User::verifyHash((string) $user['password_hash'], $password)) {
                // 懒迁移：旧格式（werkzeug pbkdf2）验证通过则升级为 PHP 原生哈希
                if (!User::isNativeHash((string) $user['password_hash'])) {
                    User::updatePassword((int) $user['id'], $password);
                }
                LoginGuard::registerSuccess($ip);
                unset($_SESSION['captcha_answer']);

                if (Otp::emergencyOff()) {
                    // 服务器上存在紧急禁用标记：临时跳过 OTP（登录后设置页会提示处理）
                    Auth::login($username);
                    Flash::error('检测到 OTP 紧急禁用标记（data/.otp_disable），本次登录已跳过 OTP 验证，请尽快到设置页处理');
                    Response::redirect('/admin/settings');
                }
                if (Otp::enabled()) {
                    // OTP 已开启：先进入第二因子验证页，验证通过才真正登录
                    Auth::setPendingOtpUser($username);
                    Response::redirect('/admin/otp');
                }
                Auth::login($username);
                Response::redirect('/admin/dashboard');
            }

            LoginGuard::registerFail($ip);
            $remaining = LoginGuard::remaining($ip);
            if (LoginGuard::captchaRequired($ip)) {
                $captchaQuestion = LoginGuard::genCaptcha();
                $captchaRequired = true;
            }
            if (LoginGuard::blocked($ip)) {
                $error = '尝试次数过多，请 ' . (LoginGuard::LOCK_SECONDS / 60) . ' 分钟后再试';
            } else {
                $error = '用户名或密码错误' . ($remaining > 0 ? '（还可尝试 ' . $remaining . ' 次）' : '');
            }
        }

        $this->render('admin/login.html', [
            'error' => $error,
            'captcha_required' => $captchaRequired,
            'captcha_question' => $captchaQuestion,
        ]);
    }

    /** GET /admin/logout：清会话回登录页 */
    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        Response::redirect('/admin/login');
    }

    /** ANY /admin/otp：OTP 第二因子验证页（6 位验证码或 8 位恢复码） */
    public function otp(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect('/admin/dashboard');
        }
        $username = Auth::pendingOtpUser();
        if ($username === '') {
            Response::redirect('/admin/login');
        }
        if (!Otp::enabled()) {
            // OTP 中途被关闭/重置（如邮箱找回）时直接放行，并清掉中间态
            Auth::login($username);
            Response::redirect('/admin/dashboard');
        }

        $error = null;
        if (Request::method() === 'POST') {
            $code = trim((string) ($_POST['code'] ?? ''));
            if ($code === '') {
                $error = '请输入验证码或恢复码';
            } elseif (Otp::emergencyOff()) {
                // 管理员已在服务器放了紧急标记：本次验证直接放行
                Auth::login($username);
                Flash::error('检测到 OTP 紧急禁用标记（data/.otp_disable），本次登录已跳过 OTP 验证，请尽快到设置页处理');
                Response::redirect('/admin/settings');
            } elseif (Otp::consumeRecovery($code) || Otp::verify(Otp::secret(), $code)) {
                Auth::login($username);
                Response::redirect('/admin/dashboard');
            } else {
                LoginGuard::registerFail(Request::ip()); // 复用登录防爆破：失败延迟 + 锁定
                $error = '验证码或恢复码错误';
            }
        }

        $this->render('admin/otp.html', [
            'error' => $error,
            'user' => $username,
            'recovery_left' => Otp::recoveryRemaining(),
            'mail_ok' => Mail::configured(),
        ]);
    }

    /** ANY /admin/otp/recover：OTP 丢失找回（邮件验证码 → 重置 OTP） */
    public function otpRecover(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect('/admin/dashboard');
        }
        $username = Auth::pendingOtpUser();
        if ($username === '') {
            Response::redirect('/admin/login');
        }

        $target = self::notifyTarget();
        if (!Mail::configured()) {
            $this->render('admin/otp_recover.html', [
                'user' => $username,
                'step' => 'unavailable',
                'target' => '',
                'sent' => false,
                'error' => '服务器未配置 SMTP，无法邮件找回。可用恢复码登录，'
                    . '或由服务器管理员执行 touch data/.otp_disable 临时跳过。',
            ]);
            return;
        }

        $error = null;
        $sent = false;
        $step = 'send';
        if (Request::method() === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'send') {
                if ($target === '') {
                    $error = '管理员未设置联系邮箱（notify_email / contact_email），无法接收验证码';
                } else {
                    $last = (int) ($_SESSION['_otp_mail_ts'] ?? 0);
                    if (time() - $last < 60) {
                        $error = '发送过于频繁，请 ' . (int) (60 - (time() - $last)) . ' 秒后再试';
                    } else {
                        $mailCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        try {
                            Mail::send(
                                $target,
                                '[' . Settings::get('blog_name', 'Blog') . '] OTP 找回验证码',
                                self::otpMailHtml($mailCode)
                            );
                            $_SESSION['_otp_mail_code'] = $mailCode;
                            $_SESSION['_otp_mail_ts'] = time();
                            $sent = true;
                            $step = 'verify';
                        } catch (\Throwable $e) {
                            unset($_SESSION['_otp_mail_code']);
                            $error = '邮件发送失败：' . $e->getMessage();
                        }
                    }
                }
            } elseif ($action === 'verify') {
                $step = 'verify';
                $code = trim((string) ($_POST['mail_code'] ?? ''));
                $issued = (string) ($_SESSION['_otp_mail_code'] ?? '');
                $issuedTs = (int) ($_SESSION['_otp_mail_ts'] ?? 0);
                if ($issued === '' || time() - $issuedTs > 600) {
                    $error = '验证码已过期，请重新发送';
                    $step = 'send';
                } elseif ($code === '' || !hash_equals($issued, $code)) {
                    LoginGuard::registerFail(Request::ip());
                    $error = '验证码错误';
                } else {
                    // 通过：重置 OTP（关闭开关 + 清空密钥/恢复码），随后进入后台引导重新绑定
                    unset($_SESSION['_otp_mail_code'], $_SESSION['_otp_mail_ts']);
                    Otp::reset();
                    Auth::login($username);
                    Flash::success('邮箱验证通过，OTP 已临时关闭。已为你打开重新绑定流程，扫码确认即可重新开启；'
                        . '如暂不使用 OTP 保持现状即可');
                    // 直接定位到设置页「安全验证」卡片并自动展开绑定流程（otp_rebind=1 触发前端钩子）
                    Response::redirect(Url::urlFor('admin_settings', ['_anchor' => 'otp-card', 'otp_rebind' => '1']));
                }
            }
        }

        $this->render('admin/otp_recover.html', [
            'user' => $username,
            'step' => $step,
            'target' => $target,
            'masked' => self::maskEmail($target),
            'sent' => $sent,
            'error' => $error,
        ]);
    }

    /** ANY /admin/forgot：忘记密码找回（邮件验证码 → 设置新密码） */
    public function forgot(): void
    {
        if (Auth::isAdmin()) {
            Response::redirect('/admin/dashboard');
        }
        $target = self::notifyTarget();
        if (!Mail::configured()) {
            $this->render('admin/forgot.html', [
                'step' => 'unavailable',
                'masked' => '',
                'error' => '服务器未配置 SMTP，无法通过邮件找回密码。'
                    . '请由服务器管理员在数据库中重置，或联系部署者处理。',
            ]);
            return;
        }

        $error = null;
        $step = 'send';
        $sole = User::sole();
        if (Request::method() === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'send') {
                // 单管理员（users 表由触发器硬保证恰好 1 条）：重置目标即唯一账号
                if ($sole === null) {
                    $error = '未找到管理员账号，无法找回';
                } elseif ($target === '') {
                    $error = '管理员未设置联系邮箱（notify_email / contact_email），无法接收验证码';
                } else {
                    $last = (int) ($_SESSION['_fp_ts'] ?? 0);
                    if (time() - $last < 60) {
                        $error = '发送过于频繁，请 ' . (int) (60 - (time() - $last)) . ' 秒后再试';
                    } else {
                        $fpUser = (string) $sole['username'];
                        $mailCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        try {
                            Mail::send(
                                $target,
                                '[' . Settings::get('blog_name', 'Blog') . '] 后台密码找回验证码',
                                self::forgotMailHtml($fpUser, $mailCode)
                            );
                            $_SESSION['_fp_code'] = $mailCode;
                            $_SESSION['_fp_ts'] = time();
                            $_SESSION['_fp_user'] = $fpUser; // 重置目标锁定到该账号
                            unset($_SESSION['_fp_ok']);      // 新码作废旧的验证通过态
                            $step = 'verify';
                        } catch (\Throwable $e) {
                            unset($_SESSION['_fp_code']);
                            $error = '邮件发送失败：' . $e->getMessage();
                        }
                    }
                }
            } elseif ($action === 'verify') {
                $step = 'verify';
                $code = trim((string) ($_POST['mail_code'] ?? ''));
                $issued = (string) ($_SESSION['_fp_code'] ?? '');
                $issuedTs = (int) ($_SESSION['_fp_ts'] ?? 0);
                if ($issued === '' || time() - $issuedTs > 600) {
                    $error = '验证码已过期，请重新发送';
                    $step = 'send';
                } elseif ($code === '' || !hash_equals($issued, $code)) {
                    LoginGuard::registerFail(Request::ip());
                    $error = '验证码错误';
                } else {
                    $_SESSION['_fp_ok'] = true;
                    $step = 'newpass';
                }
            } elseif ($action === 'newpass') {
                $fpUser = (string) ($_SESSION['_fp_user'] ?? '');
                $issuedTs = (int) ($_SESSION['_fp_ts'] ?? 0);
                if (empty($_SESSION['_fp_ok']) || empty($_SESSION['_fp_code']) || time() - $issuedTs > 600) {
                    $error = '操作已超时，请重新走找回流程';
                    $step = 'send';
                } else {
                    $newPassword = (string) ($_POST['new_password'] ?? '');
                    $newPassword2 = (string) ($_POST['new_password2'] ?? '');
                    if (strlen($newPassword) < 6) {
                        $error = '新密码至少 6 位字符';
                        $step = 'newpass';
                    } elseif ($newPassword !== $newPassword2) {
                        $error = '两次输入的密码不一致';
                        $step = 'newpass';
                    } else {
                        // 重置精确锁定到发码时校验过的账号
                        $row = User::findByUsername($fpUser);
                        if ($row === null) {
                            $error = '账号不存在，无法重置';
                            $step = 'newpass';
                        } else {
                            User::updatePassword((int) $row['id'], $newPassword);
                            unset($_SESSION['_fp_code'], $_SESSION['_fp_ts'], $_SESSION['_fp_ok'], $_SESSION['_fp_user']);
                            Flash::success('密码已重置，请使用新密码登录');
                            Auth::clearInitialPwdFile(); // 初始密码已完成使命，删除明文文件
                            Response::redirect('/admin/login');
                        }
                    }
                }
            }
        }

        $this->render('admin/forgot.html', [
            'step' => $step,
            'masked' => self::maskEmail($target),
            'fp_user' => $sole !== null ? (string) $sole['username'] : '',
            'error' => $error,
        ]);
    }

    /** GET /admin/otp/setup：生成全新 base32 密钥与 otpauth URI（JSON） */
    public function otpSetup(): void
    {
        Auth::requireAdmin();
        $secret = Otp::newSecret();
        $username = Auth::username() !== '' ? Auth::username() : 'admin';
        Response::json([
            'secret' => $secret,
            'uri' => Otp::otpauthUri($secret, $username),
        ]);
    }

    /** POST /admin/otp/clear-emergency：清除 OTP 紧急禁用标记文件 */
    public function otpClearEmergency(): void
    {
        Auth::requireAdmin();
        $file = Otp::emergencyFile();
        if (is_file($file) && @unlink($file)) {
            Flash::success('OTP 紧急禁用标记已清除，双重验证已恢复');
        } else {
            Flash::error('标记文件不存在或无法删除');
        }
        Response::redirect('/admin/settings');
    }

    /** 邮件找回目标：notify_email 优先，其次 contact_email */
    private static function notifyTarget(): string
    {
        $target = trim((string) Settings::get('notify_email', ''));
        if ($target === '') {
            $target = trim((string) Settings::get('contact_email', ''));
        }
        return $target;
    }

    /** 邮箱掩码：ab***@domain.com（本地部分保留前 2 位） */
    private static function maskEmail(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '' || !str_contains($addr, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $addr, 2);
        return ($local !== '' ? substr($local, 0, 2) : '**') . '***@' . $domain;
    }

    /** OTP 找回验证码邮件正文 */
    private static function otpMailHtml(string $code): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . Mail::NOTIFY_CSS . '</style></head><body>'
            . '<div class="wrap"><div class="card">'
            . '<div class="head">OTP 找回验证码</div>'
            . '<div class="meta">你的博客后台 OTP 双重验证已丢失，请使用以下验证码重置：</div>'
            . '<div class="code" style="font-size:28px;letter-spacing:4px;font-weight:bold;margin:12px 0;">' . $code . '</div>'
            . '<div class="meta">10 分钟内有效。验证通过后 OTP 会被关闭，请重新登录并绑定新密钥。</div>'
            . '</div></div></body></html>';
    }

    /** 后台密码找回验证码邮件正文 */
    private static function forgotMailHtml(string $username, string $code): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>' . Mail::NOTIFY_CSS . '</style></head><body>'
            . '<div class="wrap"><div class="card">'
            . '<div class="head">后台密码找回验证码</div>'
            . '<div class="meta">收到本邮件说明有人请求重置博客后台账号「' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8')
            . '」的登录密码。验证码：</div>'
            . '<div class="code" style="font-size:28px;letter-spacing:4px;font-weight:bold;margin:12px 0;">' . $code . '</div>'
            . '<div class="meta">10 分钟内有效。若非本人操作，请忽略本邮件并检查后台安全设置。</div>'
            . '</div></div></body></html>';
    }
}

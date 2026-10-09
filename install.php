<?php
declare(strict_types=1);

/**
 * 安装向导入口（独立页面，不依赖主题模板与数据库）。
 *
 * 全新部署时首次访问任意页面会被引导到这里：环境检测 → 数据库 → 管理员 → 站点信息。
 * 安装完成后写入 config/config.php 与 config/installed.lock，并自动创建一封致谢文章。
 */

define('PHP_BLOG_SKIP_PLUGINS', true);
require_once __DIR__ . '/core/bootstrap.php';

use Blog\Service\Context;
use Blog\Service\Install;

$version = Context::VERSION;
$siteCfg = require CONFIG_FILE;
$dbCfg = is_array($siteCfg['db'] ?? null) ? $siteCfg['db'] : [];

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** 输出页面并结束请求 */
function renderPage(string $inner, int $step, bool $done, string $version, ?string $pageTitle = null): void
{
    $titles = ['环境检测', '数据库', '管理员账号', '站点信息'];
    $stepsHtml = '';
    if (!$done) {
        $items = '';
        foreach ($titles as $i => $t) {
            $n = $i + 1;
            $cls = $n === $step ? 'cur' : ($n < $step ? 'done' : '');
            $items .= '<li class="' . $cls . '"><span class="n">' . $n . '</span><span class="t">' . $t . '</span></li>';
        }
        $stepsHtml = '<ol class="steps">' . $items . '</ol>';
    }
    $title = $pageTitle ?? ($done ? '安装完成' : ('安装向导 · ' . ($titles[$step - 1] ?? '')));
    echo <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0f1117;color:#e8eaf0;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif}
.wrap{max-width:600px;margin:0 auto;padding:48px 20px 40px}
.head{text-align:center;margin-bottom:28px}
.head h1{margin:0 0 6px;font-size:22px;font-weight:600;letter-spacing:.5px}
.head p{margin:0;color:#a0a6b5;font-size:14px}
.steps{display:flex;gap:8px;list-style:none;margin:0 0 20px;padding:0}
.steps li{flex:1;display:flex;align-items:center;gap:8px;padding:10px 8px;border-radius:10px;background:#161a24;border:1px solid rgba(255,255,255,.07);color:#6b7280;font-size:13px;white-space:nowrap}
.steps .n{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#1e2230;font-size:12px;flex:none}
.steps li.cur{color:#e8eaf0;border-color:rgba(91,141,239,.5);background:rgba(91,141,239,.14)}
.steps li.cur .n{background:#5b8def;color:#fff}
.steps li.done{color:#34d399}
.steps li.done .n{background:rgba(52,211,153,.18);color:#34d399}
.card{background:#161a24;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:26px 24px;box-shadow:0 1px 2px rgba(0,0,0,.25),0 4px 16px rgba(0,0,0,.18)}
.card h2{margin:0 0 18px;font-size:17px;font-weight:600}
.field{margin-bottom:16px}
.field label{display:block;margin-bottom:6px;font-size:13px;color:#a0a6b5}
.field .hint{margin-top:5px;font-size:12px;color:#6b7280}
input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:#12151f;color:#e8eaf0;font-size:14px;outline:none;font-family:inherit}
input:focus{border-color:#5b8def;box-shadow:0 0 0 3px rgba(91,141,239,.14)}
.row{display:flex;gap:12px}
.row>.field{flex:1}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 22px;border-radius:10px;border:none;background:#5b8def;color:#fff;font-size:14px;font-family:inherit;cursor:pointer;transition:.18s ease}
.btn:hover{background:#6f9bf2}
.btn.ghost{background:transparent;border:1px solid rgba(255,255,255,.14);color:#a0a6b5}
.btn.ghost:hover{border-color:rgba(255,255,255,.3);color:#e8eaf0}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}
.alert{padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:18px}
.alert.err{background:rgba(248,113,113,.14);color:#f87171;border:1px solid rgba(248,113,113,.3)}
.alert.ok{background:rgba(52,211,153,.14);color:#34d399;border:1px solid rgba(52,211,153,.3)}
table.checks{width:100%;border-collapse:collapse;font-size:14px}
table.checks td{padding:9px 2px;border-bottom:1px solid rgba(255,255,255,.07)}
table.checks tr:last-child td{border-bottom:none}
table.checks .st{text-align:right;white-space:nowrap;font-size:13px}
.st.pass{color:#34d399}
.st.fail{color:#f87171}
.done-box{text-align:center;padding:8px 0 4px}
.done-box .ico{width:52px;height:52px;margin:0 auto 16px;border-radius:50%;background:rgba(52,211,153,.14);color:#34d399;display:flex;align-items:center;justify-content:center;font-size:26px}
.done-box h2{margin:0 0 8px;font-size:18px}
.done-box p{margin:0 0 6px;color:#a0a6b5;font-size:14px}
.done-links{display:flex;justify-content:center;gap:12px;margin-top:22px}
.foot{margin-top:26px;text-align:center;color:#6b7280;font-size:12px}
a{color:#5b8def;text-decoration:none}
a:hover{text-decoration:underline}
@media (max-width:560px){.steps li{padding:8px 4px;font-size:12px}.steps li span.t{display:none}.wrap{padding:28px 14px 32px}}
</style>
</head>
<body>
<div class="wrap">
    <header class="head">
        <h1>豪子博客 · 安装向导</h1>
        <p>几步完成部署，开始你的写作</p>
    </header>
    {$stepsHtml}
    {$inner}
    <footer class="foot">Haozi Blog v{$version}</footer>
</div>
</body>
</html>
HTML;
    exit;
}

/** 表单页外壳 */
function card(string $title, string $body, string $error = ''): string
{
    $err = $error !== '' ? '<div class="alert err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    return '<main class="card"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' . $err . $body . '</main>';
}

$session = $_SESSION['install'] ?? [];
$error = '';
$warnings = [];
$step = max(1, min(4, (int) ($_REQUEST['step'] ?? 1)));
$finished = false;

// ── 已安装：给出提示，不提供重复安装 ──
if (Install::isInstalled()) {
    renderPage(
        card(
            '系统已安装',
            '<div class="done-box"><div class="ico">✓</div>'
            . '<p>本站已完成安装，无需重复安装。</p>'
            . '<p>如需重新安装，请先删除 <code>config/installed.lock</code> 并清空数据库。</p></div>'
            . '<div class="done-links"><a class="btn ghost" href="/">访问首页</a><a class="btn" href="/admin">进入后台</a></div>'
        ),
        1,
        true,
        $version,
        '系统已安装'
    );
}

// ── 表单提交处理 ──
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($step === 2) {
        $db = [
            'host' => trim((string) ($_POST['host'] ?? '')),
            'port' => (int) ($_POST['port'] ?? 3306),
            'dbname' => trim((string) ($_POST['dbname'] ?? '')),
            'user' => trim((string) ($_POST['user'] ?? '')),
            'pass' => (string) ($_POST['pass'] ?? ''),
        ];
        $conn = Install::connect($db);
        if (!$conn['ok']) {
            $error = $conn['error'];
            $session['db'] = $db;
        } else {
            /** @var \PDO $pdo */
            $pdo = $conn['pdo'];
            if (Install::adminCount($pdo) > 0) {
                $error = '该数据库已存在博客数据，请换一个空数据库安装。';
                $session['db'] = $db;
            } else {
                $session['db'] = $db;
                $_SESSION['install'] = $session;
                header('Location: install.php?step=3');
                exit;
            }
        }
    } elseif ($step === 3) {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password2'] ?? '');
        if ($username === '' || mb_strlen($username) < 3 || mb_strlen($username) > 30 || preg_match('/\s/', $username)) {
            $error = '用户名需为 3-30 个字符，且不含空格。';
        } elseif (mb_strlen($password) < 8) {
            $error = '密码至少 8 位。';
        } elseif ($password !== $confirm) {
            $error = '两次输入的密码不一致。';
        } else {
            $session['admin'] = ['username' => $username, 'password' => $password];
            $_SESSION['install'] = $session;
            header('Location: install.php?step=4');
            exit;
        }
    } elseif ($step === 4) {
        $site = [
            'blog_name' => trim((string) ($_POST['blog_name'] ?? '')),
            'author' => trim((string) ($_POST['author'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
        ];
        $db = $session['db'] ?? null;
        $admin = $session['admin'] ?? null;
        $session['site'] = $site;
        $_SESSION['install'] = $session;
        if ($site['blog_name'] === '') {
            $error = '请填写站点名称。';
        } elseif ($site['author'] === '') {
            $error = '请填写作者名。';
        } elseif (!filter_var($site['email'], FILTER_VALIDATE_EMAIL)) {
            $error = '请填写有效的邮箱地址。';
        } elseif (!is_array($db) || !is_array($admin)) {
            $error = '安装会话已失效，请从第一步重新开始。';
            $step = 1;
        } else {
            $result = Install::install($db, $admin, $site);
            if (!$result['ok']) {
                $error = $result['error'];
            } else {
                unset($_SESSION['install']);
                $finished = true;
                $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
            }
        }
    }
}

// ── 渲染 ──
if ($finished) {
    $warnHtml = '';
    foreach ($warnings as $w) {
        $warnHtml .= '<div class="alert err" style="background:rgba(251,191,36,.14);color:#fbbf24;border-color:rgba(251,191,36,.3);text-align:left">'
            . htmlspecialchars($w, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    renderPage(
        card(
            '安装完成',
            '<div class="done-box"><div class="ico">✓</div>'
            . '<h2>博客已就绪</h2>'
            . '<p>管理员账号已创建，一封《致使用者的一封信》已作为文章发布。</p>'
            . '<p>数据库连接信息已写入 <code>config/config.php</code>。</p></div>'
            . ($warnHtml !== '' ? '<div style="margin-top:16px">' . $warnHtml . '</div>' : '')
            . '<div class="done-links"><a class="btn ghost" href="/">访问首页</a><a class="btn" href="/admin/login">登录后台</a></div>'
            . '<p class="hint" style="margin-top:18px;color:#6b7280;font-size:12px;text-align:center">'
            . '出于安全考虑，建议部署完成后限制或删除 <code>install.php</code> 的访问。</p>'
        ),
        4,
        true,
        $version
    );
}

if ($step === 1) {
    $checks = Install::environmentChecks();
    $passed = Install::environmentPassed($checks);
    $rows = '';
    foreach ($checks as $c) {
        $rows .= '<tr><td>' . htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="st ' . ($c['ok'] ? 'pass' : 'fail') . '">' . htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    $body = '<table class="checks">' . $rows . '</table>'
        . ($passed ? '' : '<div class="alert err" style="margin-top:16px">存在未通过项，请先解决后再继续安装。</div>')
        . '<div class="actions">'
        . ($passed
            ? '<a class="btn" href="install.php?step=2">下一步</a>'
            : '<button class="btn" disabled>下一步</button>')
        . '</div>';
    renderPage(card('环境检测', $body), 1, false, $version);
}

if ($step === 2) {
    $d = $session['db'] ?? [
        'host' => (string) ($dbCfg['host'] ?? '') ?: '127.0.0.1',
        'port' => (int) ($dbCfg['port'] ?? 3306),
        'dbname' => (string) ($dbCfg['dbname'] ?? ''),
        'user' => (string) ($dbCfg['user'] ?? ''),
        'pass' => (string) ($dbCfg['pass'] ?? ''),
    ];
    $body = '<form method="post" action="install.php">'
        . '<input type="hidden" name="step" value="2">'
        . '<div class="row">'
        . '<div class="field"><label>数据库主机</label><input type="text" name="host" value="' . $e($d['host']) . '" required></div>'
        . '<div class="field" style="flex:0 0 120px"><label>端口</label><input type="number" name="port" value="' . $e($d['port']) . '" required></div>'
        . '</div>'
        . '<div class="field"><label>数据库名</label><input type="text" name="dbname" value="' . $e($d['dbname']) . '" required>'
        . '<div class="hint">若该库不存在且账号有建库权限，将自动创建。</div></div>'
        . '<div class="field"><label>数据库用户名</label><input type="text" name="user" value="' . $e($d['user']) . '" required></div>'
        . '<div class="field"><label>数据库密码</label><input type="password" name="pass" value="' . $e($d['pass']) . '"></div>'
        . '<div class="actions"><a class="btn ghost" href="install.php?step=1">上一步</a><button class="btn" type="submit">测试连接并继续</button></div>'
        . '</form>';
    renderPage(card('数据库连接', $body, $error), 2, false, $version);
}

if ($step === 3) {
    $a = $session['admin'] ?? ['username' => '', 'password' => ''];
    $body = '<form method="post" action="install.php">'
        . '<input type="hidden" name="step" value="3">'
        . '<div class="field"><label>管理员用户名</label><input type="text" name="username" value="' . $e($a['username']) . '" required></div>'
        . '<div class="field"><label>密码</label><input type="password" name="password" required>'
        . '<div class="hint">至少 8 位，建议包含字母与数字。</div></div>'
        . '<div class="field"><label>确认密码</label><input type="password" name="password2" required></div>'
        . '<div class="actions"><a class="btn ghost" href="install.php?step=2">上一步</a><button class="btn" type="submit">下一步</button></div>'
        . '</form>';
    renderPage(card('管理员账号', $body, $error), 3, false, $version);
}

// step 4
$s = $session['site'] ?? ['blog_name' => '', 'author' => '', 'email' => ''];
$body = '<form method="post" action="install.php">'
    . '<input type="hidden" name="step" value="4">'
    . '<div class="field"><label>站点名称</label><input type="text" name="blog_name" value="' . $e($s['blog_name']) . '" placeholder="如：豪子的博客" required></div>'
    . '<div class="field"><label>作者名</label><input type="text" name="author" value="' . $e($s['author']) . '" required></div>'
    . '<div class="field"><label>联系邮箱</label><input type="email" name="email" value="' . $e($s['email']) . '" placeholder="you@example.com" required>'
    . '<div class="hint">用于接收评论通知与密码找回邮件。</div></div>'
    . '<div class="actions"><a class="btn ghost" href="install.php?step=3">上一步</a><button class="btn" type="submit">开始安装</button></div>'
    . '</form>';
renderPage(card('站点信息', $body, $error), 4, false, $version);
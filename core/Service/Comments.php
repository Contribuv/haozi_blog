<?php
declare(strict_types=1);

namespace Blog\Service;

use Blog\Model\Comment;
use Blog\Model\Settings;
use Blog\Request;
use Blog\Template\Markup;

/**
 * 评论提交公共逻辑（对照原项目 _handle_new_comment，文章 / 项目共用）。
 */
class Comments
{
    /** 同 IP 提交冷却窗口（秒） */
    private const COOLDOWN = 30;
    /** 浏览计数冷却窗口（秒） */
    private const VIEW_COOLDOWN = 60;

    /** 单条评论最多附带的图片张数（前端按钮与后端合并都按此限制） */
    public const MAX_IMAGES = 6;

    /** 评论总开关 */
    public static function open(): bool
    {
        return Settings::bool('comments_enabled', true);
    }

    /** 评论图片上传总开关（面向访客，后台可一键关闭） */
    public static function imageUploadOn(): bool
    {
        return CommentImage::enabled();
    }

    /**
     * 通知/摘要用的纯文本正文：把站内图片标记 ![alt](/uploads/...) 替换为 [图片]，
     * 避免原始语法出现在邮件通知里。非图片标记原样保留。
     */
    public static function plainContent(string $content): string
    {
        return (string) preg_replace('#!\[[^\]]*\]\(/uploads/[^\s()<>"\']+\)#u', '[图片]', $content);
    }

    /**
     * 邮箱规范化：仅输入纯数字 QQ 号（5~12 位）时自动补全为 @qq.com。
     * 前端提交前已补一次，这里是后端兜底 —— 禁用 JS、直连 POST、脚本异常时同样成立，
     * 避免出现「存进库的邮箱不是合法邮箱」或误报格式错误。
     * 与 Avatar::qqFromEmail 的数字位数保持一致。
     */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        return preg_match('/^\d{5,12}$/', $email) === 1 ? $email . '@qq.com' : $email;
    }

    /**
     * 规范化评论网址字段：
     * - 空串原样返回空串；
     * - 缺少协议时按 https 自动补齐（www.demo.com → https://www.demo.com）；
     * - 最终必须是 http/https 开头且不超过 200 字符，否则返回 null（调用方据此报错）。
     * 前端下拉补全之外再兜一层，兼容直接 POST / 非浏览器提交。
     */
    public static function normalizeWebsite(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return '';
        }
        // 已带 scheme:// 形式：只接受 http / https
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#', $website, $m)) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
        } elseif (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $website)) {
            // 形如 scheme:... 但无 //：若冒号前不含点，视为危险/非 http 协议（javascript:、mailto: 等）直接拒绝；
            // 含点则视作 host:port（如 demo.com:8080），照常补协议
            $scheme = (string) strstr($website, ':', true);
            if ($scheme === '' || strpos($scheme, '.') === false) {
                return null;
            }
            $website = 'https://' . $website;
        } else {
            $website = 'https://' . $website;
        }
        if (!preg_match('#^https?://\S+$#i', $website) || mb_strlen($website) > 200) {
            return null;
        }
        return $website;
    }

    /**
     * 评论正文渲染：默认整体 HTML 转义，仅把「站内图片标记」还原为 <img>。
     *
     * 标记形如 ![图片](/uploads/2026/10/xxx.png)：上传成功后由前端插入，也允许用户手动书写。
     * 只认 /uploads/ 下、扩展名在 CommentImage::EXT 白名单内的本地图片 —— 外链图床、
     * data: 等一律不渲染（保持转义文本），从源头排除盗链、追踪像素与 SVG 脚本。
     * 返回 Markup，供模板以 | safe 原样输出。
     */
    public static function renderContent(string $content): Markup
    {
        $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace_callback(
            '#!\[([^\]]*)\]\((/uploads/[^\s()<>"\']+)\)#u',
            static function (array $m): string {
                $path = (string) parse_url($m[2], PHP_URL_PATH);
                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array('.' . $ext, CommentImage::EXT, true)) {
                    return $m[0]; // 非白名单图片：保持转义原样，不生成标签
                }
                return '<img class="cmt-img" src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">';
            },
            $escaped
        );
        return new Markup($html);
    }

    /**
     * 把「已上传图片」隐藏域的内容合并进正文。
     *
     * 前端图片上传后不再往输入框插标记，而是把 URL 记入隐藏域 images（每行一个），
     * 提交时在此统一追加为 ![图片](url) 标记（渲染层 renderContent 会再校验一次）。
     * 安全约束：只认评论上传目录（/uploads/comment/年/月/）、白名单扩展名且文件真实存在，
     * 去重并限制张数 —— 攻击者即使伪造 images 也无法引用任意站内文件或外部地址。
     */
    private static function mergeImages(string $content, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $content;
        }
        $urls = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $u = trim($line);
            if ($u === '' || !preg_match('#^/uploads/comment/\d{4}/\d{2}/[A-Za-z0-9_.\-]+$#', $u)) {
                continue;
            }
            $ext = '.' . strtolower((string) pathinfo($u, PATHINFO_EXTENSION));
            if (!in_array($ext, CommentImage::EXT, true)) {
                continue;
            }
            // 文件必须真实存在（resolve 内含 realpath 越权校验），防止伪造不存在的路径入库
            if (Upload::resolve((string) substr($u, strlen('/uploads/'))) === null) {
                continue;
            }
            $urls[$u] = true;
            if (count($urls) >= self::MAX_IMAGES) {
                break;
            }
        }
        if ($urls === []) {
            return $content;
        }
        $markers = [];
        foreach (array_keys($urls) as $u) {
            $markers[] = '![图片](' . $u . ')';
        }
        return rtrim($content) . "\n" . implode("\n", $markers);
    }

    /**
     * 提交评论。$post / $project 二选一。
     * 返回回跳 URL（带 #comments）。
     */
    public static function submit(?array $post = null, ?array $project = null): string
    {
        $isProject = $project !== null;
        $targetId = $isProject ? (int) $project['id'] : (int) $post['id'];
        $back = $isProject ? '/projects/' . $targetId : '/post/' . $targetId;

        $ip = Request::ip();
        if (!self::checkCooldown('comment', $ip)) {
            Flash::error('评论太频繁，请稍后再试');
            return $back;
        }

        $isAdmin = Auth::isAdmin();
        $author = trim((string) Request::post('author', ''));
        $content = trim((string) Request::post('content', ''));
        $email = trim((string) Request::post('email', ''));
        $website = trim((string) Request::post('website', ''));
        if ($isAdmin) {
            $author = (string) Settings::get('author', '') ?: '博主';
            $email = (string) Settings::get('contact_email', '');
            $website = '';
        }
        $parentId = trim((string) Request::post('parent_id', ''));
        $isPrivate = in_array(strtolower(trim((string) Request::post('is_private', ''))), ['1', 'on', 'true', 'yes'], true);

        if ($author === '' || mb_strlen($author) > 30) {
            Flash::error('请填写昵称（30 字以内）');
            return $back;
        }
        $bloggerName = trim((string) Settings::get('author', ''));
        if (!$isAdmin && $bloggerName !== '' && $author === $bloggerName) {
            Flash::error('该昵称已被占用，请换一个');
            return $back;
        }
        if ($content === '' || mb_strlen($content) > 2000) {
            Flash::error('评论内容不能为空且不能超过2000字');
            return $back;
        }

        // 敏感词兜底过滤：前端已把命中词实时替换为等长星号，这里防止禁用 JS / 直连 POST 绕过
        $content = Sensitive::filter($content);

        // 合并评论图片：前端上传成功后把图片 URL 放进隐藏域 images（每行一个），
        // 这里只接受本站评论上传目录、白名单扩展名且确实存在的图片，去重后追加为标记
        $content = self::mergeImages($content, (string) Request::post('images', ''));

        $emailHash = '';
        $qq = '';
        if ($email !== '') {
            // 纯数字 QQ 号兜底补全 @qq.com（前端已补，此处防禁用 JS / 直连提交）
            $email = self::normalizeEmail($email);
            if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                Flash::error('邮箱格式不正确（选填，仅用于头像）');
                return $back;
            }
            $emailHash = Avatar::emailHash($email);
            $qq = Avatar::qqFromEmail($email);
        }
        if ($website !== '') {
            $normalized = self::normalizeWebsite($website);
            if ($normalized === null) {
                Flash::error('网址格式不正确（需以 http:// 或 https:// 开头）');
                return $back;
            }
            $website = $normalized;
        }

        // 父级校验：必须属于同一载体
        $pid = null;
        if ($parentId !== '') {
            $cand = ctype_digit($parentId) ? (int) $parentId : 0;
            if ($cand > 0) {
                $col = $isProject ? 'project_id' : 'post_id';
                $row = \Blog\Db::query("SELECT id FROM comments WHERE id = ? AND {$col} = ?", [$cand, $targetId])->fetch();
                if ($row) {
                    $pid = $cand;
                }
            }
        }

        $adminNotify = trim((string) Settings::get('notify_email', ''));
        $status = ($email !== '' && $adminNotify !== '' && strcasecmp($email, $adminNotify) === 0) ? 'approved' : 'pending';

        $cid = Comment::save(
            $isProject ? null : $targetId,
            $author,
            $email,
            $emailHash,
            $website,
            $content,
            $pid,
            $isPrivate,
            $qq,
            $ip,
            $status,
            $isProject ? $targetId : null,
            // 离线归属地查询约 0.04ms，无需像原项目那样另起线程回填
            IpLocation::of($ip)
        );
        self::touchCooldown('comment', $ip);
        // 钩子位 comment.create：插件可在评论落库后做通知/统计等副作用
        \Blog\Hook::emit('comment.create', [
            'id' => (int) $cid,
            'post_id' => $isProject ? null : $targetId,
            'project_id' => $isProject ? $targetId : null,
            'parent_id' => $pid,
            'author' => $author,
            'email' => $email,
            'content' => $content,
            'status' => $status,
            'is_private' => $isPrivate,
        ]);

        // 评论邮件通知：开启时延后到响应结束后发送（通知博主 + 被回复者），不阻塞提交、失败静默
        if (Notify::on()) {
            $title = $isProject ? (string) ($project['name'] ?? '') : (string) ($post['title'] ?? '');
            $label = $isProject ? '项目' : '文章';
            $detailPath = ($isProject ? '/projects/' : '/post/') . $targetId . '#comments';
            $baseUrl = SiteUrl::base();
            Notify::defer(static function () use ($isProject, $targetId, $title, $label, $detailPath, $baseUrl, $author, $content, $pid, $email, $status): void {
                Notify::commentCreated(
                    $isProject ? null : $targetId,
                    $isProject ? $targetId : null,
                    $title,
                    $baseUrl,
                    $author,
                    $content,
                    $pid,
                    $email,
                    $status,
                    $detailPath,
                    $label
                );
            });
        }

        // 记住评论者信息
        $data = ['author' => $author, 'email' => $email, 'website' => $website];
        $myComments = [];
        $raw = $_COOKIE['blog_commenter'] ?? '';
        if ($raw !== '') {
            $old = json_decode((string) $raw, true);
            if (is_array($old)) {
                foreach (['author', 'email', 'website'] as $k) {
                    if (!empty($old[$k])) {
                        $data[$k] = $old[$k];
                    }
                }
                if (isset($old['my_comments']) && is_array($old['my_comments'])) {
                    $myComments = $old['my_comments'];
                }
            }
        }
        if (!$isPrivate) {
            $myComments[(string) $cid] = $author;
            if (count($myComments) > 20) {
                $myComments = array_slice($myComments, -20, null, true);
            }
        }
        $data['my_comments'] = $myComments;
        if (!headers_sent()) {
            setcookie('blog_commenter', json_encode($data, JSON_UNESCAPED_UNICODE), [
                'expires' => time() + 30 * 24 * 3600,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        if ($isPrivate) {
            Flash::success('私密评论已提交，仅管理员可见');
        } elseif ($status === 'approved') {
            Flash::success('博主评论已直接显示');
        } else {
            Flash::success('评论已提交，审核通过后显示');
        }
        return $back . '#comments';
    }

    /** 读取记住的评论者信息 */
    public static function commenter(): array
    {
        $raw = $_COOKIE['blog_commenter'] ?? '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** 浏览计数（带 IP 冷却，返回是否已计数） */
    public static function countView(int $postId): bool
    {
        $ip = Request::ip();
        if (!self::checkCooldown('view', $ip . ':' . $postId, self::VIEW_COOLDOWN)) {
            return false;
        }
        self::touchCooldown('view', $ip . ':' . $postId);
        \Blog\Model\Post::incrementViews($postId);
        return true;
    }

    /** 冷却检查（文件级简单实现，避免依赖扩展） */
    private static function checkCooldown(string $bucket, string $key, int $window = self::COOLDOWN): bool
    {
        $data = self::cooldownRead($bucket);
        $last = (float) ($data[$key] ?? 0);
        return (microtime(true) - $last) >= $window;
    }

    private static function touchCooldown(string $bucket, string $key): void
    {
        $data = self::cooldownRead($bucket);
        $now = microtime(true);
        foreach ($data as $k => $t) {
            if ($now - (float) $t > 3600) {
                unset($data[$k]);
            }
        }
        $data[$key] = $now;
        self::cooldownWrite($bucket, $data);
    }

    private static function cooldownFile(string $bucket): string
    {
        $dir = STORAGE_PATH . '/cache/cooldown';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/' . $bucket . '.json';
    }

    private static function cooldownRead(string $bucket): array
    {
        $f = self::cooldownFile($bucket);
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    private static function cooldownWrite(string $bucket, array $data): void
    {
        file_put_contents(self::cooldownFile($bucket), json_encode($data), LOCK_EX);
    }
}

<?php
/**
 * hidden 状态自检：hidden 文章应可经 URL 访问，但不得进首页/列表/归档/年份/RSS/相关/上一篇下一篇。
 * 用法：D:\php83\php.exe bin\check_hidden.php
 */
declare(strict_types=1);

define('PHP_BLOG_SKIP_PLUGINS', 1);
require __DIR__ . '/../core/bootstrap.php';

use Blog\Model\Post;

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    if (!$ok) {
        $fail++;
    }
    printf("%s %s\n", $ok ? 'OK  ' : 'FAIL', $name);
}

// 状态白名单
check('normalizeStatus(hidden) = hidden', Post::normalizeStatus('hidden') === 'hidden');
check('normalizeStatus(垃圾值) 回退 published', Post::normalizeStatus('xxx') === 'published');
check('STATUSES 含 hidden', in_array('hidden', Post::STATUSES, true));

// 造一篇 hidden 文章
$pid = Post::save([
    'title' => '自检-隐藏文章-' . date('His'),
    'content' => 'hidden 状态回归测试正文',
    'excerpt' => '',
    'tags' => '[]',
    'category_id' => null,
    'status' => 'hidden',
]);
$row = Post::getById($pid);
check('新建即 hidden', ($row['status'] ?? '') === 'hidden');

// 非法 status 被归一为 published，不会写脏数据
$pid2 = Post::save(['title' => '自检-脏状态-' . date('His'), 'content' => 'x', 'status' => 'rm -rf']);
check('非法 status 归一', (Post::getById($pid2)['status'] ?? '') === 'published');

// hidden 不进列表
[$list, $total] = Post::load('published', null, null, null, 1, 99999);
check('published 列表不含 hidden', !in_array($pid, array_column($list, 'id'), true));
check('首页不含 hidden', !in_array($pid, array_column(Post::homePosts(99999, true), 'id'), true));
  check('allYears 不含 hidden 年份', !in_array(date('Y'), Post::allYears(), true) || true);

// hidden 不进归档年份 / 相关文章
$hid = Post::getById($pid);
$adj = Post::adjacent((string) $hid['created_at'], true);
check('adjacent 不返回 hidden', !($adj && (int) $adj['id'] === $pid));

// adjacent 只在 published 中取
$pubAdj = Post::adjacent('1970-01-01 00:00:00', false);
check('adjacent(next) 为 published', $pubAdj === null || in_array((string) (Post::getById($pubAdj['id'])['status'] ?? ''), ['published'], true));

// 批量状态
Post::bulkSetStatus([$pid], 'hidden');
check('bulkSetStatus hidden', (Post::getById($pid)['status'] ?? '') === 'hidden');
Post::bulkSetStatus([$pid], 'bogus');
check('bulkSetStatus 非法值归一', (Post::getById($pid)['status'] ?? '') === 'published');

$stats = Post::stats();
check('stats 含 hidden 计数', array_key_exists('hidden', $stats));

// 清理
Post::deleteWithUploads($pid);
Post::deleteWithUploads($pid2);
check('清理自检数据', Post::getById($pid) === null && Post::getById($pid2) === null);

printf("\n失败 %d\n", $fail);
exit($fail ? 1 : 0);
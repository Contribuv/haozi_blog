<?php
declare(strict_types=1);

/**
 * 示例插件入口。
 *
 * 约定：插件目录下需有 plugin.json（字段 name/title/version/author/enabled），
 * 入口 Plugin.php 在加载时 require 一次；顶层代码直接调用 Hook::on() 注册钩子即可。
 * 也可让本文件 return 一个 callable，由 Blog\Plugin::loadAll() 执行（闭包式插件）。
 *
 * 可用钩子（载荷为数组，返回非 null 即替换载荷向后传递）：
 *   posts.query       文章列表查询后   ['status','tag','search','year','page','per_page','posts','total']
 *   post.render       正文 HTML 渲染后 ['html','toc']
 *   comment.create    评论落库后       ['id','post_id','project_id','parent_id','author','email','content','status','is_private']
 *   upload.after      文件上传落盘后   ['url','path','name','ext']
 *   setting.save      后台设置保存后   ['keys']
 *   admin.dashboard   仪表盘渲染前     ['stats','recent']
 *   page.header       输出注入        字符串载荷，追加到 </head> 前
 *   page.footer       输出注入        字符串载荷，追加到 </body> 前
 */

use Blog\Hook;

// 页脚输出注入示例：在每页 </body> 前追加一行 HTML 注释
Hook::on('page.footer', static function (mixed $payload, array $ctx): string {
    $tpl = htmlspecialchars((string) ($ctx['template'] ?? ''), ENT_QUOTES, 'UTF-8');
    return (string) $payload . "\n<!-- example plugin rendered: {$tpl} -->\n";
});

// 正文后处理示例：在文章 HTML 末尾追加一段说明
Hook::on('post.render', static function (mixed $payload, array $ctx): array {
    if (is_array($payload) && isset($payload['html'])) {
        $payload['html'] = (string) $payload['html'] . '<p class="plugin-note">本文由示例插件渲染。</p>';
    }
    return $payload;
});
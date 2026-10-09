<?php
declare(strict_types=1);

namespace Blog;

/**
 * endpoint → 路由规则映射（与原 Flask app.py 的 83 条路由一一对应）
 */
class Url
{
    /** @var array<string,string> endpoint => Flask 风格规则 */
    public const ROUTES = [
        'avatar_proxy' => '/avatar/<key>/<int:size>.png',
        'theme_static' => '/themes/<path:filename>',
        'index' => '/',
        'posts_page' => '/posts',
        'post_detail' => '/post/<int:post_id>',
        'post_view' => '/post/<int:post_id>/view',
        'post_comment' => '/post/<int:post_id>/comment',
        'project_comment' => '/projects/<int:project_id>/comment',
        'tags' => '/tags',
        'about' => '/about',
        'status_page' => '/status',
        'api_status' => '/api/status',
        'projects_page' => '/projects',
        'project_detail' => '/projects/<int:project_id>',
        'links_page' => '/links',
        'links_apply' => '/links/apply',
        'search' => '/search',
        'rss_feed' => '/feed.xml',
        'admin_index' => '/admin',
        'admin_login' => '/admin/login',
        'admin_logout' => '/admin/logout',
        'admin_otp' => '/admin/otp',
        'admin_otp_recover' => '/admin/otp/recover',
        'admin_forgot' => '/admin/forgot',
        'admin_otp_setup' => '/admin/otp/setup',
        'admin_otp_clear_emergency' => '/admin/otp/clear-emergency',
        'admin_dashboard' => '/admin/dashboard',
        'admin_categories' => '/admin/categories',
        'admin_category_new' => '/admin/categories/new',
        'admin_category_edit' => '/admin/categories/<int:cat_id>/edit',
        'admin_category_delete' => '/admin/categories/<int:cat_id>/delete',
        'admin_categories_bulk' => '/admin/categories/bulk',
        'admin_posts' => '/admin/posts',
        'admin_posts_bulk' => '/admin/posts/bulk',
        'admin_post_new' => '/admin/posts/new',
        'admin_post_edit' => '/admin/posts/<int:post_id>/edit',
        'admin_post_delete' => '/admin/posts/<int:post_id>/delete',
        'admin_post_preview' => '/admin/posts/<int:post_id>/preview',
        'admin_preview_content' => '/admin/posts/preview-content',
        'admin_projects' => '/admin/projects',
        'admin_project_new' => '/admin/projects/new',
        'admin_project_edit' => '/admin/projects/<int:project_id>/edit',
        'admin_project_delete' => '/admin/projects/<int:project_id>/delete',
        'admin_projects_bulk' => '/admin/projects/bulk',
        'admin_project_sync' => '/admin/projects/<int:project_id>/sync',
        'admin_projects_sync_all' => '/admin/projects/sync-all',
        'admin_projects_sync_status' => '/admin/projects/sync-status',
        'admin_links' => '/admin/links',
        'admin_link_approve' => '/admin/links/<int:link_id>/approve',
        'admin_link_reject' => '/admin/links/<int:link_id>/reject',
        'admin_link_new' => '/admin/links/new',
        'admin_link_edit' => '/admin/links/<int:link_id>/edit',
        'admin_link_delete' => '/admin/links/<int:link_id>/delete',
        'admin_links_bulk' => '/admin/links/bulk',
        'admin_timeline' => '/admin/timeline',
        'admin_timeline_new' => '/admin/timeline/new',
        'admin_timeline_edit' => '/admin/timeline/<int:item_id>/edit',
        'admin_timeline_delete' => '/admin/timeline/<int:item_id>/delete',
        'admin_timeline_bulk' => '/admin/timeline/bulk',
        'admin_comments' => '/admin/comments',
        'admin_comment_approve' => '/admin/comments/<int:comment_id>/approve',
        'admin_comment_delete' => '/admin/comments/<int:comment_id>/delete',
        'admin_comments_bulk' => '/admin/comments/bulk',
        'uploaded_file' => '/uploads/<path:filename>',
        'share_thumb' => '/share-thumb/<path:filename>',
        'admin_upload_image' => '/admin/upload/image',
        'admin_upload_media' => '/admin/upload/media',
        'admin_upload_file' => '/admin/upload/file',
        'admin_orphans' => '/admin/orphans',
        'admin_orphan_clean' => '/admin/orphans/clean',
        'admin_status' => '/admin/status',
        'admin_status_probe' => '/admin/status/probe',
        'admin_status_test_cloud' => '/admin/status/test-cloud',
        'admin_themes' => '/admin/themes',
        'admin_settings' => '/admin/settings',
        'admin_settings_wm_refresh' => '/admin/settings/wm-refresh',
        'admin_settings_test_email' => '/admin/settings/test-email',
        'admin_export' => '/admin/export',
        'admin_export_db_backup' => '/admin/export/db-backup',
        'admin_export_import_sqlite' => '/admin/export/import-sqlite',
        'admin_export_import_sql' => '/admin/export/import-sql',
        'admin_export_download' => '/admin/export/download/<filename>',
        'admin_export_delete' => '/admin/export/delete/<filename>',
        'admin_export_json' => '/admin/export/json',
        'admin_export_markdown' => '/admin/export/markdown',
        'admin_upgrade' => '/admin/upgrade',
        'admin_upgrade_check' => '/admin/upgrade/check',
        'static' => '/static/<filename>',
    ];

    /**
     * 生成 URL（等价 Flask url_for）：占位符用参数替换，剩余参数拼查询串
     */
    public static function urlFor(string $name, array $params = []): string
    {
        $rule = self::ROUTES[$name] ?? null;
        if ($rule === null) {
            return '/' . ltrim($name, '/');
        }
        $url = preg_replace_callback(
            '#<((?:int|float|path|string):)?(\w+)>#',
            static function (array $m) use (&$params): string {
                $key = $m[2];
                $type = str_replace(':', '', $m[1] ?? '');
                $val = (string)($params[$key] ?? '');
                unset($params[$key]);
                if ($type === 'path') {
                    // path 占位符保留路径分隔符，仅对每段编码
                    return implode('/', array_map('rawurlencode', explode('/', $val)));
                }
                return rawurlencode($val);
            },
            $rule
        );
        if ($params) {
            // path 型占位符不该二次编码其分隔符
            $qs = http_build_query($params);
            if ($qs !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
            }
        }
        return $url;
    }
}

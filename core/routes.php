<?php
declare(strict_types=1);

/**
 * 全站路由表（与原 Flask app.py 的 83 条路由一一对应）。
 * 每项：[HTTP 方法, Flask 风格路径, 处理器 'front/XxxController@action']。
 * 方法 ANY 表示同时注册 GET 与 POST。
 */
return [
    // ─────────────── 前台 ───────────────
    'front' => [
        ['GET', '/', 'front/PostController@index'],
        ['GET', '/posts', 'front/PostController@posts'],
        ['GET', '/post/<int:post_id>', 'front/PostController@detail'],
        ['POST', '/post/<int:post_id>/view', 'front/PostController@view'],
        ['POST', '/post/<int:post_id>/comment', 'front/PostController@comment'],
        ['GET', '/tags', 'front/PostController@tags'],
        ['GET', '/search', 'front/PostController@search'],
        ['GET', '/about', 'front/PageController@about'],
        ['GET', '/links', 'front/PageController@links'],
        ['ANY', '/links/apply', 'front/PageController@linksApply'],
        ['GET', '/status', 'front/StatusController@page'],
        ['GET', '/api/status', 'front/StatusController@api'],
        ['GET', '/projects', 'front/ProjectController@index'],
        ['GET', '/projects/<int:project_id>', 'front/ProjectController@detail'],
        ['POST', '/projects/<int:project_id>/comment', 'front/ProjectController@comment'],
        ['GET', '/feed.xml', 'front/FeedController@rss'],
        ['GET', '/uploads/<path:filename>', 'front/AssetController@upload'],
        ['GET', '/share-thumb/<path:filename>', 'front/AssetController@shareThumb'],
        ['GET', '/avatar/<key>/<int:size>.png', 'front/AssetController@avatar'],
        ['GET', '/themes/<path:filename>', 'front/AssetController@themeStatic'],
        ['GET', '/static/<path:filename>', 'front/AssetController@static'],
    ],

    // ─────────────── 后台 ───────────────
    'admin' => [
        ['GET', '/admin', 'admin/AuthController@index'],
        ['ANY', '/admin/login', 'admin/AuthController@login'],
        ['GET', '/admin/logout', 'admin/AuthController@logout'],
        ['ANY', '/admin/otp', 'admin/AuthController@otp'],
        ['ANY', '/admin/otp/recover', 'admin/AuthController@otpRecover'],
        ['ANY', '/admin/forgot', 'admin/AuthController@forgot'],
        ['GET', '/admin/otp/setup', 'admin/AuthController@otpSetup'],
        ['POST', '/admin/otp/clear-emergency', 'admin/AuthController@otpClearEmergency'],

        ['GET', '/admin/dashboard', 'admin/DashboardController@index'],

        ['GET', '/admin/categories', 'admin/CategoryController@index'],
        ['ANY', '/admin/categories/new', 'admin/CategoryController@create'],
        ['ANY', '/admin/categories/<int:cat_id>/edit', 'admin/CategoryController@edit'],
        ['POST', '/admin/categories/<int:cat_id>/delete', 'admin/CategoryController@delete'],
        ['POST', '/admin/categories/bulk', 'admin/CategoryController@bulk'],

        ['GET', '/admin/posts', 'admin/PostController@index'],
        ['POST', '/admin/posts/bulk', 'admin/PostController@bulk'],
        ['ANY', '/admin/posts/new', 'admin/PostController@create'],
        ['ANY', '/admin/posts/<int:post_id>/edit', 'admin/PostController@edit'],
        ['POST', '/admin/posts/<int:post_id>/delete', 'admin/PostController@delete'],
        ['GET', '/admin/posts/<int:post_id>/preview', 'admin/PostController@preview'],
        ['POST', '/admin/posts/preview-content', 'admin/PostController@previewContent'],

        ['GET', '/admin/projects', 'admin/ProjectController@index'],
        ['ANY', '/admin/projects/new', 'admin/ProjectController@create'],
        ['ANY', '/admin/projects/<int:project_id>/edit', 'admin/ProjectController@edit'],
        ['POST', '/admin/projects/<int:project_id>/delete', 'admin/ProjectController@delete'],
        ['POST', '/admin/projects/bulk', 'admin/ProjectController@bulk'],
        ['POST', '/admin/projects/<int:project_id>/sync', 'admin/ProjectController@sync'],
        ['POST', '/admin/projects/sync-all', 'admin/ProjectController@syncAll'],
        ['GET', '/admin/projects/sync-status', 'admin/ProjectController@syncStatus'],

        ['GET', '/admin/links', 'admin/LinkController@index'],
        ['POST', '/admin/links/<int:link_id>/approve', 'admin/LinkController@approve'],
        ['POST', '/admin/links/<int:link_id>/reject', 'admin/LinkController@reject'],
        ['ANY', '/admin/links/new', 'admin/LinkController@create'],
        ['ANY', '/admin/links/<int:link_id>/edit', 'admin/LinkController@edit'],
        ['POST', '/admin/links/<int:link_id>/delete', 'admin/LinkController@delete'],
        ['POST', '/admin/links/bulk', 'admin/LinkController@bulk'],

        ['GET', '/admin/timeline', 'admin/TimelineController@index'],
        ['ANY', '/admin/timeline/new', 'admin/TimelineController@create'],
        ['ANY', '/admin/timeline/<int:item_id>/edit', 'admin/TimelineController@edit'],
        ['POST', '/admin/timeline/<int:item_id>/delete', 'admin/TimelineController@delete'],
        ['POST', '/admin/timeline/bulk', 'admin/TimelineController@bulk'],

        ['GET', '/admin/comments', 'admin/CommentController@index'],
        ['POST', '/admin/comments/<int:comment_id>/approve', 'admin/CommentController@approve'],
        ['POST', '/admin/comments/<int:comment_id>/delete', 'admin/CommentController@delete'],
        ['POST', '/admin/comments/bulk', 'admin/CommentController@bulk'],

        ['POST', '/admin/upload/image', 'admin/UploadController@image'],
        ['POST', '/admin/upload/media', 'admin/UploadController@media'],
        ['POST', '/admin/upload/file', 'admin/UploadController@file'],
        ['GET', '/admin/orphans', 'admin/OrphanController@index'],
        ['POST', '/admin/orphans/clean', 'admin/OrphanController@clean'],

        ['GET', '/admin/status', 'admin/StatusController@index'],
        ['POST', '/admin/status', 'admin/StatusController@save'],
        ['POST', '/admin/status/probe', 'admin/StatusController@probe'],
        ['POST', '/admin/status/test-cloud', 'admin/StatusController@testCloud'],

        ['GET', '/admin/themes', 'admin/ThemeController@index'],
        ['POST', '/admin/themes', 'admin/ThemeController@activate'],

        ['GET', '/admin/settings', 'admin/SettingsController@index'],
        ['POST', '/admin/settings', 'admin/SettingsController@save'],
        ['GET', '/admin/settings/wm-refresh', 'admin/SettingsController@wmRefresh'],
        ['POST', '/admin/settings/test-email', 'admin/SettingsController@testEmail'],

        ['GET', '/admin/export', 'admin/ExportController@index'],
        ['POST', '/admin/export/db-backup', 'admin/ExportController@dbBackup'],
        ['POST', '/admin/export/import-sqlite', 'admin/ExportController@importSqlite'],
        ['POST', '/admin/export/import-sql', 'admin/ExportController@importSql'],
        ['GET', '/admin/export/download/<filename>', 'admin/ExportController@download'],
        ['POST', '/admin/export/delete/<filename>', 'admin/ExportController@delete'],
        ['GET', '/admin/export/json', 'admin/ExportController@json'],
        ['GET', '/admin/export/markdown', 'admin/ExportController@markdown'],

        ['GET', '/admin/upgrade', 'admin/UpgradeController@index'],
        ['POST', '/admin/upgrade', 'admin/UpgradeController@run'],
        ['GET', '/admin/upgrade/check', 'admin/UpgradeController@check'],
    ],
];

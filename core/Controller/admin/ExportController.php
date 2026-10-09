<?php
declare(strict_types=1);

namespace Blog\Controller\admin;

use Blog\Controller\BaseController;
use Blog\Db;
use Blog\Response;
use Blog\Service\Auth;
use Blog\Service\Backup;
use Blog\Service\Flash;
use Blog\Service\SqlImport;
use Blog\Service\SqliteImport;

/**
 * 后台「数据管理」：数据统计、数据库备份、JSON / Markdown 导出。
 * 对照原项目 admin_export / admin_export_db_backup / admin_export_download /
 * admin_export_delete / admin_export_json / admin_export_markdown。
 */
class ExportController extends BaseController
{
    /** GET /admin/export：统计 + 备份列表 */
    public function index(): void
    {
        Auth::requireAdmin();
        $stats = [];
        foreach (['posts', 'projects', 'links', 'comments', 'timeline', 'categories'] as $t) {
            $row = Db::query('SELECT COUNT(*) AS c FROM `' . $t . '`')->fetch();
            $stats[$t] = (int) ($row['c'] ?? 0);
        }
        $row = Db::query('SELECT COUNT(*) AS c FROM settings')->fetch();
        $stats['settings'] = (int) ($row['c'] ?? 0);

        $this->render('admin/export.html', [
            'backups' => Backup::list(),
            'stats' => $stats,
        ]);
    }

    /** POST /admin/export/db-backup：创建数据库 SQL 备份 */
    public function dbBackup(): void
    {
        Auth::requireAdmin();
        $name = Backup::writeDbBackup();
        Flash::success('数据库备份成功：' . $name);
        Response::redirect('/admin/export');
    }

    /**
     * POST /admin/export/import-sqlite：上传 SQLite 库整库导入（覆盖当前数据）。
     * 对照原项目无此功能，为 PHP 版新增：便于从 Python(Flask) 版迁移或灾难恢复。
     * 安全措施：文件预检 → 自动备份当前库 → 执行导入 → 行数校验。
     */
    public function importSqlite(): void
    {
        Auth::requireAdmin();
        $tmp = self::takeUploadFile('db_file', '要导入的 SQLite 数据库文件');
        self::requireConfirm();

        // 预检：非有效 SQLite 或缺表直接拒绝，不触碰现有数据
        $info = SqliteImport::inspect($tmp);
        if (!$info['ok']) {
            Flash::error('导入失败：' . $info['error']);
            Response::redirect('/admin/export');
        }
        $backup = self::backupOrAbort();

        try {
            $r = SqliteImport::run($tmp, isset($_POST['keep_users']));
        } catch (\Throwable $e) {
            Flash::error('导入失败：' . $e->getMessage() . '；可用备份 ' . $backup . ' 恢复');
            Response::redirect('/admin/export');
        }

        $rows = array_sum($r['tables']);
        if ($r['failed']) {
            Flash::error('导入完成但行数校验不一致，请检查数据。导入前备份：' . $backup);
        } else {
            Flash::success('导入成功，共 ' . $rows . ' 行；导入前已自动备份 ' . $backup);
        }
        Response::redirect('/admin/export');
    }

    /**
     * POST /admin/export/import-sql：上传 MySQL SQL 转储整库还原（覆盖当前数据）。
     * 对应「数据库备份」产出的 .sql 文件，也兼容 mysqldump / phpMyAdmin 导出的文件。
     * 安全措施：文件预检 → 自动备份当前库 → 逐条执行 → 汇总语句数与表数。
     */
    public function importSql(): void
    {
        Auth::requireAdmin();
        $tmp = self::takeUploadFile('sql_file', '要导入的 SQL 备份文件');
        self::requireConfirm();

        $info = SqlImport::inspect($tmp);
        if (!$info['ok']) {
            Flash::error('导入失败：' . $info['error']);
            Response::redirect('/admin/export');
        }
        $backup = self::backupOrAbort();

        try {
            $r = SqlImport::run($tmp, isset($_POST['keep_users']));
        } catch (\Throwable $e) {
            Flash::error('导入失败：' . $e->getMessage() . '；可用备份 ' . $backup . ' 恢复');
            Response::redirect('/admin/export');
        }

        $msg = '导入成功：执行 ' . $r['done'] . ' 条语句，覆盖 ' . count($r['tables']) . ' 张表';
        if ($r['added'] > 0) {
            $msg .= '，补入默认配置 ' . $r['added'] . ' 项';
        }
        if ($r['skipped'] > 0) {
            $msg .= '；已跳过 users 表 ' . $r['skipped'] . ' 条语句';
        }
        Flash::success($msg . '；导入前已自动备份 ' . $backup);
        Response::redirect('/admin/export');
    }

    /**
     * 取上传文件并做通用校验（字段、大小上限、临时文件有效性）。
     * 失败时置 Flash 并重定向（内部 exit），成功返回临时路径。
     */
    private static function takeUploadFile(string $field, string $label): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            Flash::error('请选择' . $label);
            Response::redirect('/admin/export');
        }
        // 区分上传错误码：数据库文件常超过 php.ini 默认的 2M 上限，需明确告知
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器上传上限，请调大 php.ini 的 upload_max_filesize 与 post_max_size 后重试',
                UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试',
                UPLOAD_ERR_NO_FILE => '请选择' . $label,
                default => '上传失败（错误码 ' . $err . '），请重试',
            };
            Flash::error($msg);
            Response::redirect('/admin/export');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Flash::error('上传失败，请重试');
            Response::redirect('/admin/export');
        }
        return $tmp;
    }

    /** 覆盖式导入的二次确认（前端 required 可被绕过，后端必须复核） */
    private static function requireConfirm(): void
    {
        if (($_POST['confirm'] ?? '') === '') {
            Flash::error('请勾选「确认覆盖」后再导入');
            Response::redirect('/admin/export');
        }
    }

    /** 导入前自动备份当前库；备份失败即中止（否则覆盖后无法回滚），返回备份文件名 */
    private static function backupOrAbort(): string
    {
        try {
            return Backup::writeDbBackup();
        } catch (\Throwable $e) {
            Flash::error('导入中止：自动备份失败（' . $e->getMessage() . '）');
            Response::redirect('/admin/export');
        }
        return '';
    }

    /** GET /admin/export/download/<filename>：下载备份文件 */
    public function download(string $filename): void
    {
        Auth::requireAdmin();
        $safe = self::safeName($filename);
        $fp = Backup::dir() . '/' . $safe;
        if ($safe === '' || !is_file($fp)) {
            $this->abort404();
        }
        self::sendFile($fp, $safe, 'application/octet-stream');
    }

    /** POST /admin/export/delete/<filename>：删除备份文件 */
    public function delete(string $filename): void
    {
        Auth::requireAdmin();
        $safe = self::safeName($filename);
        $fp = Backup::dir() . '/' . $safe;
        if ($safe !== '' && is_file($fp)) {
            unlink($fp);
            Flash::success('已删除：' . $safe);
        } else {
            Flash::error('文件不存在');
        }
        Response::redirect('/admin/export');
    }

    /** GET /admin/export/json：导出全站数据为 JSON 文件 */
    public function json(): void
    {
        Auth::requireAdmin();
        $data = [];
        foreach (['posts', 'projects', 'links', 'timeline', 'categories'] as $t) {
            $data[$t] = Db::query('SELECT * FROM `' . $t . '` ORDER BY id')->fetchAll();
        }
        $data['comments'] = Db::query(
            'SELECT c.*, p.title AS post_title FROM comments c LEFT JOIN posts p ON c.post_id = p.id ORDER BY c.id'
        )->fetchAll();
        // 排除密码等敏感字段
        $data['settings'] = Db::query("SELECT * FROM settings WHERE `key` NOT LIKE '%password%'")->fetchAll();
        $data['exported_at'] = date('Y-m-d H:i:s');

        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $ts = date('Ymd_His');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="blog_export_' . $ts . '.json"');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }

    /** GET /admin/export/markdown：导出文章为 Markdown 打包 ZIP */
    public function markdown(): void
    {
        Auth::requireAdmin();
        $posts = Db::query("SELECT * FROM posts WHERE status != 'draft' ORDER BY created_at DESC")->fetchAll();
        $catRows = Db::query('SELECT id, name FROM categories')->fetchAll();
        $categories = [];
        foreach ($catRows as $c) {
            $categories[(string) $c['id']] = (string) $c['name'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'blogmd');
        if ($tmp === false) {
            Response::html('临时文件创建失败', 500);
        }
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($posts as $post) {
            $p = (array) $post;
            // --- YAML front matter ---
            $tags = [];
            if (isset($p['tags'])) {
                $decoded = json_decode((string) $p['tags'], true);
                $tags = is_array($decoded) ? $decoded : [];
            }
            $catName = ($p['category_id'] ?? null) ? ($categories[(string) $p['category_id']] ?? '') : '';
            $fm = [
                'title' => (string) ($p['title'] ?? ''),
                'slug' => (string) ($p['slug'] ?? ''),
                'date' => substr((string) ($p['created_at'] ?? ''), 0, 10),
                'status' => (string) ($p['status'] ?? 'published'),
                'tags' => $tags,
                'category' => $catName,
                'read_time' => $p['read_time'] ?? 3,
            ];
            if (!empty($p['cover'])) {
                $fm['cover'] = $p['cover'];
            }
            if (!empty($p['excerpt'])) {
                $fm['excerpt'] = $p['excerpt'];
            }

            $yamlLines = ['---'];
            foreach ($fm as $k => $v) {
                if (is_array($v)) {
                    $yamlLines[] = $k . ':';
                    foreach ($v as $item) {
                        $yamlLines[] = '  - ' . $item;
                    }
                } else {
                    $yamlLines[] = $k . ': ' . $v;
                }
            }
            $yamlLines[] = '---';
            $front = implode("\n", $yamlLines) . "\n\n";

            // slug → 文件名（过滤文件系统非法字符）
            $safeSlug = (string) preg_replace('/[<>:"\/\\\\|?*]/', '-', (string) ($p['slug'] ?? ''));
            if ($safeSlug === '') {
                $safeSlug = 'post-' . ($p['id'] ?? '');
            }
            $zip->addFromString($safeSlug . '.md', $front . (string) ($p['content'] ?? ''));
        }
        $zip->close();

        $ts = date('Ymd_His');
        // sendFile 内部会 exit，注册关停回调清理临时 ZIP
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        });
        self::sendFile($tmp, 'blog_posts_' . $ts . '.zip', 'application/zip');
    }

    /** 文件名安全化（等价 Flask secure_filename 的保守替换） */
    private static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }

    /** 以附件形式输出文件 */
    private static function sendFile(string $path, string $downloadName, string $mime): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }
}
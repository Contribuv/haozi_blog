<?php
declare(strict_types=1);

namespace Blog\Model;

use Blog\Service\Github;

/**
 * GitHub 项目数据模型，对照 db_load_projects / db_get_project / db_save_project / db_delete_project。
 * 注意：projects 表无 updated_at 字段（原库与 MySQL 均无），写入时不得包含该列。
 */
class Project extends Model
{
    /** 全部项目（topics 解码为数组） */
    public static function load(): array
    {
        $rows = self::rows('SELECT * FROM projects ORDER BY sort_order ASC, created_at DESC');
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['stars'] = (int) ($r['stars'] ?? 0);
            $r['sort_order'] = (int) ($r['sort_order'] ?? 0);
            $r['featured'] = (int) ($r['featured'] ?? 0);
            $r['custom_name'] = (int) ($r['custom_name'] ?? 0);
            $r['topics'] = self::jsonList($r['topics'] ?? null);
        }
        return $rows;
    }

    /** 按 id 取项目 */
    public static function get(int $id): ?array
    {
        $row = self::one('SELECT * FROM projects WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['stars'] = (int) ($row['stars'] ?? 0);
        $row['featured'] = (int) ($row['featured'] ?? 0);
        $row['custom_name'] = (int) ($row['custom_name'] ?? 0);
        $row['topics'] = self::jsonList($row['topics'] ?? null);
        return $row;
    }

    /**
     * 保存项目：仅填 GitHub 地址时自动拉取实时数据（stars/语言/标签/描述）。
     * keepName=true 用于「同步」场景，尊重已有自定义名称不覆盖。
     */
    public static function save(array $form, ?int $projectId = null, bool $keepName = false): int
    {
        $customName = trim((string) ($form['name'] ?? ''));
        $inputUrl = (string) ($form['url'] ?? '');
        $gh = Github::fetchRepo($inputUrl);

        if ($gh) {
            $ghName = $gh['name'];
            $description = $gh['description'];
            $url = $gh['url'];
            $stars = (int) $gh['stars'];
            $language = (string) $gh['language'];
            $languages = self::jsonEncode($gh['languages']);
            $topics = self::jsonEncode($gh['topics']);
            $githubRepo = Github::parseRepo($inputUrl) ?? '';
        } else {
            $ghName = null;
            $description = (string) ($form['description'] ?? '');
            $url = $inputUrl;
            $stars = (int) ($form['stars'] ?? 0);
            $language = (string) ($form['language'] ?? '');
            $languages = '';
            $topics = self::jsonEncode(array_values(array_filter(
                array_map('trim', explode(',', (string) ($form['topics'] ?? ''))),
                static fn (string $t): bool => $t !== ''
            )));
            $githubRepo = Github::parseRepo($url) ?? '';
        }

        if ($keepName) {
            $existing = self::one('SELECT name, custom_name FROM projects WHERE id = ?', [$projectId]);
            $name = $existing ? (string) $existing['name'] : ($customName !== '' ? $customName : $ghName);
            $isCustom = $existing ? (int) $existing['custom_name'] : 0;
        } else {
            if ($customName !== '' && $customName !== $ghName) {
                $name = $customName;
                $isCustom = 1;
            } else {
                $name = $customName !== '' ? $customName : $ghName;
                $isCustom = 0;
            }
        }

        $sortOrder = (int) ($form['sort_order'] ?? 0);
        $featured = (($form['featured'] ?? '') === '1') ? 1 : 0;

        if ($projectId !== null) {
            self::exec(
                'UPDATE projects SET name=?, description=?, url=?, stars=?, language=?, languages=?, topics=?, sort_order=?, featured=?, github_repo=?, custom_name=? WHERE id=?',
                [$name, $description, $url, $stars, $language, $languages, $topics, $sortOrder, $featured, $githubRepo, $isCustom, $projectId]
            );
            return $projectId;
        }
        self::exec(
            'INSERT INTO projects (name, description, url, stars, language, languages, topics, sort_order, featured, github_repo, custom_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$name, $description, $url, $stars, $language, $languages, $topics, $sortOrder, $featured, $githubRepo, $isCustom]
        );
        return self::insertId();
    }

    /** GitHub 仓库（owner/repo）是否已存在，用于新增去重 */
    public static function repoExists(string $slug, ?int $excludeId = null): bool
    {
        if ($slug === '') {
            return false;
        }
        if ($excludeId !== null) {
            return self::scalar('SELECT id FROM projects WHERE github_repo = ? AND id != ?', [$slug, $excludeId]) !== null;
        }
        return self::scalar('SELECT id FROM projects WHERE github_repo = ?', [$slug]) !== null;
    }

    /** 删除项目，并清理其归属评论 */
    public static function delete(int $id): void
    {
        self::exec('DELETE FROM projects WHERE id = ?', [$id]);
        self::exec('DELETE FROM comments WHERE project_id = ?', [$id]);
    }
}

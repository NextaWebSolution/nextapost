<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Workspace building blocks: colour labels, signatures (reusable hashtag / call-to-action
 * blocks) and post templates. Every call is scoped to one workspace id.
 */
final class LibraryService
{
    public const COLORS = ['#7C3AED', '#2563EB', '#0891B2', '#059669', '#65A30D', '#CA8A04', '#EA580C', '#DC2626', '#DB2777', '#475569'];

    public function __construct(private DB $db)
    {
    }

    // ---------------- Labels ----------------

    public function labels(int $wsId): array
    {
        return $this->db->all('SELECT id, uuid, name, color FROM labels WHERE workspace_id = ? ORDER BY name', [$wsId]);
    }

    public function label(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM labels WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    /** @return array{0:?array,1:array<string,string>} [label, errors] */
    public function saveLabel(int $wsId, array $in, ?array $existing = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', str_val($in['name'] ?? '')) ?? '');
        $color = strtoupper(trim(str_val($in['color'] ?? ($existing['color'] ?? self::COLORS[0]))));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 40) {
            $errors['name'] = __('Give the label a name of up to 40 characters.');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $errors['color'] = __('Pick a colour.');
        }
        $dupe = $this->db->value('SELECT id FROM labels WHERE workspace_id = ? AND LOWER(name) = LOWER(?) AND id <> ?', [$wsId, $name, (int) ($existing['id'] ?? 0)]);
        if ($dupe) {
            $errors['name'] = __('A label with this name already exists.');
        }
        if ($errors) {
            return [null, $errors];
        }
        if ($existing) {
            $this->db->update('labels', ['name' => $name, 'color' => $color], 'id = ?', [$existing['id']]);
            return [$this->db->one('SELECT * FROM labels WHERE id = ?', [$existing['id']]), []];
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM labels WHERE workspace_id = ?', [$wsId]) >= 100) {
            return [null, ['name' => __('A workspace can have up to 100 labels.')]];
        }
        $id = $this->db->insert('labels', ['uuid' => uuid4(), 'workspace_id' => $wsId, 'name' => $name, 'color' => $color, 'created_at' => now_utc()]);
        return [$this->db->one('SELECT * FROM labels WHERE id = ?', [$id]), []];
    }

    public function deleteLabel(array $label): void
    {
        $this->db->run('DELETE FROM labels WHERE id = ?', [$label['id']]);
    }

    /** Replace a post's labels. Unknown uuids are ignored. */
    public function setPostLabels(int $wsId, int $postId, array $labelUuids): void
    {
        $uuids = array_values(array_unique(array_filter($labelUuids, 'is_string')));
        $this->db->run('DELETE FROM post_labels WHERE post_id = ?', [$postId]);
        if (!$uuids) {
            return;
        }
        $ids = $this->db->all('SELECT id FROM labels WHERE workspace_id = ? AND uuid IN (' . DB::in($uuids) . ')', array_merge([$wsId], $uuids));
        foreach ($ids as $r) {
            $this->db->insert('post_labels', ['post_id' => $postId, 'label_id' => $r['id']]);
        }
    }

    /** @return array<int, list<array{uuid:string,name:string,color:string}>> post id => labels */
    public function labelsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if (!$postIds) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT pl.post_id, l.uuid, l.name, l.color FROM post_labels pl JOIN labels l ON l.id = pl.label_id
                                 WHERE pl.post_id IN (' . DB::in($postIds) . ') ORDER BY l.name', $postIds) as $r) {
            $out[(int) $r['post_id']][] = ['uuid' => $r['uuid'], 'name' => $r['name'], 'color' => $r['color']];
        }
        return $out;
    }

    // ---------------- Signatures ----------------

    public function signatures(int $wsId): array
    {
        return $this->db->all('SELECT id, uuid, name, content, is_default FROM signatures WHERE workspace_id = ? ORDER BY is_default DESC, name', [$wsId]);
    }

    public function signature(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM signatures WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    public function saveSignature(int $wsId, array $in, ?array $existing = null): array
    {
        $name = trim(str_val($in['name'] ?? ''));
        $content = str_replace("\r\n", "\n", trim(str_val($in['content'] ?? '')));
        $default = !empty($in['is_default']) ? 1 : 0;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = __('Give the signature a name of up to 60 characters.');
        }
        if ($content === '' || mb_strlen($content) > 2000) {
            $errors['content'] = __('Write the signature text (up to 2,000 characters).');
        }
        if ($errors) {
            return [null, $errors];
        }
        $id = $this->db->tx(function () use ($wsId, $name, $content, $default, $existing): int {
            if ($default) {
                $this->db->run('UPDATE signatures SET is_default = 0 WHERE workspace_id = ?', [$wsId]);
            }
            if ($existing) {
                $this->db->update('signatures', ['name' => $name, 'content' => $content, 'is_default' => $default, 'updated_at' => now_utc()], 'id = ?', [$existing['id']]);
                return (int) $existing['id'];
            }
            return $this->db->insert('signatures', ['uuid' => uuid4(), 'workspace_id' => $wsId, 'name' => $name, 'content' => $content, 'is_default' => $default, 'created_at' => now_utc()]);
        });
        return [$this->db->one('SELECT * FROM signatures WHERE id = ?', [$id]), []];
    }

    public function deleteSignature(array $sig): void
    {
        $this->db->run('DELETE FROM signatures WHERE id = ?', [$sig['id']]);
    }

    // ---------------- Templates ----------------

    /** Workspace templates plus the user's private ones. */
    public function templates(int $wsId, int $userId): array
    {
        return $this->db->all(
            "SELECT t.id, t.uuid, t.title, t.body, t.visibility, t.user_id, u.name AS author FROM post_templates t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.workspace_id = ? AND (t.visibility = 'workspace' OR t.user_id = ?) ORDER BY t.title",
            [$wsId, $userId]
        );
    }

    public function template(int $wsId, int $userId, string $uuid): ?array
    {
        return $this->db->one("SELECT * FROM post_templates WHERE workspace_id = ? AND uuid = ? AND (visibility = 'workspace' OR user_id = ?)", [$wsId, $uuid, $userId]);
    }

    public function saveTemplate(int $wsId, int $userId, array $in, ?array $existing = null): array
    {
        $title = trim(str_val($in['title'] ?? ''));
        $body = str_replace("\r\n", "\n", trim(str_val($in['body'] ?? '')));
        $vis = ($in['visibility'] ?? 'workspace') === 'private' ? 'private' : 'workspace';
        $errors = [];
        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = __('Give the template a title of up to 120 characters.');
        }
        if ($body === '' || mb_strlen($body) > 20000) {
            $errors['body'] = __('Write the template text.');
        }
        if ($errors) {
            return [null, $errors];
        }
        if ($existing) {
            $this->db->update('post_templates', ['title' => $title, 'body' => $body, 'visibility' => $vis, 'updated_at' => now_utc()], 'id = ?', [$existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert('post_templates', ['uuid' => uuid4(), 'workspace_id' => $wsId, 'user_id' => $userId, 'title' => $title, 'body' => $body, 'visibility' => $vis, 'created_at' => now_utc()]);
        }
        return [$this->db->one('SELECT * FROM post_templates WHERE id = ?', [$id]), []];
    }

    public function deleteTemplate(array $tpl): void
    {
        $this->db->run('DELETE FROM post_templates WHERE id = ?', [$tpl['id']]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Ideas board: columns (stages) of ideas that can be turned into posts. */
final class IdeaService
{
    public const MAX_STAGES = 12;
    public const MAX_IDEAS = 2000;

    public function __construct(private DB $db)
    {
    }

    /** Columns in order; the first visit creates the default three. */
    public function stages(int $wsId): array
    {
        $rows = $this->db->all('SELECT id, uuid, name, position FROM idea_stages WHERE workspace_id = ? ORDER BY position, id', [$wsId]);
        if (!$rows) {
            foreach ([__('Ideas'), __('In progress'), __('Ready to post')] as $i => $name) {
                $this->db->insert('idea_stages', ['uuid' => uuid4(), 'workspace_id' => $wsId, 'name' => $name, 'position' => $i]);
            }
            $rows = $this->db->all('SELECT id, uuid, name, position FROM idea_stages WHERE workspace_id = ? ORDER BY position, id', [$wsId]);
        }
        return $rows;
    }

    public function stage(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM idea_stages WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    /** @return array{0:?array,1:array<string,string>} */
    public function saveStage(int $wsId, array $in, ?array $existing = null): array
    {
        $name = trim(str_val($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            return [null, ['name' => __('Give the column a name of up to 60 characters.')]];
        }
        if ($existing) {
            $this->db->update('idea_stages', ['name' => $name], 'id = ?', [$existing['id']]);
            return [$this->db->one('SELECT * FROM idea_stages WHERE id = ?', [$existing['id']]), []];
        }
        if (count($this->stages($wsId)) >= self::MAX_STAGES) {
            return [null, ['name' => __('A board can have up to {n} columns.', ['n' => self::MAX_STAGES])]];
        }
        $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM idea_stages WHERE workspace_id = ?', [$wsId]);
        $id = $this->db->insert('idea_stages', ['uuid' => uuid4(), 'workspace_id' => $wsId, 'name' => $name, 'position' => $pos]);
        return [$this->db->one('SELECT * FROM idea_stages WHERE id = ?', [$id]), []];
    }

    /** Deleting a column moves its ideas to the first remaining column. */
    public function deleteStage(int $wsId, array $stage): ?string
    {
        $stages = $this->stages($wsId);
        if (count($stages) <= 1) {
            return __('The board needs at least one column.');
        }
        $target = $stages[0]['id'] == $stage['id'] ? $stages[1]['id'] : $stages[0]['id'];
        $this->db->tx(function () use ($stage, $target): void {
            $this->db->run('UPDATE ideas SET stage_id = ? WHERE stage_id = ?', [$target, $stage['id']]);
            $this->db->run('DELETE FROM idea_stages WHERE id = ?', [$stage['id']]);
        });
        return null;
    }

    public function reorderStages(int $wsId, array $uuids): void
    {
        foreach (array_values(array_filter($uuids, 'is_string')) as $i => $u) {
            $this->db->run('UPDATE idea_stages SET position = ? WHERE workspace_id = ? AND uuid = ?', [$i, $wsId, $u]);
        }
    }

    public function find(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM ideas WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    /** Ideas with labels, stage and author, newest position order within each stage. */
    /** One idea with labels, stage and author. */
    public function get(int $wsId, int $id): ?array
    {
        return $this->list($wsId, null, null, 1, 0, $id)[0] ?? null;
    }

    public function list(int $wsId, ?int $stageId = null, ?string $search = null, int $limit = 500, int $offset = 0, ?int $id = null): array
    {
        $sql = 'SELECT i.*, s.uuid AS stage_uuid, s.name AS stage_name, u.name AS user_name, p.uuid AS post_uuid
                FROM ideas i LEFT JOIN idea_stages s ON s.id = i.stage_id LEFT JOIN users u ON u.id = i.user_id LEFT JOIN posts p ON p.id = i.post_id AND p.deleted_at IS NULL
                WHERE i.workspace_id = ?';
        $args = [$wsId];
        if ($stageId !== null) {
            $sql .= ' AND i.stage_id = ?';
            $args[] = $stageId;
        }
        if ($id !== null) {
            $sql .= ' AND i.id = ?';
            $args[] = $id;
        }
        if ($search !== null && $search !== '') {
            $sql .= " AND (i.title LIKE ? ESCAPE '!' OR i.body LIKE ? ESCAPE '!')";
            $like = like_pattern($search);
            array_push($args, $like, $like);
        }
        $rows = $this->db->all($sql . ' ORDER BY s.position, i.position, i.id LIMIT ' . max(1, min(2000, $limit)) . ' OFFSET ' . max(0, $offset), $args);
        if (!$rows) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $labels = [];
        foreach ($this->db->all('SELECT il.idea_id, l.uuid, l.name, l.color FROM idea_labels il JOIN labels l ON l.id = il.label_id WHERE il.idea_id IN (' . DB::in($ids) . ')', $ids) as $l) {
            $labels[(int) $l['idea_id']][] = ['uuid' => $l['uuid'], 'name' => $l['name'], 'color' => $l['color']];
        }
        foreach ($rows as &$r) {
            $r['labels'] = $labels[(int) $r['id']] ?? [];
            $r['media'] = json_decode((string) ($r['media'] ?? ''), true) ?: [];
        }
        return $rows;
    }

    /** @return array{0:?array,1:array<string,string>} */
    public function save(array $ws, array $user, array $in, ?array $existing = null): array
    {
        $wsId = (int) $ws['id'];
        $errors = [];
        $title = trim(str_val($in['title'] ?? ($existing['title'] ?? '')));
        $body = str_replace("\r\n", "\n", trim(str_val($in['body'] ?? ($existing['body'] ?? ''))));
        $link = trim(str_val($in['link'] ?? ($existing['link'] ?? '')));
        if ($title === '' && $body === '') {
            $errors['title'] = __('Write a title or some notes for the idea.');
        }
        if (mb_strlen($title) > 200) {
            $errors['title'] = __('Titles can be up to 200 characters.');
        }
        if (mb_strlen($body) > 20000) {
            $errors['body'] = __('Notes can be up to 20,000 characters.');
        }
        if ($link !== '' && (!preg_match('#^https?://\S+$#i', $link) || mb_strlen($link) > 700)) {
            $errors['link'] = __('Enter a full link starting with https://.');
        }
        $stageId = $existing['stage_id'] ?? null;
        if (!empty($in['stage'])) {
            $st = $this->stage($wsId, str_val($in['stage']));
            if (!$st) {
                $errors['stage'] = __('That column no longer exists.');
            } else {
                $stageId = (int) $st['id'];
            }
        }
        $stageId ??= (int) $this->stages($wsId)[0]['id'];
        $media = $existing ? (json_decode((string) ($existing['media'] ?? ''), true) ?: []) : [];
        if (array_key_exists('media', $in)) {
            $uuids = array_values(array_unique(array_filter((array) $in['media'], 'is_string')));
            $found = $uuids ? array_column($this->db->all('SELECT uuid FROM media WHERE workspace_id = ? AND uuid IN (' . DB::in($uuids) . ')', array_merge([$wsId], $uuids)), 'uuid') : [];
            if (count($found) !== count($uuids)) {
                $errors['media'] = __('One of the files was deleted.');
            }
            $media = array_slice($uuids, 0, 20);
        }
        if (!$existing && (int) $this->db->value('SELECT COUNT(*) FROM ideas WHERE workspace_id = ?', [$wsId]) >= self::MAX_IDEAS) {
            $errors['title'] = __('A workspace can keep up to {n} ideas. Delete some old ones first.', ['n' => number_format(self::MAX_IDEAS)]);
        }
        if ($errors) {
            return [null, $errors];
        }
        $data = ['title' => $title ?: null, 'body' => $body ?: null, 'link' => $link ?: null, 'media' => $media ? json_encode($media) : null, 'stage_id' => $stageId, 'updated_at' => now_utc()];
        if ($existing) {
            if ((int) $existing['stage_id'] !== $stageId) {
                $data['position'] = (int) $this->db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM ideas WHERE stage_id = ?', [$stageId]);
            }
            $this->db->update('ideas', $data, 'id = ?', [$existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert('ideas', $data + [
                'uuid' => uuid4(), 'workspace_id' => $wsId, 'user_id' => $user['id'] ?? null, 'source' => in_array($in['source'] ?? 'web', ['web', 'api', 'mcp', 'rss'], true) ? ($in['source'] ?? 'web') : 'web',
                'position' => (int) $this->db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM ideas WHERE stage_id = ?', [$stageId]), 'created_at' => now_utc(),
            ]);
        }
        if (array_key_exists('labels', $in)) {
            $this->db->run('DELETE FROM idea_labels WHERE idea_id = ?', [$id]);
            foreach (array_slice(array_unique(array_filter((array) $in['labels'], 'is_string')), 0, 20) as $lu) {
                if ($l = (new LibraryService($this->db))->label($wsId, $lu)) {
                    $this->db->insert('idea_labels', ['idea_id' => $id, 'label_id' => $l['id']]);
                }
            }
        }
        return [$this->get($wsId, $id), []];
    }

    /** Move to a column at a position (0 = top); other cards shift. */
    public function move(int $wsId, array $idea, string $stageUuid, int $position): ?string
    {
        $stage = $this->stage($wsId, $stageUuid);
        if (!$stage) {
            return __('That column no longer exists.');
        }
        $this->db->tx(function () use ($idea, $stage, $position): void {
            $ids = array_map('intval', array_column($this->db->all('SELECT id FROM ideas WHERE stage_id = ? AND id <> ? ORDER BY position, id', [$stage['id'], $idea['id']]), 'id'));
            array_splice($ids, max(0, min($position, count($ids))), 0, [(int) $idea['id']]);
            foreach ($ids as $i => $iid) {
                $this->db->run('UPDATE ideas SET position = ?, stage_id = ? WHERE id = ?', [$i, $stage['id'], $iid]);
            }
        });
        return null;
    }

    public function delete(array $idea): void
    {
        $this->db->run('DELETE FROM ideas WHERE id = ?', [$idea['id']]);
    }

    /** Text for the composer: title, notes and link. */
    public static function postText(array $idea): string
    {
        $parts = array_filter([trim((string) $idea['title']), trim((string) $idea['body']), trim((string) $idea['link'])], 'strlen');
        return implode("\n\n", array_unique($parts));
    }

    public function linkPost(array $idea, int $postId): void
    {
        $this->db->update('ideas', ['post_id' => $postId, 'updated_at' => now_utc()], 'id = ?', [$idea['id']]);
        $this->db->update('posts', ['idea_id' => $idea['id']], 'id = ?', [$postId]);
    }

    public static function present(array $i): array
    {
        return [
            'id' => $i['uuid'], 'title' => $i['title'], 'body' => $i['body'], 'link' => $i['link'],
            'stage' => isset($i['stage_uuid']) ? ['id' => $i['stage_uuid'], 'name' => $i['stage_name']] : null,
            'labels' => array_map(fn ($l) => ['id' => $l['uuid'], 'name' => $l['name'], 'color' => $l['color']], $i['labels'] ?? []),
            'media' => $i['media'] ?? [], 'author' => $i['user_name'] ?? null, 'source' => $i['source'],
            'post_id' => $i['post_uuid'] ?? null, 'created_at' => Presenter::iso($i['created_at']), 'updated_at' => Presenter::iso($i['updated_at'] ?? null),
        ];
    }
}

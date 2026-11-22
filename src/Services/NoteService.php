<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Team notes on a post. Mentions are workspace members picked in the editor (their uuids);
 * anyone else is ignored. Mentioned people, the author and earlier note writers get an email.
 */
final class NoteService
{
    public const MAX = 5000;

    public function __construct(private DB $db)
    {
    }

    public function list(int $postId): array
    {
        $rows = $this->db->all(
            'SELECT n.id, n.uuid, n.body, n.mentions, n.created_at, n.edited_at, n.user_id, u.uuid AS user_uuid, u.name AS user_name
             FROM post_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.post_id = ? ORDER BY n.created_at, n.id',
            [$postId]
        );
        foreach ($rows as &$r) {
            $r['mentions'] = json_decode((string) $r['mentions'], true) ?: [];
        }
        return $rows;
    }

    public function find(int $postId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM post_notes WHERE post_id = ? AND uuid = ?', [$postId, $uuid]);
    }

    /** Members who can be mentioned. */
    public function members(int $wsId): array
    {
        return $this->db->all(
            'SELECT u.id, u.uuid, u.name, u.email, u.locale, u.notify_json, u.is_active, u.deleted_at FROM workspace_members m JOIN users u ON u.id = m.user_id
             WHERE m.workspace_id = ? AND u.deleted_at IS NULL ORDER BY u.name',
            [$wsId]
        );
    }

    /** @return array{0:?array,1:?string} [note, error] */
    public function add(array $ws, array $post, array $user, string $body, array $mentionUuids = []): array
    {
        $body = str_replace("\r\n", "\n", trim($body));
        if ($body === '') {
            return [null, __('Write a note first.')];
        }
        if (mb_strlen($body) > self::MAX) {
            return [null, __('Notes can be up to {n} characters.', ['n' => number_format(self::MAX)])];
        }
        $members = [];
        foreach ($this->members((int) $ws['id']) as $m) {
            $members[$m['uuid']] = $m;
        }
        $mentioned = array_values(array_intersect(array_unique(array_filter($mentionUuids, 'is_string')), array_keys($members)));
        $id = $this->db->insert('post_notes', [
            'uuid' => uuid4(), 'post_id' => $post['id'], 'user_id' => $user['id'], 'body' => $body,
            'mentions' => $mentioned ? json_encode($mentioned) : null, 'created_at' => now_utc(),
        ]);
        log_activity((int) $ws['id'], 'post.note', __('Note on: {title}', ['title' => str_val($post['title'])]), 'post', (int) $post['id']);
        $this->notify($ws, $post, $user, $body, $mentioned, $members);
        return [$this->db->one('SELECT * FROM post_notes WHERE id = ?', [$id]), null];
    }

    /** Only the writer can edit their note. */
    public function edit(array $note, array $user, string $body): ?string
    {
        if ((int) $note['user_id'] !== (int) $user['id']) {
            return __('You can only edit your own notes.');
        }
        $body = str_replace("\r\n", "\n", trim($body));
        if ($body === '' || mb_strlen($body) > self::MAX) {
            return __('Notes can be up to {n} characters.', ['n' => number_format(self::MAX)]);
        }
        $this->db->update('post_notes', ['body' => $body, 'edited_at' => now_utc()], 'id = ?', [$note['id']]);
        return null;
    }

    /** The writer or a workspace admin can delete a note. */
    public function delete(array $note, array $user, string $role): ?string
    {
        if ((int) $note['user_id'] !== (int) $user['id'] && $role !== 'admin') {
            return __('Only the writer or a workspace admin can delete this note.');
        }
        $this->db->run('DELETE FROM post_notes WHERE id = ?', [$note['id']]);
        return null;
    }

    private function notify(array $ws, array $post, array $author, string $body, array $mentioned, array $members): void
    {
        $byId = [];
        foreach ($members as $m) {
            $byId[(int) $m['id']] = $m;
        }
        $link = url('/w/' . $ws['uuid'] . '/posts/' . $post['uuid'] . '/edit') . '#notes';
        $title = str_val($post['title'] ?: 'Untitled post');
        $notifier = new Notifier($this->db);
        $sent = [(int) $author['id'] => true];
        foreach ($mentioned as $uuid) {
            $m = $members[$uuid];
            if (isset($sent[(int) $m['id']])) {
                continue;
            }
            $sent[(int) $m['id']] = true;
            $notifier->email($m, 'mention', ['{name} mentioned you on a post', ['name' => $author['name']]], [
                'heading' => ['{name} mentioned you', ['name' => $author['name']]],
                'lines' => [['On the post “{title}” in {workspace}:', ['title' => $title, 'workspace' => $ws['name']]]],
                'quote' => mb_substr($body, 0, 1500), 'link' => $link, 'button' => 'Open the post',
            ]);
        }
        // The post's author and earlier note writers follow the conversation.
        $followers = array_map('intval', array_column($this->db->all('SELECT DISTINCT user_id FROM post_notes WHERE post_id = ? AND user_id IS NOT NULL', [$post['id']]), 'user_id'));
        $followers[] = (int) $post['author_id'];
        foreach (array_unique($followers) as $uid) {
            if (isset($sent[$uid]) || !isset($byId[$uid])) {
                continue;
            }
            $sent[$uid] = true;
            $notifier->email($byId[$uid], 'note', ['New note on “{title}”', ['title' => mb_substr($title, 0, 60)]], [
                'heading' => ['{name} added a note', ['name' => $author['name']]],
                'lines' => [['On the post “{title}” in {workspace}:', ['title' => $title, 'workspace' => $ws['name']]]],
                'quote' => mb_substr($body, 0, 1500), 'link' => $link, 'button' => 'Open the post',
            ]);
        }
    }
}

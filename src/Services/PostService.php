<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Gate;
use App\Providers\ProviderFactory;

final class PostService
{
    /** Statuses in which a post can still be edited. */
    public const EDITABLE = ['draft', 'in_review', 'scheduled', 'failed'];
    public const MOVABLE = ['draft', 'in_review', 'scheduled'];

    public function __construct(private DB $db)
    {
    }

    public function find(int $workspaceId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM posts WHERE workspace_id = ? AND uuid = ? AND deleted_at IS NULL', [$workspaceId, $uuid]);
    }

    public function targets(int $postId): array
    {
        return $this->db->all(
            'SELECT t.*, c.uuid AS channel_uuid, c.network, c.name, c.username, c.avatar_url, c.status AS channel_status
             FROM post_targets t JOIN channels c ON c.id = t.channel_id WHERE t.post_id = ? ORDER BY t.id',
            [$postId]
        );
    }

    /** Media for one target: its own list when customised, otherwise the post's shared media. */
    public function targetMedia(array $target): array
    {
        if ((int) ($target['media_custom'] ?? 0) === 1) {
            return $this->db->all('SELECT m.* FROM target_media tm JOIN media m ON m.id = tm.media_id WHERE tm.target_id = ? ORDER BY tm.sort_order', [$target['id']]);
        }
        return $this->media((int) $target['post_id']);
    }

    public function media(int $postId): array
    {
        return $this->db->all('SELECT m.* FROM post_media pm JOIN media m ON m.id = pm.media_id WHERE pm.post_id = ? ORDER BY pm.sort_order', [$postId]);
    }

    /** @return array<string, array> active channels in the workspace keyed by uuid, in the given order */
    public function channelsByUuid(int $workspaceId, array $uuids): array
    {
        if (!$uuids) {
            return [];
        }
        $rows = $this->db->all('SELECT * FROM channels WHERE workspace_id = ? AND uuid IN (' . DB::in($uuids) . ')', [$workspaceId, ...$uuids]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r['uuid']] = $r;
        }
        $out = [];
        foreach ($uuids as $u) {
            if (isset($byId[$u])) {
                $out[$u] = $byId[$u];
            }
        }
        return $out;
    }

    /** @return array[] media rows in the given order */
    public function mediaByUuid(int $workspaceId, array $uuids): array
    {
        if (!$uuids) {
            return [];
        }
        $rows = $this->db->all('SELECT * FROM media WHERE workspace_id = ? AND uuid IN (' . DB::in($uuids) . ')', [$workspaceId, ...$uuids]);
        $byId = array_column($rows, null, 'uuid');
        return array_values(array_filter(array_map(static fn ($u) => $byId[$u] ?? null, $uuids)));
    }

    /**
     * Create or update a post from composer input.
     *
     * $in: action (draft|review|schedule|now), body, channels[uuid], overrides{uuid:text}, media[uuid], publish_at (local), version
     * @return array{errors: array<string,string[]>, post: ?array, publish_now: bool}
     */
    public function save(array $ws, array $user, string $role, array $in, ?array $existing = null): array
    {
        $errors = [];
        $add = static function (string $key, string $msg) use (&$errors): void {
            $errors[$key][] = $msg;
        };
        $action = in_array($in['action'] ?? '', ['draft', 'review', 'schedule', 'now'], true) ? $in['action'] : 'draft';
        $body = str_replace("\r\n", "\n", trim((string) ($in['body'] ?? '')));
        if (mb_strlen($body) > 65000) {
            $add('body', 'The text is too long.');
        }
        $chIds = array_values(array_unique(array_filter((array) ($in['channels'] ?? []), 'is_string')));
        $channels = $this->channelsByUuid((int) $ws['id'], $chIds);
        if (count($channels) !== count($chIds)) {
            $add('general', 'One of the selected channels was removed. Refresh the page and try again.');
        }
        $overrides = [];
        foreach ((array) ($in['overrides'] ?? []) as $k => $v) {
            if (is_string($k) && is_string($v) && isset($channels[$k])) {
                $overrides[$k] = str_replace("\r\n", "\n", trim($v));
            }
        }
        $mediaIds = array_values(array_unique(array_filter((array) ($in['media'] ?? []), 'is_string')));
        $media = $this->mediaByUuid((int) $ws['id'], $mediaIds);
        if (count($media) !== count($mediaIds)) {
            $add('general', 'One of the attached files was deleted. Remove it and try again.');
        }
        // Different media for a channel (e.g. a PDF for LinkedIn, image slides for Instagram).
        $mediaOverrides = [];
        foreach ((array) ($in['media_overrides'] ?? []) as $chUuid => $list) {
            if (!is_string($chUuid) || !isset($channels[$chUuid]) || !is_array($list)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter($list, 'is_string')));
            $rows = $this->mediaByUuid((int) $ws['id'], $ids);
            if (count($rows) !== count($ids)) {
                $add($chUuid, 'One of the files chosen for ' . $channels[$chUuid]['name'] . ' was deleted. Remove it and try again.');
            }
            $mediaOverrides[$chUuid] = $rows;
        }

        if ($existing) {
            if (!in_array($existing['status'], self::EDITABLE, true)) {
                $add('general', 'This post is ' . strtolower(status_label($existing['status'])) . ' and can’t be edited any more. Duplicate it instead.');
            }
            if (isset($in['version']) && (int) $in['version'] !== (int) $existing['version']) {
                $add('general', 'Someone else changed this post while you were editing. Reload the page to get the latest version.');
            }
        }

        $publishAt = null;
        $raw = trim((string) ($in['publish_at'] ?? ''));
        if ($action === 'now') {
            $publishAt = now_utc();
        } elseif ($raw !== '') {
            $publishAt = to_utc($raw, (string) $ws['timezone']);
            if ($publishAt === null) {
                $add('publish_at', 'Pick a valid date and time.');
            }
        }
        if (in_array($action, ['schedule', 'review'], true) && $publishAt === null && !isset($errors['publish_at'])) {
            $add('publish_at', 'Pick a date and time to publish.');
        }
        if ($action === 'schedule' && $publishAt !== null && $publishAt < now_utc(-60)) {
            $add('publish_at', 'That time has already passed. Pick a future time or use “Post now”.');
        }

        if ($action !== 'draft') {
            if (!$channels) {
                $add('channels', 'Select at least one channel.');
            }
            foreach ($channels as $uuid => $ch) {
                $items = array_map([MediaService::class, 'toItem'], $mediaOverrides[$uuid] ?? $media);
                if ($ch['status'] !== 'active') {
                    $add($uuid, $ch['name'] . ' needs to be reconnected before you can post to it.');
                    continue;
                }
                $meta = json_decode((string) ($ch['meta_json'] ?? ''), true) ?: [];
                foreach (ProviderFactory::make($ch['network'])->validate($overrides[$uuid] ?? $body, $items, $meta) as $msg) {
                    $add($uuid, $msg);
                }
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'post' => null, 'publish_now' => false];
        }

        $status = match ($action) {
            'draft' => 'draft',
            'review' => 'in_review',
            default => Gate::can($role, 'publish', $ws) ? 'scheduled' : 'in_review',
        };
        $publishNow = $action === 'now' && $status === 'scheduled';
        $now = now_utc();
        $titleSource = $body !== '' ? $body : (string) (reset($overrides) ?: '');
        $title = str_excerpt($titleSource, 120) ?: 'Untitled post';

        $postId = $this->db->tx(function () use ($ws, $user, $existing, $status, $publishAt, $body, $title, $channels, $overrides, $media, $mediaOverrides, $now, $action): int {
            $data = ['title' => $title, 'body' => $body, 'status' => $status, 'publish_at' => $publishAt, 'updated_at' => $now];
            if ($action !== 'draft') {
                $data['review_note'] = null;
            }
            if ($existing) {
                $data['version'] = (int) $existing['version'] + 1;
                $this->db->update('posts', $data, 'id = ?', [$existing['id']]);
                $pid = (int) $existing['id'];
                $this->db->run('DELETE FROM post_targets WHERE post_id = ?', [$pid]);
                $this->db->run('DELETE FROM post_media WHERE post_id = ?', [$pid]);
            } else {
                $pid = $this->db->insert('posts', $data + [
                    'uuid' => uuid4(), 'workspace_id' => $ws['id'], 'author_id' => $user['id'], 'version' => 1, 'source' => 'web', 'created_at' => $now,
                ]);
            }
            foreach ($channels as $uuid => $ch) {
                $tid = $this->db->insert('post_targets', [
                    'post_id' => $pid, 'channel_id' => $ch['id'], 'body_override' => $overrides[$uuid] ?? null,
                    'status' => 'pending', 'publish_at' => $publishAt, 'attempts' => 0, 'media_custom' => isset($mediaOverrides[$uuid]) ? 1 : 0,
                ]);
                foreach ($mediaOverrides[$uuid] ?? [] as $i => $m) {
                    $this->db->insert('target_media', ['target_id' => $tid, 'media_id' => $m['id'], 'sort_order' => $i]);
                }
            }
            foreach ($media as $i => $m) {
                $this->db->insert('post_media', ['post_id' => $pid, 'media_id' => $m['id'], 'sort_order' => $i]);
            }
            return $pid;
        });

        $post = $this->db->one('SELECT * FROM posts WHERE id = ?', [$postId]);
        log_activity((int) $ws['id'], $existing ? 'post.updated' : 'post.created', status_label($status) . ': ' . $title, 'post', $postId);
        return ['errors' => [], 'post' => $post, 'publish_now' => $publishNow];
    }

    /** @return string|null error message */
    public function move(array $ws, array $post, string $localDateTime, ?int $version = null): ?string
    {
        if (!in_array($post['status'], self::MOVABLE, true)) {
            return 'Only drafts, posts in review and scheduled posts can be moved.';
        }
        if ($version !== null && $version !== (int) $post['version']) {
            return 'This post changed since the calendar loaded. Refresh and try again.';
        }
        $utc = to_utc($localDateTime, (string) $ws['timezone']);
        if ($utc === null) {
            return 'That date isn’t valid.';
        }
        if ($post['status'] !== 'draft' && $utc < now_utc(-60)) {
            return 'You can’t move a post into the past.';
        }
        $this->db->tx(function () use ($post, $utc): void {
            $this->db->run('UPDATE posts SET publish_at = ?, version = version + 1, updated_at = ? WHERE id = ?', [$utc, now_utc(), $post['id']]);
            $this->db->run("UPDATE post_targets SET publish_at = ? WHERE post_id = ? AND status = 'pending'", [$utc, $post['id']]);
        });
        log_activity((int) $ws['id'], 'post.moved', 'Moved to ' . to_local($utc, (string) $ws['timezone'], 'd M Y H:i') . ': ' . $post['title'], 'post', (int) $post['id']);
        return null;
    }

    /** @return string|null error */
    public function approve(array $ws, array $post): ?string
    {
        if ($post['status'] !== 'in_review') {
            return 'This post isn’t waiting for review.';
        }
        if (!$post['publish_at']) {
            return 'Set a publish time before approving.';
        }
        $publishAt = max((string) $post['publish_at'], now_utc());
        $this->db->tx(function () use ($post, $publishAt): void {
            $this->db->run("UPDATE posts SET status = 'scheduled', publish_at = ?, review_note = NULL, version = version + 1, updated_at = ? WHERE id = ?", [$publishAt, now_utc(), $post['id']]);
            $this->db->run("UPDATE post_targets SET publish_at = ? WHERE post_id = ? AND status = 'pending'", [$publishAt, $post['id']]);
        });
        log_activity((int) $ws['id'], 'post.approved', 'Approved: ' . $post['title'], 'post', (int) $post['id']);
        return null;
    }

    public function reject(array $ws, array $post, string $note): ?string
    {
        if ($post['status'] !== 'in_review') {
            return 'This post isn’t waiting for review.';
        }
        $this->db->run("UPDATE posts SET status = 'draft', review_note = ?, version = version + 1, updated_at = ? WHERE id = ?", [mb_substr(trim($note) ?: 'Changes requested.', 0, 2000), now_utc(), $post['id']]);
        log_activity((int) $ws['id'], 'post.rejected', 'Changes requested: ' . $post['title'], 'post', (int) $post['id']);
        return null;
    }

    public function delete(array $ws, array $post): ?string
    {
        if ($post['status'] === 'publishing') {
            return 'This post is being published right now. Try again in a minute.';
        }
        $this->db->tx(function () use ($post): void {
            $this->db->run("UPDATE posts SET deleted_at = ?, status = CASE WHEN status IN ('published','partially_published') THEN status ELSE 'cancelled' END WHERE id = ?", [now_utc(), $post['id']]);
            $this->db->run("UPDATE post_targets SET status = 'cancelled' WHERE post_id = ? AND status IN ('pending','queued')", [$post['id']]);
        });
        log_activity((int) $ws['id'], 'post.deleted', 'Deleted: ' . $post['title'], 'post', (int) $post['id']);
        return null;
    }

    /** Re-queue failed targets for immediate publishing. */
    public function retry(array $ws, array $post): ?string
    {
        if (!in_array($post['status'], ['failed', 'partially_published'], true)) {
            return 'Only failed posts can be retried.';
        }
        $n = $this->db->run("UPDATE post_targets SET status = 'pending', publish_at = ?, last_error = NULL, attempts = 0 WHERE post_id = ? AND status = 'failed'", [now_utc(), $post['id']])->rowCount();
        if ($n === 0) {
            return 'There’s nothing to retry.';
        }
        $this->db->run("UPDATE posts SET status = 'scheduled', version = version + 1, updated_at = ? WHERE id = ?", [now_utc(), $post['id']]);
        log_activity((int) $ws['id'], 'post.retried', 'Retry: ' . $post['title'], 'post', (int) $post['id']);
        return null;
    }

    public function duplicate(array $ws, array $user, array $post): array
    {
        return $this->db->tx(function () use ($ws, $user, $post): array {
            $now = now_utc();
            $id = $this->db->insert('posts', [
                'uuid' => uuid4(), 'workspace_id' => $ws['id'], 'author_id' => $user['id'], 'title' => $post['title'],
                'body' => $post['body'], 'status' => 'draft', 'publish_at' => null, 'version' => 1, 'source' => 'web',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($this->db->all('SELECT id, channel_id, body_override, media_custom FROM post_targets WHERE post_id = ?', [$post['id']]) as $t) {
                $tid = $this->db->insert('post_targets', ['post_id' => $id, 'channel_id' => $t['channel_id'], 'body_override' => $t['body_override'], 'status' => 'pending', 'attempts' => 0, 'media_custom' => (int) $t['media_custom']]);
                foreach ($this->db->all('SELECT media_id, sort_order FROM target_media WHERE target_id = ?', [$t['id']]) as $tm) {
                    $this->db->insert('target_media', ['target_id' => $tid, 'media_id' => $tm['media_id'], 'sort_order' => $tm['sort_order']]);
                }
            }
            foreach ($this->db->all('SELECT media_id, sort_order FROM post_media WHERE post_id = ?', [$post['id']]) as $m) {
                $this->db->insert('post_media', ['post_id' => $id, 'media_id' => $m['media_id'], 'sort_order' => $m['sort_order']]);
            }
            return (array) $this->db->one('SELECT * FROM posts WHERE id = ?', [$id]);
        });
    }

    /** Events for the calendar between two local dates (inclusive start, exclusive end). */
    public function calendar(array $ws, string $startLocal, string $endLocal): array
    {
        $tz = (string) $ws['timezone'];
        $from = to_utc($startLocal . ' 00:00:00', $tz);
        $to = to_utc($endLocal . ' 00:00:00', $tz);
        if (!$from || !$to) {
            return [];
        }
        $posts = $this->db->all(
            'SELECT id, uuid, title, body, status, publish_at, version FROM posts
             WHERE workspace_id = ? AND deleted_at IS NULL AND publish_at >= ? AND publish_at < ? ORDER BY publish_at, id LIMIT 2000',
            [$ws['id'], $from, $to]
        );
        return $this->decorate($posts, $tz);
    }

    /** Drafts without a date (the "Ideas" panel). */
    public function unscheduled(array $ws, int $limit = 50): array
    {
        $posts = $this->db->all(
            "SELECT id, uuid, title, body, status, publish_at, version FROM posts
             WHERE workspace_id = ? AND deleted_at IS NULL AND publish_at IS NULL AND status = 'draft' ORDER BY updated_at DESC, id DESC LIMIT " . (int) $limit,
            [$ws['id']]
        );
        return $this->decorate($posts, (string) $ws['timezone']);
    }

    public function decorate(array $posts, string $tz): array
    {
        if (!$posts) {
            return [];
        }
        $ids = array_map('intval', array_column($posts, 'id'));
        $channels = [];
        foreach ($this->db->all(
            'SELECT t.post_id, t.status, t.external_url, c.uuid, c.network, c.name, c.avatar_url FROM post_targets t
             JOIN channels c ON c.id = t.channel_id WHERE t.post_id IN (' . DB::in($ids) . ') ORDER BY t.id',
            $ids
        ) as $r) {
            $meta = ProviderFactory::meta($r['network']);
            $channels[(int) $r['post_id']][] = ['id' => $r['uuid'], 'network' => $r['network'], 'name' => $r['name'], 'avatar' => safe_url($r['avatar_url']),
                'color' => $meta['color'], 'letter' => $meta['letter'], 'status' => $r['status'], 'url' => safe_url($r['external_url'])];
        }
        $thumbs = [];
        foreach ($this->db->all(
            'SELECT pm.post_id, m.* FROM post_media pm JOIN media m ON m.id = pm.media_id
             WHERE pm.post_id IN (' . DB::in($ids) . ') ORDER BY pm.sort_order',
            $ids
        ) as $m) {
            if (!isset($thumbs[(int) $m['post_id']])) {
                $thumbs[(int) $m['post_id']] = MediaService::toJson($m)['thumb'];
            }
        }
        $missing = array_values(array_diff($ids, array_keys($thumbs)));
        if ($missing) {
            foreach ($this->db->all(
                'SELECT t.post_id, m.* FROM post_targets t JOIN target_media tm ON tm.target_id = t.id JOIN media m ON m.id = tm.media_id
                 WHERE t.post_id IN (' . DB::in($missing) . ') ORDER BY tm.sort_order',
                $missing
            ) as $m) {
                $thumbs[(int) $m['post_id']] ??= MediaService::toJson($m)['thumb'];
            }
        }
        $out = [];
        foreach ($posts as $p) {
            $local = $p['publish_at'] ? to_local($p['publish_at'], $tz, 'Y-m-d H:i') : '';
            $out[] = [
                'id' => $p['uuid'], 'title' => $p['title'] ?: 'Untitled post', 'excerpt' => str_excerpt($p['body'] ?? '', 140),
                'status' => $p['status'], 'statusLabel' => status_label($p['status']), 'version' => (int) $p['version'],
                'date' => $local ? substr($local, 0, 10) : null, 'time' => $local ? substr($local, 11, 5) : null,
                'channels' => $channels[(int) $p['id']] ?? [], 'thumb' => $thumbs[(int) $p['id']] ?? null,
                'movable' => in_array($p['status'], self::MOVABLE, true),
            ];
        }
        return $out;
    }
}

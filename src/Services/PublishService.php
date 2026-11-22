<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;

final class PublishService
{
    public function __construct(private DB $db, private Queue $queue)
    {
    }

    /** Turn due targets (publish_at within the next minute) into queued jobs. */
    public function enqueueDue(): int
    {
        $rows = $this->db->all(
            "SELECT t.id, t.publish_at FROM post_targets t JOIN posts p ON p.id = t.post_id
             WHERE t.status = 'pending' AND p.status IN ('scheduled','publishing','partially_published','failed') AND p.deleted_at IS NULL
               AND t.publish_at IS NOT NULL AND t.publish_at <= ? ORDER BY t.publish_at LIMIT 500",
            [now_utc(60)]
        );
        $n = 0;
        foreach ($rows as $r) {
            $key = 'publish:' . $r['id'] . ':' . strtotime((string) $r['publish_at'] . ' UTC');
            $this->queue->push('publish', ['target_id' => (int) $r['id']], $key, (string) $r['publish_at']);
            if ($this->db->run("UPDATE post_targets SET status = 'queued' WHERE id = ? AND status = 'pending'", [$r['id']])->rowCount() === 1) {
                $n++;
            }
        }
        return $n;
    }

    public function handleJob(array $job): void
    {
        match ($job['type']) {
            'publish' => $this->publishTarget((int) ($job['payload']['target_id'] ?? 0)),
            default => throw new \RuntimeException('Unknown job type ' . $job['type']),
        };
    }

    /** Publish all pending targets of a post immediately (used by "Post now"). */
    public function publishPostNow(int $postId): void
    {
        foreach ($this->db->all("SELECT id FROM post_targets WHERE post_id = ? AND status = 'pending'", [$postId]) as $t) {
            $this->db->run("UPDATE post_targets SET status = 'queued' WHERE id = ? AND status = 'pending'", [$t['id']]);
            try {
                $this->publishTarget((int) $t['id']);
            } catch (ProviderException $e) {
                // Temporary problem: let the cron worker retry shortly.
                $this->queue->push('publish', ['target_id' => (int) $t['id']], 'publish:' . $t['id'] . ':retry:' . time(), now_utc(60));
            }
        }
    }

    /**
     * Publishes one target. Permanent failures mark the target failed. Temporary failures put the
     * target back to "queued" and rethrow the ProviderException so the worker can retry the job.
     */
    public function publishTarget(int $targetId): void
    {
        $claimed = $this->db->run("UPDATE post_targets SET status = 'publishing', attempts = attempts + 1 WHERE id = ? AND status IN ('queued','pending')", [$targetId])->rowCount();
        if ($claimed !== 1) {
            return; // Already handled by another worker, or cancelled.
        }
        $t = $this->db->one(
            'SELECT t.*, p.body AS post_body, p.status AS post_status, p.deleted_at AS post_deleted, p.workspace_id, p.title AS post_title,
                    c.network, c.credentials_enc, c.meta_json, c.status AS channel_status, c.name AS channel_name, c.id AS ch_id
             FROM post_targets t JOIN posts p ON p.id = t.post_id JOIN channels c ON c.id = t.channel_id WHERE t.id = ?',
            [$targetId]
        );
        if (!$t) {
            return;
        }
        $postId = (int) $t['post_id'];
        if ($t['post_deleted'] !== null || in_array($t['post_status'], ['cancelled', 'draft', 'in_review'], true)) {
            $this->db->update('post_targets', ['status' => 'cancelled'], 'id = ?', [$targetId]);
            $this->refreshPostStatus($postId);
            return;
        }
        $this->db->run("UPDATE posts SET status = 'publishing' WHERE id = ? AND status = 'scheduled'", [$postId]);

        $start = microtime(true);
        try {
            if ($t['channel_status'] !== 'active') {
                throw new ProviderException($t['channel_name'] . ' is disconnected. Reconnect it in Channels, then retry.', false, true);
            }
            $provider = ProviderFactory::make((string) $t['network']);
            $creds = Crypto::decryptJson((string) $t['credentials_enc']);
            $meta = json_decode((string) ($t['meta_json'] ?? ''), true) ?: [];
            $refreshed = $provider->refresh($creds, ProviderFactory::appConfig((string) $t['network']));
            if ($refreshed !== null) {
                $creds = $refreshed + $creds;
                $this->db->update('channels', ['credentials_enc' => Crypto::encryptJson($creds), 'updated_at' => now_utc()], 'id = ?', [$t['ch_id']]);
            }
            $media = array_map([MediaService::class, 'toItem'], (new PostService($this->db))->targetMedia($t));
            foreach ($media as $m) {
                if (!is_file($m['path'])) {
                    throw new ProviderException('An attached file is missing from the server. Edit the post and attach it again.');
                }
            }
            $text = $t['body_override'] !== null ? (string) $t['body_override'] : (string) $t['post_body'];
            $result = $provider->publish($creds, $text, $media, $meta, [
                'idempotency_key' => 'np-' . $targetId . '-' . $t['attempts'],
                'content_type' => $t['content_type'] ?? null,
                'options' => json_decode((string) ($t['options_json'] ?? ''), true) ?: [],
                'title' => (string) ($t['post_title'] ?? ''),
            ]);
            $this->db->update('post_targets', [
                'status' => 'published', 'external_id' => mb_substr($result->externalId, 0, 190), 'external_url' => safe_url($result->url) !== null ? mb_substr((string) $result->url, 0, 700) : null,
                'published_at' => now_utc(), 'last_error' => null,
            ], 'id = ?', [$targetId]);
            $this->providerLog((int) $t['ch_id'], (string) $t['network'], 'publish', true, 'Published ' . $result->externalId, $start);
            $this->db->update('channels', ['last_error' => null], 'id = ?', [$t['ch_id']]);
        } catch (ProviderException $e) {
            $this->providerLog((int) $t['ch_id'], (string) $t['network'], 'publish', false, $e->getMessage(), $start);
            if ($e->authError) {
                $this->db->update('channels', ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now_utc()], 'id = ?', [$t['ch_id']]);
            }
            if ($e->retryable) {
                $this->db->update('post_targets', ['status' => 'queued', 'last_error' => mb_substr($e->getMessage(), 0, 2000)], 'id = ?', [$targetId]);
                $this->refreshPostStatus($postId);
                throw $e;
            }
            $this->markTargetFailed($targetId, $e->getMessage());
            return;
        } catch (\Throwable $e) {
            Logger::error($e);
            $this->providerLog((int) $t['ch_id'], (string) $t['network'], 'publish', false, $e->getMessage(), $start);
            $this->markTargetFailed($targetId, 'Unexpected error: ' . $e->getMessage());
            return;
        }
        $this->refreshPostStatus($postId);
    }

    public function markTargetFailed(int $targetId, string $error): void
    {
        $this->db->run("UPDATE post_targets SET status = 'failed', last_error = ? WHERE id = ? AND status IN ('queued','publishing','pending')", [mb_substr($error, 0, 2000), $targetId]);
        $postId = (int) $this->db->value('SELECT post_id FROM post_targets WHERE id = ?', [$targetId]);
        if ($postId) {
            $this->refreshPostStatus($postId);
        }
    }

    public function refreshPostStatus(int $postId): string
    {
        $counts = ['pending' => 0, 'queued' => 0, 'publishing' => 0, 'published' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM post_targets WHERE post_id = ? GROUP BY status', [$postId]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $post = $this->db->one('SELECT status, deleted_at, workspace_id, title FROM posts WHERE id = ?', [$postId]);
        if (!$post || in_array($post['status'], ['draft', 'in_review', 'cancelled'], true)) {
            return str_val($post['status'] ?? '');
        }
        $active = $counts['pending'] + $counts['queued'] + $counts['publishing'];
        $status = match (true) {
            $active > 0 && ($counts['published'] + $counts['failed']) > 0 => 'publishing',
            $active > 0 => $post['status'] === 'publishing' ? 'publishing' : 'scheduled',
            $counts['published'] > 0 && $counts['failed'] > 0 => 'partially_published',
            $counts['published'] > 0 => 'published',
            $counts['failed'] > 0 => 'failed',
            default => 'cancelled',
        };
        if ($status !== $post['status']) {
            $this->db->update('posts', ['status' => $status, 'updated_at' => now_utc()], 'id = ?', [$postId]);
            if (in_array($status, ['published', 'failed', 'partially_published'], true)) {
                log_activity((int) $post['workspace_id'], 'post.' . $status, status_label($status) . ': ' . $post['title'], 'post', $postId);
                Events::emit($this->db, (int) $post['workspace_id'], $status === 'failed' ? 'post.failed' : 'post.published', $postId);
                if ($status !== 'published') {
                    $this->notifyFailure($postId);
                }
            }
        }
        return $status;
    }

    private function notifyFailure(int $postId): void
    {
        $p = $this->db->one('SELECT p.*, w.uuid AS ws_uuid, w.name AS ws_name FROM posts p JOIN workspaces w ON w.id = p.workspace_id WHERE p.id = ?', [$postId]);
        $author = $p ? $this->db->one('SELECT * FROM users WHERE id = ?', [$p['author_id']]) : null;
        if (!$author) {
            return;
        }
        $errors = array_column($this->db->all("SELECT c.name, t.last_error FROM post_targets t JOIN channels c ON c.id = t.channel_id WHERE t.post_id = ? AND t.status = 'failed'", [$postId]), 'last_error', 'name');
        $lines = [['Workspace: {workspace}', ['workspace' => $p['ws_name']]]];
        foreach ($errors as $name => $err) {
            $lines[] = ['{channel}: {error}', ['channel' => $name, 'error' => mb_substr((string) $err, 0, 300)]];
        }
        (new Notifier($this->db))->email($author, 'failed', ['Couldn’t publish: “{title}”', ['title' => mb_substr((string) $p['title'], 0, 60)]], [
            'heading' => 'A post didn’t publish everywhere', 'lines' => $lines,
            'link' => url('/w/' . $p['ws_uuid'] . '/posts/' . $p['uuid'] . '/edit'), 'button' => 'Open the post',
        ]);
    }

    private function providerLog(int $channelId, string $network, string $action, bool $ok, string $message, float $start): void
    {
        Logger::write($ok ? 'info' : 'warning', sprintf('[%s] %s channel=%d ok=%s %dms %s', $network, $action, $channelId, $ok ? 'yes' : 'no', (int) ((microtime(true) - $start) * 1000), mb_substr($message, 0, 500)));
    }
}

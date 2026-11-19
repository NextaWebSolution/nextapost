<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Automatic storage clean-up per workspace (off by default).
 *
 * Follows what hosted schedulers do: the post stays live on the social network, only NextaPost's
 * local copy is removed. Drafts, posts in review, scheduled and publishing posts — and any file
 * still attached to one of them — are never touched.
 *
 *  - media_retention_days:   delete images/videos of finished posts (published, failed, cancelled) older than N days.
 *                            The post text, status and links are kept.
 *  - post_retention_days:    delete finished posts older than N days completely, with files no other post uses.
 *  - library_retention_days: delete library uploads never attached to any post, older than N days.
 */
final class RetentionService
{
    public const MEDIA_OPTIONS = [0 => 'Never', 30 => 'After 30 days', 60 => 'After 60 days', 90 => 'After 90 days', 180 => 'After 6 months', 365 => 'After 1 year'];
    public const POST_OPTIONS = [0 => 'Never', 90 => 'After 90 days', 180 => 'After 6 months', 365 => 'After 1 year', 730 => 'After 2 years'];
    public const LIBRARY_OPTIONS = [0 => 'Never', 90 => 'After 90 days', 180 => 'After 6 months', 365 => 'After 1 year'];
    private const FINISHED = "('published','partially_published','failed','cancelled')";
    private const ACTIVE = "('draft','in_review','scheduled','publishing')";

    public function __construct(private DB $db)
    {
    }

    /** @return array{media_files:int, media_bytes:int, posts:int} */
    public function run(array $ws, bool $dryRun = false): array
    {
        $wsId = (int) $ws['id'];
        $out = ['media_files' => 0, 'media_bytes' => 0, 'posts' => 0];
        $deleteMedia = [];
        $postsToDelete = [];
        $postsLosingMedia = [];

        // Files still needed by an active post are always protected.
        $protected = array_map('intval', array_column($this->db->all(
            'SELECT pm.media_id FROM post_media pm JOIN posts p ON p.id = pm.post_id
             WHERE p.workspace_id = ? AND p.deleted_at IS NULL AND p.status IN ' . self::ACTIVE . '
             UNION SELECT tm.media_id FROM target_media tm JOIN post_targets t ON t.id = tm.target_id JOIN posts p ON p.id = t.post_id
             WHERE p.workspace_id = ? AND p.deleted_at IS NULL AND p.status IN ' . self::ACTIVE,
            [$wsId, $wsId]
        ), 'media_id'));

        // 1) Whole post history.
        $postDays = (int) ($ws['post_retention_days'] ?? 0);
        if ($postDays > 0) {
            $cutoff = now_utc(-$postDays * 86400);
            $postsToDelete = array_map('intval', array_column($this->db->all(
                'SELECT id FROM posts WHERE workspace_id = ? AND status IN ' . self::FINISHED . ' AND COALESCE(publish_at, updated_at, created_at) < ?',
                [$wsId, $cutoff]
            ), 'id'));
        }
        // Soft-deleted posts are purged 30 days after deletion regardless of settings.
        $postsToDelete = array_values(array_unique(array_merge($postsToDelete, array_map('intval', array_column($this->db->all(
            'SELECT id FROM posts WHERE workspace_id = ? AND deleted_at IS NOT NULL AND deleted_at < ? AND status IN ' . self::FINISHED,
            [$wsId, now_utc(-30 * 86400)]
        ), 'id')))));

        // 2) Media of finished posts older than the media cutoff (or of posts being deleted).
        $mediaDays = (int) ($ws['media_retention_days'] ?? 0);
        $candidatePosts = $postsToDelete;
        if ($mediaDays > 0) {
            $cut = now_utc(-$mediaDays * 86400);
            $postsLosingMedia = array_map('intval', array_column($this->db->all(
                'SELECT p.id FROM posts p JOIN post_media pm ON pm.post_id = p.id
                 WHERE p.workspace_id = ? AND p.status IN ' . self::FINISHED . ' AND COALESCE(p.publish_at, p.updated_at, p.created_at) < ?
                 UNION SELECT p.id FROM posts p JOIN post_targets t ON t.post_id = p.id JOIN target_media tm ON tm.target_id = t.id
                 WHERE p.workspace_id = ? AND p.status IN ' . self::FINISHED . ' AND COALESCE(p.publish_at, p.updated_at, p.created_at) < ?',
                [$wsId, $cut, $wsId, $cut]
            ), 'id'));
            $candidatePosts = array_values(array_unique(array_merge($candidatePosts, $postsLosingMedia)));
        }
        if ($candidatePosts) {
            $in = DB::in($candidatePosts);
            $rows = $this->db->all(
                'SELECT m.* FROM media m WHERE m.workspace_id = ? AND m.id IN (
                    SELECT pm.media_id FROM post_media pm WHERE pm.post_id IN (' . $in . ')
                    UNION SELECT tm.media_id FROM target_media tm JOIN post_targets t ON t.id = tm.target_id WHERE t.post_id IN (' . $in . '))',
                [$wsId, ...$candidatePosts, ...$candidatePosts]
            );
            foreach ($rows as $m) {
                if (in_array((int) $m['id'], $protected, true)) {
                    continue;
                }
                // A file shared with a finished post that is NOT being cleaned yet is kept for now.
                $others = (int) $this->db->value(
                    'SELECT COUNT(*) FROM post_media WHERE media_id = ? AND post_id NOT IN (' . $in . ')',
                    [$m['id'], ...$candidatePosts]
                ) + (int) $this->db->value(
                    'SELECT COUNT(*) FROM target_media tm JOIN post_targets t ON t.id = tm.target_id WHERE tm.media_id = ? AND t.post_id NOT IN (' . $in . ')',
                    [$m['id'], ...$candidatePosts]
                );
                if ($others === 0) {
                    $deleteMedia[(int) $m['id']] = $m;
                }
            }
        }

        // 3) Library files never attached to a post.
        $libDays = (int) ($ws['library_retention_days'] ?? 0);
        if ($libDays > 0) {
            foreach ($this->db->all(
                'SELECT m.* FROM media m WHERE m.workspace_id = ? AND m.created_at < ? AND NOT EXISTS (SELECT 1 FROM post_media pm WHERE pm.media_id = m.id)
                 AND NOT EXISTS (SELECT 1 FROM target_media tm WHERE tm.media_id = m.id)',
                [$wsId, now_utc(-$libDays * 86400)]
            ) as $m) {
                $deleteMedia[(int) $m['id']] = $m;
            }
        }

        $out['media_files'] = count($deleteMedia);
        $out['media_bytes'] = array_sum(array_map(static fn ($m) => (int) $m['size_bytes'], $deleteMedia));
        $out['posts'] = count($postsToDelete);
        if ($dryRun || (!$deleteMedia && !$postsToDelete)) {
            return $out;
        }

        $mediaSvc = new MediaService($this->db);
        if ($postsLosingMedia) {
            $this->db->run('UPDATE posts SET media_removed_at = ? WHERE media_removed_at IS NULL AND id IN (' . DB::in($postsLosingMedia) . ')', [now_utc(), ...$postsLosingMedia]);
        }
        foreach ($deleteMedia as $m) {
            $mediaSvc->delete($m);
        }
        foreach (array_chunk($postsToDelete, 200) as $chunk) {
            $this->db->run('DELETE FROM posts WHERE workspace_id = ? AND id IN (' . DB::in($chunk) . ')', [$wsId, ...$chunk]);
        }
        log_activity($wsId, 'storage.cleanup', sprintf('Automatic clean-up removed %d file(s) (%s) and %d old post(s)', $out['media_files'], human_bytes($out['media_bytes']), $out['posts']));
        return $out;
    }

    /** Daily job: every workspace with a rule, plus purge of workspaces deleted more than 30 days ago. */
    public function runAll(): array
    {
        $total = ['media_files' => 0, 'media_bytes' => 0, 'posts' => 0, 'workspaces_purged' => 0];
        foreach ($this->db->all('SELECT * FROM workspaces WHERE deleted_at IS NULL') as $ws) {
            foreach ($this->run($ws) as $k => $v) {
                $total[$k] += $v;
            }
        }
        foreach ($this->db->all('SELECT * FROM workspaces WHERE deleted_at IS NOT NULL AND deleted_at < ?', [now_utc(-30 * 86400)]) as $ws) {
            $this->purgeWorkspace($ws);
            $total['workspaces_purged']++;
        }
        return $total;
    }

    /** Permanently removes a workspace, its posts, channels (and their encrypted tokens) and its media files. */
    public function purgeWorkspace(array $ws): void
    {
        $this->db->run('DELETE FROM workspaces WHERE id = ?', [$ws['id']]);
        if (preg_match('/^[0-9a-f-]{36}$/', (string) $ws['uuid'])) {
            self::removeTree(APP_ROOT . '/public/media/' . $ws['uuid']);
            self::removeTree(APP_ROOT . '/storage/documents/' . $ws['uuid']);
        }
    }

    private static function removeTree(string $dir): void
    {
        $real = realpath($dir);
        $ok = false;
        foreach ([APP_ROOT . '/public/media', APP_ROOT . '/storage/documents'] as $r) {
            $root = realpath($r);
            if ($root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                $ok = true;
            }
        }
        if (!$ok) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}

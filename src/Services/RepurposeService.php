<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;

/**
 * Repurpose rules: watch an Instagram or Threads account and turn each new post (e.g. a Reel)
 * into a draft, a queued post or a post for review on other channels. Checked about hourly by the worker.
 */
final class RepurposeService
{
    public const SOURCES = ['instagram', 'instagram_direct', 'threads'];
    public const FORMATS = ['reel' => 'Reels and videos', 'post' => 'Photo posts', 'any' => 'Everything'];
    public const MODES = ['draft' => 'Save as drafts', 'queue' => 'Add to each channel’s queue', 'review' => 'Send for review (queued)'];
    public const INTERVAL = 3600;
    public const MAX_RULES = 20;

    public function __construct(private DB $db)
    {
    }

    public function rules(int $wsId): array
    {
        return $this->db->all(
            "SELECT r.*, c.uuid AS source_uuid, c.name AS source_name, c.network AS source_network,
                    (SELECT COUNT(*) FROM repurpose_items i WHERE i.repurpose_id = r.id AND i.status = 'done') AS made
             FROM repurposes r LEFT JOIN channels c ON c.id = r.source_channel_id WHERE r.workspace_id = ? ORDER BY r.created_at DESC",
            [$wsId]
        );
    }

    public function rule(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM repurposes WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    /** @return array{0:?array,1:array<string,string>} */
    public function create(array $ws, array $user, array $in): array
    {
        $errors = [];
        $wsId = (int) $ws['id'];
        $src = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$wsId, str_val($in['source'] ?? '')]);
        if (!$src || !in_array($src['network'], self::SOURCES, true)) {
            $errors['source'] = __('Choose an Instagram or Threads channel to watch.');
        }
        $format = str_val($in['format'] ?? 'reel');
        if (!isset(self::FORMATS[$format])) {
            $errors['format'] = __('Choose what to repurpose.');
        }
        $mode = str_val($in['mode'] ?? 'draft');
        if (!isset(self::MODES[$mode])) {
            $errors['mode'] = __('Choose what happens with new posts.');
        }
        $dest = [];
        foreach (array_unique(array_filter((array) ($in['destinations'] ?? []), 'is_string')) as $u) {
            $c = $this->db->one('SELECT id, uuid FROM channels WHERE workspace_id = ? AND uuid = ?', [$wsId, $u]);
            if ($c && (!$src || (int) $c['id'] !== (int) $src['id'])) {
                $dest[] = $c['uuid'];
            }
        }
        if (!$dest) {
            $errors['destinations'] = __('Pick at least one channel to post to.');
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM repurposes WHERE workspace_id = ?', [$wsId]) >= self::MAX_RULES) {
            $errors['source'] = __('A workspace can have up to {n} repurpose rules.', ['n' => self::MAX_RULES]);
        }
        if ($errors) {
            return [null, $errors];
        }
        $id = $this->db->insert('repurposes', [
            'uuid' => uuid4(), 'workspace_id' => $wsId, 'user_id' => $user['id'], 'source_channel_id' => $src['id'], 'source_format' => $format,
            'destinations' => json_encode($dest), 'mode' => $mode, 'status' => 'active', 'activated_at' => now_utc(), 'next_poll_at' => now_utc(), 'created_at' => now_utc(),
        ]);
        log_activity($wsId, 'repurpose.created', __('Repurpose rule created for {name}', ['name' => $src['name']]));
        return [$this->db->one('SELECT * FROM repurposes WHERE id = ?', [$id]), []];
    }

    /** Pause, or resume (resuming only picks up posts made from now on). */
    public function setStatus(array $rule, string $status): void
    {
        $data = ['status' => $status === 'paused' ? 'paused' : 'active', 'paused_reason' => null];
        if ($data['status'] === 'active') {
            $data += ['activated_at' => now_utc(), 'next_poll_at' => now_utc(), 'last_error' => null];
        }
        $this->db->update('repurposes', $data, 'id = ?', [$rule['id']]);
    }

    public function delete(array $rule): void
    {
        $this->db->run('DELETE FROM repurposes WHERE id = ?', [$rule['id']]);
    }

    private function matches(string $format, array $item): bool
    {
        if (!$item['media']) {
            return false;
        }
        return match ($format) {
            'reel' => in_array($item['kind'], ['reel', 'video'], true),
            'post' => $item['kind'] === 'post',
            default => true,
        };
    }

    /** Download a source file into the library. */
    private function download(array $ws, int $userId, string $url, string $type, string $name): ?array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'nprp');
        try {
            $r = Http::request('GET', $url, ['sink' => $tmp, 'public_only' => true, 'timeout' => 300, 'max_bytes' => MediaService::maxBytes()]);
            if ($r['status'] !== 200) {
                return null;
            }
            $m = (new MediaService($this->db))->upload($ws, $userId, ['tmp_name' => $tmp, 'name' => $name . ($type === 'video' ? '.mp4' : '.jpg'), 'error' => UPLOAD_ERR_OK], true);
            $this->db->update('media', ['source' => 'repurpose'], 'id = ?', [$m['id']]);
            return $m;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /** Check one rule now. @return int posts created */
    public function poll(array $rule): int
    {
        $ws = $this->db->one('SELECT * FROM workspaces WHERE id = ? AND deleted_at IS NULL', [$rule['workspace_id']]);
        $src = $rule['source_channel_id'] ? $this->db->one('SELECT * FROM channels WHERE id = ?', [$rule['source_channel_id']]) : null;
        $user = $rule['user_id'] ? $this->db->one('SELECT u.*, m.role FROM users u JOIN workspace_members m ON m.user_id = u.id AND m.workspace_id = ? WHERE u.id = ? AND u.deleted_at IS NULL AND u.is_active = 1', [$rule['workspace_id'], $rule['user_id']]) : null;
        $pause = function (string $reason, string $msg) use ($rule): int {
            $this->db->update('repurposes', ['status' => 'paused', 'paused_reason' => $reason, 'last_error' => mb_substr($msg, 0, 500), 'last_polled_at' => now_utc()], 'id = ?', [$rule['id']]);
            return 0;
        };
        if (!$ws || !$src || !$user) {
            return $pause('missing', __('The source channel or the person who made this rule was removed.'));
        }
        if ($src['status'] !== 'active') {
            return $pause('reconnect', __('{name} needs to be reconnected.', ['name' => $src['name']]));
        }
        $provider = ProviderFactory::make((string) $src['network']);
        try {
            $creds = Crypto::decryptJson((string) $src['credentials_enc']);
            if ($fresh = $provider->refresh($creds, ProviderFactory::appConfig((string) $src['network']))) {
                $creds = $fresh + $creds;
                $this->db->update('channels', ['credentials_enc' => Crypto::encryptJson($creds)], 'id = ?', [$src['id']]);
            }
            $recent = method_exists($provider, 'recentMedia') ? $provider->recentMedia($creds, json_decode((string) $src['meta_json'], true) ?: []) : [];
        } catch (ProviderException $e) {
            if ($e->authError) {
                return $pause('reconnect', $e->getMessage());
            }
            $this->db->update('repurposes', ['last_error' => mb_substr($e->getMessage(), 0, 500), 'last_polled_at' => now_utc(), 'next_poll_at' => now_utc(self::INTERVAL)], 'id = ?', [$rule['id']]);
            return 0;
        }
        $made = 0;
        $dest = json_decode((string) $rule['destinations'], true) ?: [];
        $svc = new PostService($this->db);
        foreach (array_reverse($recent) as $item) {
            // Only posts published after the rule was turned on, each one once.
            if (($item['at'] ?? null) === null || $item['at'] < $rule['activated_at'] || !$this->matches((string) $rule['source_format'], $item)) {
                continue;
            }
            if ($this->db->value('SELECT 1 FROM repurpose_items WHERE repurpose_id = ? AND source_id = ?', [$rule['id'], $item['id']])) {
                continue;
            }
            $itemId = $this->db->insert('repurpose_items', [
                'repurpose_id' => $rule['id'], 'source_id' => $item['id'], 'status' => 'pending', 'caption' => $item['caption'],
                'media_url' => mb_substr((string) ($item['media'][0]['url'] ?? ''), 0, 1500), 'created_at' => now_utc(),
            ]);
            $media = [];
            foreach (array_slice($item['media'], 0, 10) as $n => $mf) {
                $m = $this->download($ws, (int) $user['id'], $mf['url'], $mf['type'], 'repurpose-' . preg_replace('/\W/', '', $item['id']) . '-' . $n);
                if ($m) {
                    $media[] = $m['uuid'];
                }
            }
            if (!$media) {
                $this->db->update('repurpose_items', ['status' => 'failed', 'reason' => 'download'], 'id = ?', [$itemId]);
                continue;
            }
            $res = $svc->save($ws, $user, (string) $user['role'], [
                'action' => $rule['mode'] === 'draft' ? 'draft' : ($rule['mode'] === 'review' ? 'review' : 'schedule'),
                'schedule_mode' => $rule['mode'] === 'draft' ? 'custom' : 'queue', 'body' => $item['caption'], 'channels' => $dest, 'media' => $media, 'source' => 'repurpose',
            ]);
            if ($res['errors'] && $rule['mode'] !== 'draft') {
                // Can't be queued (e.g. a channel has no posting times): keep it as a draft so nothing is lost.
                $res = $svc->save($ws, $user, (string) $user['role'], ['action' => 'draft', 'body' => $item['caption'], 'channels' => $dest, 'media' => $media, 'source' => 'repurpose']);
            }
            if ($res['errors']) {
                $this->db->update('repurpose_items', ['status' => 'failed', 'reason' => 'invalid'], 'id = ?', [$itemId]);
                continue;
            }
            $this->db->update('repurpose_items', ['status' => 'done', 'post_id' => $res['post']['id']], 'id = ?', [$itemId]);
            $made++;
        }
        $this->db->update('repurposes', ['last_polled_at' => now_utc(), 'next_poll_at' => now_utc(self::INTERVAL), 'last_error' => null], 'id = ?', [$rule['id']]);
        return $made;
    }

    /** Called by the cron worker. */
    public function pollDue(int $limit = 2): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT * FROM repurposes WHERE status = 'active' AND (next_poll_at IS NULL OR next_poll_at <= ?) ORDER BY next_poll_at LIMIT " . max(1, $limit), [now_utc()]) as $r) {
            try {
                $n += $this->poll($r);
            } catch (\Throwable $e) {
                Logger::write('warning', 'Repurpose check failed: ' . $e->getMessage(), ['rule' => $r['uuid']]);
                $this->db->update('repurposes', ['next_poll_at' => now_utc(self::INTERVAL), 'last_error' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [$r['id']]);
            }
        }
        return $n;
    }

    public function present(array $r, int $wsId): array
    {
        $dest = json_decode((string) $r['destinations'], true) ?: [];
        $names = $dest ? $this->db->all('SELECT uuid, name FROM channels WHERE workspace_id = ? AND uuid IN (' . DB::in($dest) . ')', array_merge([$wsId], $dest)) : [];
        return [
            'id' => $r['uuid'], 'source' => $r['source_uuid'] ? ['id' => $r['source_uuid'], 'name' => $r['source_name'], 'network' => $r['source_network']] : null,
            'format' => $r['source_format'], 'mode' => $r['mode'], 'status' => $r['status'], 'paused_reason' => $r['paused_reason'],
            'destinations' => array_map(fn ($c) => ['id' => $c['uuid'], 'name' => $c['name']], $names),
            'posts_made' => (int) ($r['made'] ?? 0), 'last_checked_at' => Presenter::iso($r['last_polled_at']), 'error' => $r['last_error'],
            'created_at' => Presenter::iso($r['created_at']),
        ];
    }
}

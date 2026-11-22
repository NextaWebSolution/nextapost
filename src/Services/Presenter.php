<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Providers\ProviderFactory;

/**
 * One JSON shape for posts, channels and media, shared by the REST API, the MCP server and
 * webhooks. Times are ISO 8601 UTC. Never includes credentials or internal ids.
 */
final class Presenter
{
    public function __construct(private DB $db)
    {
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? str_replace(' ', 'T', substr($utc, 0, 19)) . 'Z' : null;
    }

    public function channel(array $c): array
    {
        $meta = json_decode((string) ($c['meta_json'] ?? ''), true) ?: [];
        return [
            'id' => $c['uuid'], 'network' => $c['network'], 'network_name' => ProviderFactory::make($c['network'])->meta()['name'] ?? $c['network'],
            'name' => $c['name'], 'username' => $c['username'] ?: null, 'status' => $c['status'],
            'timezone' => $c['timezone'] ?? null, 'posting_goal' => isset($c['posting_goal']) ? (int) $c['posting_goal'] ?: null : null,
            'has_schedule' => ScheduleService::slotCount(ScheduleService::decode($c['posting_schedule'] ?? null)) > 0,
            'avatar_url' => safe_url((string) ($c['avatar_url'] ?? '')) ?: null,
            'kind' => $meta['kind'] ?? null,
        ];
    }

    public function media(array $m): array
    {
        return [
            'id' => $m['uuid'], 'type' => $m['type'], 'mime' => $m['mime'], 'name' => $m['original_name'],
            'size_bytes' => (int) $m['size_bytes'], 'width' => $m['width'] ? (int) $m['width'] : null, 'height' => $m['height'] ? (int) $m['height'] : null,
            'pages' => isset($m['pages']) && $m['pages'] ? (int) $m['pages'] : null, 'alt_text' => $m['alt_text'] ?: null,
            'url' => $m['type'] === 'document' ? null : abs_url((string) $m['path']), 'source' => $m['source'] ?? 'upload', 'attribution' => json_decode((string) ($m['attribution'] ?? ''), true) ?: null,
            'created_at' => self::iso($m['created_at']),
        ];
    }

    /** Full post with targets, media, labels. */
    public function post(array $p, array $wsRow): array
    {
        $svc = new PostService($this->db);
        $targets = [];
        foreach ($svc->targets((int) $p['id']) as $t) {
            $metrics = $this->db->one('SELECT likes, comments, shares, views, impressions, fetched_at FROM post_metrics WHERE target_id = ?', [$t['id']]);
            $targets[] = [
                'channel' => ['id' => $t['channel_uuid'], 'network' => $t['network'], 'name' => $t['name']],
                'status' => $t['status'], 'publish_at' => self::iso($t['publish_at']), 'published_at' => self::iso($t['published_at'] ?? null),
                'text' => $t['body_override'] ?? null, 'content_type' => $t['content_type'] ?? null,
                'options' => json_decode((string) ($t['options_json'] ?? ''), true) ?: (object) [],
                'media' => (int) ($t['media_custom'] ?? 0) ? array_map([$this, 'media'], $svc->targetMedia($t)) : null,
                'url' => safe_url((string) ($t['external_url'] ?? '')) ?: null, 'error' => $t['last_error'] ?: null,
                'metrics' => $metrics ? array_map(fn ($v) => is_numeric($v) ? (int) $v : self::iso($v), $metrics) : null,
            ];
        }
        $labels = (new LibraryService($this->db))->labelsForPosts([(int) $p['id']])[(int) $p['id']] ?? [];
        $author = $this->db->one('SELECT uuid, name FROM users WHERE id = ?', [$p['author_id']]);
        return [
            'id' => $p['uuid'], 'status' => $p['status'], 'text' => (string) $p['body'], 'title' => $p['title'],
            'publish_at' => self::iso($p['publish_at']), 'timezone' => $wsRow['timezone'],
            'publish_at_local' => $p['publish_at'] ? to_local((string) $p['publish_at'], (string) $wsRow['timezone'], 'Y-m-d\TH:i') : null,
            'schedule_mode' => $p['schedule_mode'] ?? 'custom',
            'recurrence' => ($r = json_decode((string) ($p['recurrence'] ?? ''), true)) ? array_diff_key($r, ['spawned' => 1]) : null,
            'review_note' => $p['review_note'] ?: null, 'created_via' => $p['source'] ?? 'web',
            'labels' => array_map(fn ($l) => ['id' => $l['uuid'], 'name' => $l['name'], 'color' => $l['color']], $labels),
            'media' => array_map([$this, 'media'], $svc->media((int) $p['id'])),
            'targets' => $targets,
            'author' => $author ? ['id' => $author['uuid'], 'name' => $author['name']] : null,
            'version' => (int) $p['version'],
            'created_at' => self::iso($p['created_at']), 'updated_at' => self::iso($p['updated_at']),
            'url' => abs_url('/w/' . $wsRow['uuid'] . '/posts/' . $p['uuid'] . '/edit'),
        ];
    }
}

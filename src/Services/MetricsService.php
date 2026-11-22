<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Providers\ProviderFactory;

/**
 * Likes, comments, shares and views of published posts, refreshed about every 6 hours
 * for 30 days after publishing (networks that report them: Instagram, Facebook, X, YouTube, Threads, Mastodon, Bluesky).
 */
final class MetricsService
{
    public const DAYS = 30;
    public const EVERY = 6 * 3600;

    public function __construct(private DB $db)
    {
    }

    /** @return int targets refreshed */
    public function refreshDue(int $limit = 10): int
    {
        $rows = $this->db->all(
            "SELECT t.id, t.external_id, c.id AS ch_id, c.network, c.credentials_enc, c.meta_json, m.fetched_at
             FROM post_targets t JOIN channels c ON c.id = t.channel_id JOIN posts p ON p.id = t.post_id
             LEFT JOIN post_metrics m ON m.target_id = t.id
             WHERE t.status = 'published' AND t.external_id IS NOT NULL AND t.published_at > ? AND c.status = 'active' AND p.deleted_at IS NULL
               AND (m.fetched_at IS NULL OR m.fetched_at < ?)
             ORDER BY m.fetched_at, t.published_at DESC LIMIT " . max(1, min(100, $limit)),
            [now_utc(-self::DAYS * 86400), now_utc(-self::EVERY)]
        );
        $done = 0;
        foreach ($rows as $r) {
            if (!ProviderFactory::has((string) $r['network'])) {
                continue;
            }
            $p = ProviderFactory::make((string) $r['network']);
            $metrics = null;
            if (method_exists($p, 'metrics')) {
                try {
                    $creds = Crypto::decryptJson((string) $r['credentials_enc']);
                    if ($fresh = $p->refresh($creds, ProviderFactory::appConfig((string) $r['network']))) {
                        $creds = $fresh + $creds;
                        $this->db->update('channels', ['credentials_enc' => Crypto::encryptJson($creds)], 'id = ?', [$r['ch_id']]);
                    }
                    $metrics = $p->metrics($creds, (string) $r['external_id'], json_decode((string) $r['meta_json'], true) ?: []);
                } catch (\Throwable) {
                    $metrics = null; // try again next round; publishing reports sign-in problems
                }
            }
            $this->store((int) $r['id'], $metrics, $r['fetched_at'] !== null);
            $done++;
        }
        return $done;
    }

    private function store(int $targetId, ?array $m, bool $exists): void
    {
        $data = ['fetched_at' => now_utc()];
        if ($m !== null) {
            foreach (['likes', 'comments', 'shares', 'views', 'impressions'] as $k) {
                $data[$k] = isset($m[$k]) ? max(0, (int) $m[$k]) : null;
            }
        }
        if ($exists) {
            $this->db->update('post_metrics', $data, 'target_id = ?', [$targetId]);
        } else {
            $this->db->insert('post_metrics', $data + ['target_id' => $targetId]);
        }
    }

    /** Totals for a set of posts (used by the post list and the composer). @return array<int, array> post id => totals */
    public function totals(array $postIds): array
    {
        if (!$postIds) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT t.post_id, SUM(m.likes) AS likes, SUM(m.comments) AS comments, SUM(m.shares) AS shares, SUM(m.views) AS views, MAX(m.fetched_at) AS fetched_at
             FROM post_targets t JOIN post_metrics m ON m.target_id = t.id WHERE t.post_id IN (' . DB::in($postIds) . ') GROUP BY t.post_id',
            array_values($postIds)
        ) as $r) {
            if ($r['likes'] === null && $r['comments'] === null && $r['shares'] === null && $r['views'] === null) {
                continue;
            }
            $out[(int) $r['post_id']] = array_map(fn ($v) => $v === null ? null : (int) $v, array_intersect_key($r, array_flip(['likes', 'comments', 'shares', 'views'])));
        }
        return $out;
    }
}

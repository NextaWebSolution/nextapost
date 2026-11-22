<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Repeating posts. A post's `recurrence` JSON: {"freq":"day|week|month|year","interval":1,"until":"2027-03-31"|null,"count":10|null,"n":1}.
 * Only one upcoming copy exists at a time: when an occurrence has finished publishing, the next
 * one is created with the same text, channels, media and labels, at the same local time.
 */
final class RecurrenceService
{
    public const FREQS = ['day', 'week', 'month', 'year'];

    public function __construct(private DB $db)
    {
    }

    /** @return array{0:?array,1:?string} [normalized rule or null to clear, error] */
    public static function validate(mixed $in): array
    {
        if ($in === null || $in === '' || $in === false || (is_array($in) && empty($in['freq']))) {
            return [null, null];
        }
        if (!is_array($in) || !in_array($in['freq'] ?? '', self::FREQS, true)) {
            return [null, __('Choose how often the post repeats: daily, weekly, monthly or yearly.')];
        }
        $interval = (int) ($in['interval'] ?? 1);
        if ($interval < 1 || $interval > 30) {
            return [null, __('Repeat every 1 to 30 periods.')];
        }
        $until = trim(str_val($in['until'] ?? ''));
        if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
            return [null, __('The end date must be a date.')];
        }
        $count = ($in['count'] ?? '') === '' || $in['count'] === null ? null : (int) $in['count'];
        if ($count !== null && ($count < 2 || $count > 500)) {
            return [null, __('A series can run 2 to 500 times.')];
        }
        return [['freq' => $in['freq'], 'interval' => $interval, 'until' => $until ?: null, 'count' => $count, 'n' => max(1, (int) ($in['n'] ?? 1))], null];
    }

    /** Next occurrence (UTC) after $publishAtUtc, keeping the local wall-clock time; null when the series ended. */
    public static function next(string $publishAtUtc, array $rule, string $tz): ?string
    {
        if (!empty($rule['count']) && (int) ($rule['n'] ?? 1) >= (int) $rule['count']) {
            return null;
        }
        $zone = new DateTimeZone($tz);
        $local = (new DateTimeImmutable($publishAtUtc, new DateTimeZone('UTC')))->setTimezone($zone);
        $i = max(1, (int) ($rule['interval'] ?? 1));
        // Monthly/yearly series keep their original day (31 Jan → 28 Feb → 31 Mar), stored as "day" in the rule.
        $anchor = (int) ($rule['day'] ?? $local->format('j'));
        $next = match ($rule['freq'] ?? null) {
            'day' => $local->modify("+{$i} day"),
            'week' => $local->modify('+' . (7 * $i) . ' day'),
            'month' => self::addMonths($local, $i, $anchor),
            'year' => self::addMonths($local, 12 * $i, $anchor),
            default => null, // unknown or damaged rule: end the series instead of failing the worker
        };
        if ($next === null) {
            return null;
        }
        if (!empty($rule['until']) && $next->format('Y-m-d') > $rule['until']) {
            return null;
        }
        return $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Adds months without overflowing (31 Jan + 1 month = 28/29 Feb), aiming for day $anchor of the month. */
    private static function addMonths(DateTimeImmutable $d, int $months, ?int $anchor = null): DateTimeImmutable
    {
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n') + $months;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        $day = min($anchor ?? (int) $d->format('j'), (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
        return $d->setDate($y, $m, $day);
    }

    /** Called by the cron worker: creates the next copy of finished occurrences. */
    public function spawnDue(int $limit = 50): int
    {
        $rows = $this->db->all(
            "SELECT p.*, w.timezone AS ws_tz FROM posts p JOIN workspaces w ON w.id = p.workspace_id
             WHERE p.recurrence IS NOT NULL AND p.deleted_at IS NULL AND w.deleted_at IS NULL
               AND p.status IN ('published','partially_published','failed') LIMIT " . (int) $limit
        );
        $n = 0;
        foreach ($rows as $post) {
            $rule = json_decode(str_val($post['recurrence']), true);
            if (!is_array($rule) || !empty($rule['spawned'])) {
                continue;
            }
            if ($post['publish_at'] && in_array($rule['freq'] ?? '', ['month', 'year'], true) && empty($rule['day'])) {
                try {
                    $rule['day'] = (int) (new DateTimeImmutable(str_val($post['publish_at']), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(str_val($post['ws_tz'])))->format('j');
                } catch (\Throwable) {
                }
            }
            $next = $post['publish_at'] ? self::next(str_val($post['publish_at']), $rule, str_val($post['ws_tz'])) : null;
            // Never schedule into the past (e.g. the worker was down): skip ahead to the next future slot.
            $guard = 0;
            while ($next !== null && $next < now_utc(60) && $guard++ < 1000) {
                $rule['n'] = (int) ($rule['n'] ?? 1) + 1;
                $next = self::next($next, $rule, str_val($post['ws_tz']));
            }
            $this->db->tx(function () use ($post, $rule, $next, &$n): void {
                $this->db->update('posts', ['recurrence' => json_encode($rule + ['spawned' => true])], 'id = ?', [$post['id']]);
                if ($next === null) {
                    return;
                }
                $child = $rule;
                $child['n'] = (int) ($rule['n'] ?? 1) + 1;
                unset($child['spawned']);
                $this->copy($post, $next, $child);
                $n++;
            });
        }
        return $n;
    }

    private function copy(array $post, string $publishAt, array $rule): int
    {
        $now = now_utc();
        $pid = $this->db->insert('posts', [
            'uuid' => uuid4(), 'workspace_id' => $post['workspace_id'], 'author_id' => $post['author_id'], 'title' => $post['title'], 'body' => $post['body'],
            'status' => 'scheduled', 'publish_at' => $publishAt, 'source' => 'recurrence', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            'recurrence' => json_encode($rule), 'recurrence_parent_id' => $post['recurrence_parent_id'] ?: $post['id'],
        ]);
        foreach ($this->db->all('SELECT * FROM post_targets WHERE post_id = ?', [$post['id']]) as $t) {
            $tid = $this->db->insert('post_targets', [
                'post_id' => $pid, 'channel_id' => $t['channel_id'], 'body_override' => $t['body_override'], 'status' => 'pending', 'publish_at' => $publishAt,
                'attempts' => 0, 'media_custom' => $t['media_custom'], 'content_type' => $t['content_type'], 'options_json' => $t['options_json'],
            ]);
            $this->db->run('INSERT INTO target_media (target_id, media_id, sort_order) SELECT ?, media_id, sort_order FROM target_media WHERE target_id = ?', [$tid, $t['id']]);
        }
        $this->db->run('INSERT INTO post_media (post_id, media_id, sort_order) SELECT ?, media_id, sort_order FROM post_media WHERE post_id = ?', [$pid, $post['id']]);
        $this->db->run('INSERT INTO post_labels (post_id, label_id) SELECT ?, label_id FROM post_labels WHERE post_id = ?', [$pid, $post['id']]);
        log_activity((int) $post['workspace_id'], 'post.recurred', __('Next in series scheduled: {title}', ['title' => str_val($post['title'])]), 'post', $pid);
        Events::emit($this->db, (int) $post['workspace_id'], 'post.scheduled', $pid);
        return $pid;
    }
}

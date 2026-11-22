<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Per-channel posting schedule (weekly time slots) and the queue built from it.
 * Schedule JSON: {"mon":["09:00","17:30"],"tue":[...],...} in the channel's time zone
 * (falls back to the workspace's). "Add to queue" takes the channel's next free slot.
 */
final class ScheduleService
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const MAX_PER_DAY = 12;
    public const MAX_GOAL = 42;

    /**
     * Starting suggestions per network: [day, hour]. General guidance for when audiences on each
     * network tend to be active; owners should adjust to their own audience.
     */
    private const WINDOWS = [
        'linkedin' => [['tue', 9], ['wed', 12], ['thu', 9], ['tue', 17], ['wed', 9], ['thu', 13], ['mon', 10], ['fri', 10]],
        'instagram' => [['wed', 11], ['fri', 19], ['tue', 19], ['sat', 11], ['thu', 12], ['sun', 18], ['mon', 19], ['wed', 19]],
        'facebook' => [['wed', 9], ['tue', 13], ['thu', 13], ['fri', 10], ['mon', 13], ['sat', 11], ['sun', 12]],
        'x' => [['tue', 8], ['wed', 12], ['thu', 9], ['mon', 12], ['fri', 8], ['tue', 17], ['wed', 17], ['thu', 17], ['sat', 10], ['sun', 11]],
        'threads' => [['tue', 9], ['wed', 12], ['thu', 18], ['mon', 12], ['fri', 9], ['sat', 11], ['sun', 18]],
        'tiktok' => [['tue', 19], ['thu', 19], ['fri', 18], ['sat', 11], ['sun', 19], ['wed', 20], ['mon', 19]],
        'youtube' => [['fri', 17], ['sat', 11], ['sun', 11], ['thu', 17], ['wed', 17]],
        'pinterest' => [['sat', 20], ['fri', 15], ['sun', 20], ['tue', 21], ['thu', 21]],
        'default' => [['tue', 10], ['thu', 10], ['wed', 15], ['mon', 11], ['fri', 11], ['tue', 16], ['thu', 16]],
    ];

    public function __construct(private DB $db)
    {
    }

    public static function decode(?string $json): array
    {
        $out = array_fill_keys(self::DAYS, []);
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return $out;
        }
        foreach (self::DAYS as $d) {
            foreach ((array) ($data[$d] ?? []) as $t) {
                if (is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                    $out[$d][] = $t;
                }
            }
            $out[$d] = array_values(array_unique($out[$d]));
            sort($out[$d]);
        }
        return $out;
    }

    public static function slotCount(array $schedule): int
    {
        return array_sum(array_map('count', $schedule));
    }

    /** @return array{0:?array,1:?string} [normalized schedule, error] */
    public static function validate(mixed $input): array
    {
        if (!is_array($input)) {
            return [null, __('The schedule must list times per weekday.')];
        }
        $out = array_fill_keys(self::DAYS, []);
        foreach ($input as $day => $times) {
            if (!in_array($day, self::DAYS, true) || !is_array($times)) {
                return [null, __('Unknown weekday “{day}”. Use mon, tue, wed, thu, fri, sat or sun.', ['day' => (string) $day])];
            }
            foreach ($times as $t) {
                if (!is_string($t) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                    return [null, __('Times must look like 09:30 (24-hour clock).')];
                }
                $out[$day][] = $t;
            }
            $out[$day] = array_values(array_unique($out[$day]));
            sort($out[$day]);
            if (count($out[$day]) > self::MAX_PER_DAY) {
                return [null, __('Up to {n} times per day.', ['n' => self::MAX_PER_DAY])];
            }
        }
        return [$out, null];
    }

    /** Suggested schedule for a network and a weekly posting goal. */
    public static function generate(string $network, int $goal): array
    {
        $goal = max(1, min(self::MAX_GOAL, $goal));
        $key = match (true) {
            str_starts_with($network, 'linkedin') => 'linkedin',
            str_starts_with($network, 'instagram') => 'instagram',
            isset(self::WINDOWS[$network]) => $network,
            default => 'default',
        };
        $windows = self::WINDOWS[$key];
        $out = array_fill_keys(self::DAYS, []);
        // Cycle through the windows, shifting the hour on later rounds so times don't collide.
        for ($i = 0; $i < $goal; $i++) {
            [$day, $hour] = $windows[$i % count($windows)];
            $round = intdiv($i, count($windows));
            $h = ($hour + $round * 3) % 24;
            $minute = (crc32($network . $day . $i) % 4) * 15; // 00, 15, 30 or 45: steady, not random per request
            $t = sprintf('%02d:%02d', $h, $minute);
            if (!in_array($t, $out[$day], true)) {
                $out[$day][] = $t;
            }
        }
        foreach ($out as &$times) {
            sort($times);
        }
        return $out;
    }

    public function save(array $channel, array $schedule, ?string $timezone, ?int $goal): void
    {
        $tz = $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
        $this->db->update('channels', [
            'posting_schedule' => json_encode($schedule), 'timezone' => $tz, 'posting_goal' => $goal, 'updated_at' => now_utc(),
        ], 'id = ?', [$channel['id']]);
    }

    public function timezone(array $channel, array $ws): string
    {
        return (string) ($channel['timezone'] ?: $ws['timezone']);
    }

    /**
     * Free queue slots for a channel, in UTC ("Y-m-d H:i:s"), soonest first.
     * A slot is taken when a live post on this channel is already set for that minute.
     */
    public function freeSlots(array $channel, array $ws, int $limit = 10, ?string $afterUtc = null, int $days = 90): array
    {
        $schedule = self::decode($channel['posting_schedule'] ?? null);
        if (self::slotCount($schedule) === 0) {
            return [];
        }
        $tz = new DateTimeZone($this->timezone($channel, $ws));
        $after = new DateTimeImmutable($afterUtc ?? now_utc(120), new DateTimeZone('UTC'));
        $taken = [];
        foreach ($this->db->all(
            "SELECT t.publish_at FROM post_targets t JOIN posts p ON p.id = t.post_id
             WHERE t.channel_id = ? AND t.publish_at >= ? AND t.status IN ('pending','queued','publishing','published')
               AND p.deleted_at IS NULL AND p.status NOT IN ('cancelled')",
            [$channel['id'], $after->format('Y-m-d H:i:00')]
        ) as $r) {
            $taken[substr((string) $r['publish_at'], 0, 16)] = true;
        }
        $out = [];
        $day = $after->setTimezone($tz)->setTime(0, 0);
        for ($i = 0; $i < $days && count($out) < $limit; $i++, $day = $day->modify('+1 day')) {
            $key = self::DAYS[(int) $day->format('N') - 1];
            foreach ($schedule[$key] as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->setTime($h, $m)->setTimezone(new DateTimeZone('UTC'));
                $utc = $slot->format('Y-m-d H:i:00');
                if ($slot <= $after || isset($taken[substr($utc, 0, 16)])) {
                    continue;
                }
                $out[] = $utc;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    public function nextSlot(array $channel, array $ws, ?string $afterUtc = null): ?string
    {
        return $this->freeSlots($channel, $ws, 1, $afterUtc)[0] ?? null;
    }
}

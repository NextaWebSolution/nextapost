<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /** Returns true when the action is allowed, false when the bucket is exhausted. */
    public static function hit(DB $db, string $bucket, int $max, int $windowSeconds): bool
    {
        $bucket = substr(hash('sha256', $bucket), 0, 64);
        $now = time();
        $row = $db->one('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if ($row === null || (int) $row['reset_at'] <= $now) {
            $db->run('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
            try {
                $db->insert('rate_limits', ['bucket' => $bucket, 'hits' => 1, 'reset_at' => $now + $windowSeconds]);
            } catch (\PDOException $e) {
                if (!DB::isDuplicate($e)) {
                    throw $e;
                }
            }
            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        $db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
        return true;
    }

    public static function clear(DB $db, string $bucket): void
    {
        $db->run('DELETE FROM rate_limits WHERE bucket = ?', [substr(hash('sha256', $bucket), 0, 64)]);
    }
}

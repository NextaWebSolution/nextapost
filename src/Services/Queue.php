<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Database-backed job queue. Claims are atomic (UPDATE … WHERE status='queued'), so several workers are safe. */
final class Queue
{
    public function __construct(private DB $db)
    {
    }

    public function push(string $type, array $payload, ?string $dedupeKey = null, ?string $runAt = null, int $maxAttempts = 3): bool
    {
        try {
            $this->db->insert('jobs', [
                'type' => $type, 'payload' => (string) json_encode($payload), 'dedupe_key' => $dedupeKey,
                'status' => 'queued', 'run_at' => $runAt ?? now_utc(), 'attempts' => 0, 'max_attempts' => $maxAttempts,
                'created_at' => now_utc(),
            ]);
            return true;
        } catch (\PDOException $e) {
            if (DB::isDuplicate($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function claim(string $workerId): ?array
    {
        for ($try = 0; $try < 5; $try++) {
            $job = $this->db->one("SELECT * FROM jobs WHERE status = 'queued' AND run_at <= ? ORDER BY run_at, id LIMIT 1", [now_utc()]);
            if (!$job) {
                return null;
            }
            $n = $this->db->run(
                "UPDATE jobs SET status = 'running', locked_at = ?, worker_id = ?, attempts = attempts + 1 WHERE id = ? AND status = 'queued'",
                [now_utc(), $workerId, $job['id']]
            )->rowCount();
            if ($n === 1) {
                $job['attempts'] = (int) $job['attempts'] + 1;
                $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
                return $job;
            }
        }
        return null;
    }

    public function complete(int $id): void
    {
        $this->db->update('jobs', ['status' => 'done', 'finished_at' => now_utc(), 'last_error' => null], 'id = ?', [$id]);
    }

    public function fail(int $id, string $error): void
    {
        $this->db->update('jobs', ['status' => 'failed', 'finished_at' => now_utc(), 'last_error' => mb_substr($error, 0, 2000)], 'id = ?', [$id]);
    }

    public function retry(int $id, int $delaySeconds, string $error): void
    {
        $this->db->update('jobs', ['status' => 'queued', 'run_at' => now_utc($delaySeconds), 'locked_at' => null, 'worker_id' => null, 'last_error' => mb_substr($error, 0, 2000)], 'id = ?', [$id]);
    }

    /** Jobs stuck in "running" (worker crashed / timed out) go back to the queue. */
    public function releaseStale(int $olderThanSeconds = 900): int
    {
        return $this->db->run(
            "UPDATE jobs SET status = 'queued', locked_at = NULL, worker_id = NULL WHERE status = 'running' AND locked_at < ?",
            [now_utc(-$olderThanSeconds)]
        )->rowCount();
    }

    public function prune(int $doneDays = 7, int $failedDays = 30): void
    {
        $this->db->run("DELETE FROM jobs WHERE status = 'done' AND finished_at < ?", [now_utc(-$doneDays * 86400)]);
        $this->db->run("DELETE FROM jobs WHERE status = 'failed' AND finished_at < ?", [now_utc(-$failedDays * 86400)]);
    }

    public function stats(): array
    {
        $rows = $this->db->all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status');
        $out = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}

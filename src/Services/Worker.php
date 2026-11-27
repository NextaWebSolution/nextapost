<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;
use App\Providers\ProviderException;

/** Runs from cron: enqueues due posts, processes jobs, keeps the system healthy. */
final class Worker
{
    private Queue $queue;
    private PublishService $publisher;

    public function __construct(private DB $db)
    {
        $this->queue = new Queue($db);
        $this->publisher = new PublishService($db, $this->queue);
    }

    /** One scheduling pass: returns number of jobs processed. */
    public function tick(string $workerId, int $deadline): int
    {
        $this->publisher->enqueueDue();
        try {
            (new RecurrenceService($this->db))->spawnDue();
        } catch (\Throwable $e) {
            Logger::error('Recurring posts: ' . $e->getMessage());
        }
        try {
            (new FeedService($this->db))->fetchDue(5);
        } catch (\Throwable $e) {
            Logger::error('Feeds: ' . $e->getMessage());
        }
        try {
            (new MetricsService($this->db))->refreshDue(10);
        } catch (\Throwable $e) {
            Logger::error('Metrics: ' . $e->getMessage());
        }
        try {
            (new RepurposeService($this->db))->pollDue(2);
        } catch (\Throwable $e) {
            Logger::error('Repurpose: ' . $e->getMessage());
        }
        try {
            // Comment-to-DM: anything the webhook didn't finish, and comments held back by the hourly limit.
            (new CommentDmService($this->db))->runDue(min($deadline, time() + 20));
        } catch (\Throwable $e) {
            Logger::error('Comment-to-DM: ' . $e->getMessage());
        }
        $processed = 0;
        while (time() < $deadline && ($job = $this->queue->claim($workerId))) {
            $this->handle($job);
            $processed++;
        }
        return $processed;
    }

    public function handle(array $job): void
    {
        try {
            match ($job['type']) {
                'email' => \App\Core\Mailer::send((string) $job['payload']['to'], (string) $job['payload']['subject'], (string) $job['payload']['html']),
                'webhook' => (new WebhookService($this->db))->deliver((int) ($job['payload']['delivery_id'] ?? 0)),
                default => $this->publisher->handleJob($job),
            };
            $this->queue->complete((int) $job['id']);
        } catch (ProviderException $e) {
            if ($e->retryable && (int) $job['attempts'] < (int) $job['max_attempts']) {
                $delay = [60, 300, 900][(int) $job['attempts'] - 1] ?? 900;
                $this->queue->retry((int) $job['id'], $delay, $e->getMessage());
            } else {
                $this->queue->fail((int) $job['id'], $e->getMessage());
                $this->failJobTarget($job, $e->getMessage() . ($e->retryable ? ' (gave up after ' . $job['attempts'] . ' attempts)' : ''));
            }
        } catch (\Throwable $e) {
            Logger::error($e);
            $this->queue->fail((int) $job['id'], $e->getMessage());
            $this->failJobTarget($job, 'Unexpected error: ' . $e->getMessage());
        }
    }

    private function failJobTarget(array $job, string $msg): void
    {
        if ($job['type'] === 'publish' && !empty($job['payload']['target_id'])) {
            $this->publisher->markTargetFailed((int) $job['payload']['target_id'], $msg);
        }
    }

    /** Full cron run: loops until the deadline (keep under the cron interval). */
    public function run(int $maxSeconds = 50, bool $once = false): array
    {
        $deadline = time() + $maxSeconds;
        $workerId = substr((gethostname() ?: 'host') . ':' . getmypid(), 0, 80);
        $released = $this->queue->releaseStale();
        $processed = 0;
        do {
            $processed += $this->tick($workerId, $deadline);
            $this->heartbeat($processed);
            if ($once) {
                break;
            }
            sleep(5);
        } while (time() + 5 < $deadline);
        $this->housekeeping();
        return ['processed' => $processed, 'released' => $released];
    }

    public function heartbeat(int $processed = 0): void
    {
        Settings::set('cron_heartbeat', ['at' => now_utc(), 'processed' => $processed, 'php' => PHP_VERSION]);
    }

    private function housekeeping(): void
    {
        $last = (string) (Settings::get('housekeeping_at') ?? '');
        if ($last !== '' && $last > now_utc(-86400)) {
            return;
        }
        Settings::set('housekeeping_at', now_utc());
        $this->queue->prune();
        (new WebhookService($this->db))->prune();
        (new OAuthService($this->db))->prune();
        (new CommentDmService($this->db))->prune();
        Logger::prune();
        $this->db->run('DELETE FROM rate_limits WHERE reset_at < ?', [time()]);
        $this->db->run('DELETE FROM password_resets WHERE expires_at < ?', [now_utc(-86400)]);
        $this->db->run('DELETE FROM activity_log WHERE created_at < ?', [now_utc(-365 * 86400)]);
        // Privacy: IP addresses in the activity log are kept for 90 days only.
        $this->db->run('UPDATE activity_log SET ip = NULL WHERE ip IS NOT NULL AND created_at < ?', [now_utc(-90 * 86400)]);
        try {
            (new RetentionService($this->db))->runAll();
        } catch (\Throwable $e) {
            Logger::error('Storage clean-up failed: ' . $e->getMessage());
        }
        foreach (glob(APP_ROOT . '/storage/tmp/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        try {
            (new Updater($this->db))->check(false);
        } catch (\Throwable $e) {
            Logger::info('Update check skipped: ' . $e->getMessage());
        }
    }
}

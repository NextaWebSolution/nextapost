<?php

declare(strict_types=1);

/**
 * NextaPost scheduler — run every minute from cPanel → Cron Jobs:
 *   * * * * * /usr/local/bin/php /home/USER/nextapost/bin/cron.php >/dev/null 2>&1
 *
 * Options: --once (single pass)  --max-time=50 (seconds)  --no-lock (extra parallel worker on a VPS)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/src/bootstrap.php';

if (!is_file(APP_ROOT . '/storage/installed.lock')) {
    fwrite(STDERR, "NextaPost is not installed yet.\n");
    exit(1);
}
if (is_file(APP_ROOT . '/storage/maintenance.flag') && filemtime(APP_ROOT . '/storage/maintenance.flag') > time() - 1800) {
    exit(0); // An update is running.
}

$opts = getopt('', ['once', 'max-time:', 'no-lock']);
$app = App\Core\App::boot(APP_ROOT);

$lock = null;
if (!isset($opts['no-lock'])) {
    $lock = fopen(APP_ROOT . '/storage/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        exit(0); // Previous run still busy.
    }
}

@set_time_limit(0);
try {
    $result = (new App\Services\Worker($app->db))->run(max(5, (int) ($opts['max-time'] ?? 50)), isset($opts['once']));
    if (getenv('NP_VERBOSE')) {
        echo json_encode($result), "\n";
    }
} catch (Throwable $e) {
    App\Core\Logger::error($e);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

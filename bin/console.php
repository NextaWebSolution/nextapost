<?php

declare(strict_types=1);

/**
 * NextaPost command line.
 *   php bin/console.php migrate                       Apply database migrations
 *   php bin/console.php create-admin EMAIL PASSWORD [NAME]
 *   php bin/console.php reset-password EMAIL PASSWORD
 *   php bin/console.php backup                        Create a backup in storage/backups
 *   php bin/console.php update ZIPFILE                Install an update package
 *   php bin/console.php version
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/src/bootstrap.php';

$cmd = $argv[1] ?? 'help';
if ($cmd === 'version') {
    echo APP_VERSION, "\n";
    exit(0);
}
$app = App\Core\App::boot(APP_ROOT);
$db = $app->db;

try {
    switch ($cmd) {
        case 'migrate':
            $done = (new App\Core\Migrator($db))->migrate();
            echo 'Database is up to date.' . ($done ? ' Applied: ' . implode(', ', $done) : '') . "\n";
            break;
        case 'create-admin':
            [$email, $pass] = [$argv[2] ?? '', $argv[3] ?? ''];
            $res = (new App\Services\AccountService($db))->register($argv[4] ?? 'Administrator', $email, $pass, 'Asia/Kolkata', '', true);
            if (is_string($res)) {
                throw new RuntimeException($res);
            }
            echo "Admin created: {$res['user']['email']}\n";
            break;
        case 'reset-password':
            $u = $db->one('SELECT id FROM users WHERE email = ?', [mb_strtolower($argv[2] ?? '')]);
            if (!$u || ($err = App\Services\AccountService::passwordProblem($argv[3] ?? ''))) {
                throw new RuntimeException($err ?? 'User not found.');
            }
            $db->update('users', ['password_hash' => \App\Core\Auth::hash($argv[3]), 'is_active' => 1], 'id = ?', [$u['id']]);
            echo "Password updated.\n";
            break;
        case 'backup':
            echo 'Backup written to ' . (new App\Services\Backup($db))->create('cli') . "\n";
            break;
        case 'update':
            echo 'Updated to ' . (new App\Services\Updater($db))->install((string) ($argv[2] ?? ''), in_array('--force', $argv, true)) . "\n";
            break;
        default:
            echo "Commands: migrate | create-admin EMAIL PASSWORD [NAME] | reset-password EMAIL PASSWORD | backup | update ZIP [--force] | version\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

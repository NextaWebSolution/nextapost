<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Settings;

/** First-time installation (used by public/install and the test suite). */
final class Installer
{
    public static function lockFile(): string
    {
        return APP_ROOT . '/storage/installed.lock';
    }

    public static function installed(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * @param array $env   APP_NAME, APP_URL, DB_* values (APP_KEY is generated unless already present)
     * @param array $admin name, email, password, timezone, company
     */
    public static function install(array $env, array $admin): void
    {
        if (self::installed()) {
            throw new \RuntimeException('NextaPost is already installed.');
        }
        $existingKey = '';
        if (is_file(APP_ROOT . '/.env')) {
            Env::load(APP_ROOT . '/.env');
            $existingKey = (string) Env::get('APP_KEY', '');
        }
        $env = ['APP_KEY' => str_starts_with($existingKey, 'base64:') ? $existingKey : Crypto::newKey(), 'APP_DEBUG' => false, 'MAX_UPLOAD_MB' => '100'] + $env;
        if (file_put_contents(APP_ROOT . '/.env', Env::render($env), LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the .env file. Make the application folder writable and try again.');
        }
        @chmod(APP_ROOT . '/.env', 0600);
        foreach ($env as $k => $v) {
            $_ENV[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        $app = App::boot(APP_ROOT);
        (new Migrator($app->db))->migrate();
        Settings::forgetCache();

        $email = mb_strtolower(trim((string) $admin['email']));
        $existing = $app->db->one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            $app->db->update('users', ['password_hash' => \App\Core\Auth::hash((string) $admin['password']), 'is_super_admin' => 1, 'is_active' => 1], 'id = ?', [$existing['id']]);
        } else {
            $res = (new AccountService($app->db))->register((string) $admin['name'], $email, (string) $admin['password'], (string) ($admin['timezone'] ?? 'Asia/Kolkata'), (string) ($admin['company'] ?? ''), true);
            if (is_string($res)) {
                throw new \RuntimeException($res);
            }
        }
        Settings::set('app_name', (string) ($env['APP_NAME'] ?? 'NextaPost'));
        if (Settings::get('allow_registration') === null) {
            Settings::set('allow_registration', false);
        }
        Settings::set('installed', ['at' => now_utc(), 'version' => APP_VERSION]);
        file_put_contents(self::lockFile(), gmdate('c') . ' v' . APP_VERSION . "\n");
    }
}

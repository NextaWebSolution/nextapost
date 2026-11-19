<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            $stamp = $_SESSION['pwstamp'] ?? null;
            if ($id) {
                $u = app()->db->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id]);
                // Sessions are invalidated when the password changes.
                $idle = (int) Env::get('SESSION_IDLE_HOURS', 12) * 3600;
                $max = (int) Env::get('SESSION_MAX_DAYS', 7) * 86400;
                $now = time();
                $expired = ($now - (int) ($_SESSION['seen_at'] ?? 0)) > $idle || ($now - (int) ($_SESSION['login_at'] ?? 0)) > $max;
                if ($u && !$expired && $stamp === substr(hash('sha256', (string) $u['password_hash']), 0, 16)) {
                    self::$user = $u;
                    $_SESSION['seen_at'] = $now;
                } else {
                    unset($_SESSION['uid'], $_SESSION['pwstamp']);
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::user() ? (int) self::user()['id'] : null;
    }

    public static function attempt(string $email, string $password): ?array
    {
        $u = app()->db->one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        // Always run a hash check so response time doesn't reveal whether an account exists or is disabled.
        $ok = password_verify($password, $u ? (string) $u['password_hash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        if (!$u || !$ok || !(int) $u['is_active']) {
            return null;
        }
        if (password_needs_rehash((string) $u['password_hash'], self::algo())) {
            $hash = self::hash($password);
            app()->db->update('users', ['password_hash' => $hash], 'id = ?', [$u['id']]);
            $u['password_hash'] = $hash;
        }
        return $u;
    }

    /** Argon2id when the server supports it, otherwise bcrypt. */
    public static function algo(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        $_SESSION['login_at'] = $_SESSION['seen_at'] = time();
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['pwstamp'] = substr(hash('sha256', (string) $user['password_hash']), 0, 16);
        self::$user = $user;
        self::$loaded = true;
        app()->db->update('users', ['last_login_at' => now_utc()], 'id = ?', [$user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        Session::regenerate();
        self::$user = null;
        self::$loaded = true;
    }

    /** Test helper / CLI: reset cached state. */
    public static function reset(): void
    {
        self::$user = null;
        self::$loaded = false;
    }
}

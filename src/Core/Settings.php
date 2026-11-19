<?php

declare(strict_types=1);

namespace App\Core;

/** System-wide key/value settings stored as JSON in the `settings` table. */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            $raw = app()->db->value('SELECT value_json FROM settings WHERE name = ?', [$key]);
            self::$cache[$key] = $raw === null ? null : json_decode((string) $raw, true);
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $db = app()->db;
        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($db->update('settings', ['value_json' => $json, 'updated_at' => now_utc()], 'name = ?', [$key]) === 0) {
            $exists = $db->value('SELECT 1 FROM settings WHERE name = ?', [$key]);
            if (!$exists) {
                $db->insert('settings', ['name' => $key, 'value_json' => $json, 'updated_at' => now_utc()]);
            }
        }
        self::$cache[$key] = $value;
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }
}

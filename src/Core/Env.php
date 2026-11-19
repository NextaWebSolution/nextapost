<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (preg_match('/^"(.*)"$/s', $value, $m)) {
                $value = stripcslashes($m[1]);
            } elseif (preg_match("/^'(.*)'$/s", $value, $m)) {
                $value = $m[1];
            } else {
                $value = (string) preg_replace('/\s+#.*$/', '', $value);
            }
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }

    /** Render a .env file from key/value pairs (values are always double-quoted and escaped). */
    public static function render(array $values): string
    {
        $out = "# NextaPost configuration — generated " . gmdate('Y-m-d H:i') . " UTC\n";
        foreach ($values as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $v = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], (string) $v);
            $out .= $k . '="' . $v . "\"\n";
        }
        return $out;
    }
}

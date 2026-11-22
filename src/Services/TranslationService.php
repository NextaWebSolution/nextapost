<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Lang;

/**
 * Interface translations kept by this site: coverage per language, and filling missing
 * strings with a workspace's AI key. Filled strings go to storage/lang/<code>.php, which
 * Lang merges over the shipped lang/<code>.php and which updates never overwrite.
 */
final class TranslationService
{
    public const BATCH = 25;

    public function __construct(private DB $db)
    {
    }

    /** @return list<string> every English string the interface uses */
    public static function keys(): array
    {
        $file = APP_ROOT . '/lang/_keys.json';
        $keys = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** @return array<string, string> */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? array_filter($data, fn ($v, $k) => is_string($k) && is_string($v) && $v !== '', ARRAY_FILTER_USE_BOTH) : [];
    }

    public static function siteFile(string $code): string
    {
        return APP_ROOT . '/storage/lang/' . $code . '.php';
    }

    /** @return array<string, array{native:string, name:string, dir:string, shipped:int, site:int, missing:int, percent:int}> */
    public static function status(): array
    {
        $keys = self::keys();
        $total = max(1, count($keys));
        $out = [];
        foreach (Lang::LOCALES as $code => [$native, $name, $dir]) {
            if ($code === 'en') {
                continue;
            }
            $shipped = self::load(APP_ROOT . '/lang/' . $code . '.php');
            $site = self::load(self::siteFile($code));
            $have = 0;
            $siteCount = 0;
            foreach ($keys as $k) {
                if (isset($shipped[$k]) || isset($site[$k])) {
                    $have++;
                }
                if (isset($site[$k]) && !isset($shipped[$k])) {
                    $siteCount++;
                }
            }
            $out[$code] = [
                'native' => $native, 'name' => $name, 'dir' => $dir,
                'shipped' => count(array_intersect_key($shipped, array_flip($keys))), 'site' => $siteCount,
                'missing' => count($keys) - $have, 'percent' => (int) floor($have * 100 / $total),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public static function missing(string $code): array
    {
        $have = self::load(APP_ROOT . '/lang/' . $code . '.php') + self::load(self::siteFile($code));
        return array_values(array_filter(self::keys(), fn ($k) => !isset($have[$k])));
    }

    /**
     * Translate the next batch of missing strings.
     * @return array{filled:int, remaining:int, error:?string}
     */
    public function fillBatch(array $ws, string $code, ?int $limit = null): array
    {
        if ($code === 'en' || !isset(Lang::LOCALES[$code])) {
            return ['filled' => 0, 'remaining' => 0, 'error' => __('Unknown language.')];
        }
        if (!AiService::configured($ws)) {
            return ['filled' => 0, 'remaining' => count(self::missing($code)), 'error' => __('The AI assistant isn’t set up for this workspace yet. An admin can add an API key in Workspace settings.')];
        }
        $missing = self::missing($code);
        $batch = array_slice($missing, 0, $limit ?? self::BATCH);
        if (!$batch) {
            return ['filled' => 0, 'remaining' => 0, 'error' => null];
        }
        [$native, $name] = Lang::LOCALES[$code];
        $system = 'You translate the user interface of NextaPost, a web app for scheduling social media posts, from English into '
            . $name . ' (' . $native . '). Write short, natural interface wording a native speaker would expect in software. '
            . 'Keep every placeholder in curly braces, such as {name} or {n}, exactly as it is. '
            . 'Keep product and brand names (NextaPost, Facebook, Instagram, LinkedIn, X, TikTok, YouTube, Google, WordPress and so on), '
            . 'technical terms (API, URL, HTTPS, MCP, cPanel, .env) and keyboard keys unchanged. Keep leading or trailing spaces and the ending punctuation style. '
            . 'Reply with one JSON object only: each key is an input string copied exactly, each value is its translation.';
        $user = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$text, $err] = (new AiService($this->db))->complete($ws, $system, (string) $user, null, 4000);
        if ($err !== null) {
            return ['filled' => 0, 'remaining' => count($missing), 'error' => $err];
        }
        $map = self::parse((string) $text);
        $good = [];
        foreach ($batch as $en) {
            $t = $map[$en] ?? null;
            if (is_string($t) && self::acceptable($en, $t)) {
                $good[$en] = self::normalizeSpaces($en, $t);
            }
        }
        if (!$good) {
            return ['filled' => 0, 'remaining' => count($missing), 'error' => __('The AI reply couldn’t be used. Try again, or try another model.')];
        }
        $this->save($code, $good);
        return ['filled' => count($good), 'remaining' => count($missing) - count($good), 'error' => null];
    }

    /** @return array<string, mixed> */
    public static function parse(string $text): array
    {
        $text = AiService::clean($text);
        $data = json_decode($text, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }
        return is_array($data) ? $data : [];
    }

    /** A translation must keep the placeholders, add no markup and stay a sensible length. */
    public static function acceptable(string $en, string $t): bool
    {
        $t = trim($t);
        if ($t === '' || mb_strlen($t) > max(40, mb_strlen($en) * 4) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $t)) {
            return false;
        }
        preg_match_all('/\{[a-z_]+\}/', $en, $a);
        preg_match_all('/\{[a-z_]+\}/', $t, $b);
        $pa = $a[0];
        $pb = $b[0];
        sort($pa);
        sort($pb);
        if ($pa !== $pb) {
            return false;
        }
        // No markup the English doesn't have.
        foreach (['<', '>'] as $ch) {
            if (substr_count($t, $ch) > substr_count($en, $ch)) {
                return false;
            }
        }
        return true;
    }

    private static function normalizeSpaces(string $en, string $t): string
    {
        $t = trim($t);
        if (str_ends_with($en, ' ')) {
            $t .= ' ';
        }
        if (str_starts_with($en, ' ')) {
            $t = ' ' . $t;
        }
        return $t;
    }

    /** @param array<string, string> $add */
    private function save(string $code, array $add): void
    {
        $file = self::siteFile($code);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        $lock = fopen($file . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $data = self::load($file);
            foreach ($add as $k => $v) {
                $data[$k] = $v;
            }
            ksort($data);
            $php = "<?php\n// This site's own interface text for " . $code . " (filled by System → Languages). Kept across updates.\nreturn "
                . var_export($data, true) . ";\n";
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($tmp, $php) === false || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Could not write ' . $file);
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function reset(string $code): bool
    {
        if (!isset(Lang::LOCALES[$code]) || $code === 'en') {
            return false;
        }
        $file = self::siteFile($code);
        if (is_file($file)) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
        return true;
    }
}

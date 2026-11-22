<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Interface translations. Strings are looked up by their English text, so anything
 * not translated yet simply shows in English. Files: lang/<code>.php returning
 * ['English text' => 'Translation', ...]; {name} placeholders are filled from $vars.
 */
final class Lang
{
    /** code => [native name, English name, direction] */
    public const LOCALES = [
        'en' => ['English', 'English', 'ltr'],
        'hi' => ['हिन्दी', 'Hindi', 'ltr'],
        'bn' => ['বাংলা', 'Bengali', 'ltr'],
        'ar' => ['العربية', 'Arabic', 'rtl'],
        'de' => ['Deutsch', 'German', 'ltr'],
        'el' => ['Ελληνικά', 'Greek', 'ltr'],
        'es' => ['Español', 'Spanish', 'ltr'],
        'fr' => ['Français', 'French', 'ltr'],
        'it' => ['Italiano', 'Italian', 'ltr'],
        'ja' => ['日本語', 'Japanese', 'ltr'],
        'ko' => ['한국어', 'Korean', 'ltr'],
        'nl' => ['Nederlands', 'Dutch', 'ltr'],
        'pl' => ['Polski', 'Polish', 'ltr'],
        'pt' => ['Português', 'Portuguese', 'ltr'],
        'ru' => ['Русский', 'Russian', 'ltr'],
        'tr' => ['Türkçe', 'Turkish', 'ltr'],
        'uk' => ['Українська', 'Ukrainian', 'ltr'],
        'zh' => ['简体中文', 'Chinese (Simplified)', 'ltr'],
    ];

    /** Strings used by the browser scripts (sent to the page as window.NP_I18N). */
    public const JS_KEYS = [
        'Replace the current text with this template?', 'Delete this note?', 'Wait for uploads to finish.', 'Could not save.',
        'Publish to {n} channel(s) right now?', 'Not scheduled', 'Saving…', 'Sending…', 'Something went wrong.', 'Copied',
        'Working…', 'Try again', 'Delete this?', 'Saved', 'Deleted',
        'Revoke this key? Apps using it will stop working.', 'Key revoked', 'Disconnect this app? It will need to sign in again.', 'Disconnected',
        'Make a new signing secret? The old one stops working right away.', 'Delete this webhook?', 'Test delivered', 'Test failed', 'Sent again',
        'Rephrase', 'Make shorter', 'Make longer', 'More casual', 'More professional', 'Fix spelling and grammar', 'Add hashtags', 'Add emoji',
        'Fit this channel’s limit', 'Describe what the post should be about.', 'Write some text first.',
        'Delete this idea?', 'Column name', 'Delete this column? Its ideas move to the first column.', 'Following', '{n} new',
        'Stop following this feed?', 'Saved as an idea',
        'Add from a link', 'Added to the library', '{n} files added to the library', 'No photos found.', 'Exporting from Canva…',
        'Google Drive couldn’t be opened.', 'Disconnect this account?', 'pages',
        'Rule created', '{n} new post(s) made', 'Delete this rule? Posts it already made are kept.',
    ];

    private static string $locale = 'en';
    /** @var array<string, string> */
    private static array $map = [];

    public static function set(?string $code): void
    {
        $code = self::normalize($code);
        self::$locale = $code;
        self::$map = [];
        if ($code !== 'en') {
            // Shipped translations, then the site's own (storage/lang, kept across updates; filled by admins or the AI tool).
            foreach ([APP_ROOT . '/lang/' . $code . '.php', APP_ROOT . '/storage/lang/' . $code . '.php'] as $file) {
                if (is_file($file)) {
                    $data = require $file;
                    if (is_array($data)) {
                        self::$map = array_merge(self::$map, array_filter($data, fn ($v) => is_string($v) && $v !== ''));
                    }
                }
            }
        }
    }

    public static function current(): string
    {
        return self::$locale;
    }

    public static function dir(): string
    {
        return self::LOCALES[self::$locale][2] ?? 'ltr';
    }

    public static function normalize(?string $code): string
    {
        $code = strtolower(trim((string) $code));
        if (isset(self::LOCALES[$code])) {
            return $code;
        }
        $base = substr($code, 0, 2);
        return isset(self::LOCALES[$base]) ? $base : 'en';
    }

    /** Best match from an Accept-Language header, or English. */
    public static function fromHeader(?string $header): string
    {
        foreach (explode(',', (string) $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            if ($tag === '') {
                continue;
            }
            if (isset(self::LOCALES[$tag]) || isset(self::LOCALES[substr($tag, 0, 2)])) {
                return self::normalize($tag);
            }
        }
        return 'en';
    }

    public static function translate(string $text, array $vars = []): string
    {
        $out = self::$map[$text] ?? $text;
        if ($out === '') {
            $out = $text;
        }
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    /** Strings the browser scripts need, as a JSON-safe map (only what is translated). */
    public static function forJs(array $keys = []): array
    {
        $file = APP_ROOT . '/lang/_js.php';
        if (is_file($file)) {
            $keys = array_merge($keys, (array) require $file);
        }
        $out = [];
        foreach ($keys as $k) {
            if (isset(self::$map[$k]) && self::$map[$k] !== '') {
                $out[$k] = self::$map[$k];
            }
        }
        return $out;
    }
}

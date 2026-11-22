<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\Settings;
use App\Providers\ProviderFactory;

function app(): App
{
    return App::get();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) {
        return $prefix;
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
    return $prefix = strip_public_segment($dir);
}

/**
 * Root install support: when the whole package sits in public_html, the root .htaccess rewrites
 * /login → /public/index.php. Visitors never typed "/public", so links must not contain it either.
 */
function strip_public_segment(string $dir): string
{
    if (str_ends_with($dir, '/public')) {
        $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if ($uri !== $dir && !str_starts_with($uri, $dir . '/')) {
            return substr($dir, 0, -strlen('/public'));
        }
    }
    return $dir;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path_prefix() . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

/** Absolute URL (used for media links sent to social networks and email). */
function abs_url(string $path = '/'): string
{
    return rtrim((string) Env::get('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file) . APP_VERSION), 0, 8) : APP_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function flash(string $key): mixed
{
    return Session::getFlash($key);
}

function old(string $key, string $default = ''): string
{
    $old = Session::getFlash('old');
    return is_array($old) && isset($old[$key]) && is_scalar($old[$key]) ? (string) $old[$key] : $default;
}

function app_name(): string
{
    try {
        return (string) (Settings::get('app_name') ?: Env::get('APP_NAME', 'NextaPost'));
    } catch (\Throwable) {
        return (string) Env::get('APP_NAME', 'NextaPost');
    }
}

function now_utc(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function to_local(?string $utc, string $tz, string $format = 'Y-m-d H:i'): string
{
    if (!$utc) {
        return '';
    }
    try {
        $dt = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
        return localize_date_names($dt, $format, $dt->format($format));
    } catch (\Throwable) {
        return '';
    }
}

/**
 * Swaps English day and month names (format letters D, l, M, F) for the interface language's,
 * when PHP's intl extension is available. Numbers and other formats are left alone.
 */
function localize_date_names(DateTimeInterface $dt, string $format, string $out): string
{
    $lang = \App\Core\Lang::current();
    if ($lang === 'en' || !class_exists(\IntlDateFormatter::class) || !preg_match('/(?<!\\\\)[DlMF]/', $format)) {
        return $out;
    }
    $pairs = [];
    foreach (['l' => 'EEEE', 'F' => 'MMMM', 'D' => 'EEE', 'M' => 'MMM'] as $letter => $pattern) {
        if (!str_contains($format, $letter)) {
            continue;
        }
        $f = new \IntlDateFormatter($lang, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $dt->getTimezone()->getName(), null, $pattern);
        $local = $f->format($dt);
        if (is_string($local) && $local !== '') {
            $pairs[$dt->format($letter)] = $local;
        }
    }
    // Longest English names first, so "May" inside "Mayday" style overlaps can't happen out of order.
    uksort($pairs, fn ($a, $b) => strlen($b) <=> strlen($a));
    return strtr($out, $pairs);
}

/** Short weekday name ("mon" … "sun") in the interface language. */
function weekday_name(string $key): string
{
    $offset = array_search($key, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true);
    if ($offset === false) {
        return ucfirst($key);
    }
    return to_local(sprintf('2024-01-%02d 12:00:00', 1 + $offset), 'UTC', 'D'); // 1 Jan 2024 was a Monday
}

/** Converts a wall-clock time in $tz ("2026-11-20T10:30", "2026-11-20 10:30:00", trailing Z ignored) to UTC. */
function to_utc(string $local, string $tz): ?string
{
    $local = trim((string) preg_replace('/(\.\d+)?Z$/', '', trim($local)));
    $local = str_replace('T', ' ', $local);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local)) {
        $local .= ':00';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $local)) {
        return null;
    }
    try {
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, new DateTimeZone($tz));
    } catch (\Throwable) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function valid_timezone(string $tz): bool
{
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function text_length(string $text): int
{
    if (function_exists('grapheme_strlen')) {
        $n = grapheme_strlen($text);
        if (is_int($n)) {
            return $n;
        }
    }
    return mb_strlen($text);
}

function str_excerpt(?string $text, int $len = 80): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    return mb_strlen($text) > $len ? rtrim(mb_substr($text, 0, $len - 1)) . '…' : $text;
}

function initials(string $name): string
{
    $parts = preg_split('/[\s@._-]+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    $i = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    return $i ?: '?';
}

function human_bytes(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return ($i ? number_format($n, 1) : (string) (int) $n) . ' ' . $u[$i];
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return __('never');
    }
    $s = time() - strtotime($utc . ' UTC');
    return match (true) {
        $s < 60 => __('just now'),
        $s < 3600 => __('{n} min ago', ['n' => intdiv($s, 60)]),
        $s < 86400 => __('{n} h ago', ['n' => intdiv($s, 3600)]),
        default => __('{n} d ago', ['n' => intdiv($s, 86400)]),
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => __('Draft'), 'in_review' => __('In review'), 'scheduled' => __('Scheduled'), 'publishing' => __('Publishing'),
        'published' => __('Published'), 'partially_published' => __('Partly published'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled'),
        'pending' => __('Waiting'), 'queued' => __('Queued'),
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function status_badge(string $status): string
{
    return '<span class="badge st-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

/** Inline Lucide-style icons (ISC licensed shapes, redrawn). */
function icon(string $name, string $class = 'size-5'): string
{
    static $paths = [
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'repeat' => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'sparkles' => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14 9l2 2"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'bulb' => '<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V18h6v-1.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"/>',
        'rss' => '<path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>',
        'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'plug' => '<path d="M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'settings' => '<path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'trash' => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'pencil' => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'server' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'grip' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
        'arrow-left' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'arrow-right' => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    ];
    $p = $paths[$name] ?? $paths['alert'];
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="' . e($class) . '" aria-hidden="true">' . $p . '</svg>';
}

function network_meta(string $network): array
{
    return ProviderFactory::meta($network);
}

/** Channel avatar with a small network badge. */
function channel_avatar(array $ch, string $size = 'size-9'): string
{
    $meta = network_meta((string) $ch['network']);
    $avatar = safe_url($ch['avatar_url'] ?? null);
    $img = $avatar
        ? '<img src="' . e($avatar) . '" alt="" class="size-full rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer">'
        : '<span class="flex size-full items-center justify-center rounded-full text-[11px] font-semibold text-white" style="background:' . e($meta['color']) . '">' . e(initials((string) $ch['name'])) . '</span>';
    return '<span class="relative inline-flex shrink-0 ' . e($size) . '" title="' . e($ch['name'] . ' · ' . $meta['name']) . '">' . $img
        . '<span class="absolute -bottom-0.5 -right-0.5 flex size-3.5 items-center justify-center rounded-full text-[8px] font-bold text-white ring-2 ring-[var(--np-panel)]" style="background:' . e($meta['color']) . '">' . e($meta['letter']) . '</span></span>';
}

/** Returns the URL only if it is an absolute http(s) URL — blocks javascript:, data: and other schemes from untrusted sources. */
function safe_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '' || mb_strlen($url) > 2000 || preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) ? $url : null;
}

/** Per-request nonce for the few inline <script> blocks allowed by the Content-Security-Policy. */
function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(6));
}

function json_attr(mixed $data): string
{
    return e((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
}

function selected(bool $cond): string
{
    return $cond ? ' selected' : '';
}

function log_activity(?int $workspaceId, string $action, string $message, ?string $subject = null, ?int $subjectId = null): void
{
    try {
        app()->db->insert('activity_log', [
            'workspace_id' => $workspaceId,
            'user_id' => \App\Core\Auth::id(),
            'action' => $action,
            'subject' => $subject,
            'subject_id' => $subjectId,
            'message' => mb_substr($message, 0, 2000),
            'ip' => PHP_SAPI === 'cli' ? null : ($_SERVER['REMOTE_ADDR'] ?? null),
            'created_at' => now_utc(),
        ]);
    } catch (\Throwable) {
        // Activity logging must never break the request.
    }
}

/** Translate interface text (English text is the key). */
function __(string $text, array $vars = []): string
{
    return \App\Core\Lang::translate($text, $vars);
}

/** A string as a JavaScript literal for use inside an Alpine attribute (quotes and HTML escaped). */
function js(string $text): string
{
    return e((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
}

/** 1234 → 1.2K, 2500000 → 2.5M */
function short_number(int $n): string
{
    foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $div => $suffix) {
        if (abs($n) >= $div) {
            return rtrim(rtrim(number_format($n / $div, 1, '.', ''), '0'), '.') . $suffix;
        }
    }
    return (string) $n;
}

/**
 * Translate a sentence and insert HTML pieces (links, bold names) for its {placeholders}.
 * The sentence itself is escaped; each piece must already be safe HTML.
 */
function __html(string $text, array $html = [], array $vars = []): string
{
    $out = e(__($text, $vars));
    foreach ($html as $k => $piece) {
        $out = str_replace('{' . $k . '}', (string) $piece, $out);
    }
    return $out;
}

/** A LIKE '%…%' pattern for user search text: trimmed, capped at 100 characters, wildcards escaped (use with ESCAPE '!'). */
function like_pattern(string $search): string
{
    $search = mb_substr(trim($search), 0, 100);
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
}

/** Text from untrusted input: scalars become strings, anything else (arrays from crafted forms) becomes $default. */
function str_val(mixed $v, string $default = ''): string
{
    return is_scalar($v) ? (string) $v : $default;
}

/** A same-site path that is safe to redirect to ("/…", never "//…", "/\\…", a backslash or control characters); otherwise $fallback. */
function safe_local_path(mixed $to, string $fallback = '/'): string
{
    if (!is_string($to) || $to === '' || $to[0] !== '/' || str_starts_with($to, '//') || strpbrk($to, "\\\r\n\t\0") !== false) {
        return $fallback;
    }
    return $to;
}

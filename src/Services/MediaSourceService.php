<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Http;
use App\Core\Settings;
use App\Providers\ProviderException;

/**
 * Bring media into the library from Unsplash, Canva, Google Photos and Google Drive.
 * Canva and Google are connected per person (their own account); Unsplash uses the site's access key.
 */
final class MediaSourceService
{
    /** Keys entered in System → Integrations → Media sources. */
    public const APPS = [
        'unsplash' => ['name' => 'Unsplash', 'id_label' => 'Access key', 'secret' => false, 'docs' => 'https://unsplash.com/oauth/applications',
            'help' => 'Create an Unsplash application and paste its Access Key. Demo apps allow 50 searches an hour; apply for production (free) for 5,000. Photos are credited to their photographer automatically.'],
        'canva' => ['name' => 'Canva', 'id_label' => 'Client ID', 'secret_label' => 'Client secret', 'docs' => 'https://www.canva.com/developers/integrations',
            'help' => 'Create a Canva Connect integration, add this redirect URL, and enable the scopes design:meta:read and design:content:read. Until Canva reviews the integration only its team members can connect.'],
        'google_picker' => ['name' => 'Google Drive picker', 'id_label' => 'API key', 'secret_label' => 'Project number (App ID)', 'secret_visible' => true, 'docs' => 'https://console.cloud.google.com/apis/credentials',
            'help' => 'Google Photos and Drive use the Google OAuth client above: add this redirect URL to it and enable the “Google Photos Picker API”, “Google Drive API” and “Google Picker API”. For Drive also create an API key (restrict it to your domain and the Picker API) and enter your Cloud project number.'],
    ];

    private const CANVA_API = 'https://api.canva.com/rest/v1/';
    private const PHOTOS_API = 'https://photospicker.googleapis.com/v1/';
    private const GOOGLE_SCOPES = ['https://www.googleapis.com/auth/photospicker.mediaitems.readonly', 'https://www.googleapis.com/auth/drive.file'];

    public function __construct(private DB $db)
    {
    }

    // ---------------------------------------------------------------- configuration

    public static function setting(string $key): array
    {
        $c = ((array) Settings::get('integrations', []))[$key] ?? [];
        if (empty($c['client_id'])) {
            return [];
        }
        $out = ['client_id' => (string) $c['client_id']];
        if (!empty($c['client_secret_enc'])) {
            try {
                $out['client_secret'] = Crypto::decrypt((string) $c['client_secret_enc']);
            } catch (\Throwable) {
                return [];
            }
        }
        return $out;
    }

    public static function redirectUri(string $source): string
    {
        return abs_url('media-sources/' . $source . '/callback');
    }

    /** Which sources this site can offer. */
    public static function available(): array
    {
        $google = self::setting('google');
        $picker = self::setting('google_picker');
        return [
            'unsplash' => self::setting('unsplash') !== [],
            'canva' => !empty(self::setting('canva')['client_secret']),
            'google_photos' => !empty($google['client_secret']),
            'google_drive' => !empty($google['client_secret']) && !empty($picker['client_id']) && !empty($picker['client_secret']),
        ];
    }

    // ---------------------------------------------------------------- per-person connections

    public function connection(int $userId, string $source): ?array
    {
        $row = $this->db->one('SELECT * FROM media_connections WHERE user_id = ? AND source = ?', [$userId, $source]);
        if (!$row) {
            return null;
        }
        try {
            $row['credentials'] = Crypto::decryptJson((string) $row['credentials_enc']);
        } catch (\Throwable) {
            return null;
        }
        return $row;
    }

    private function saveConnection(int $userId, string $source, array $creds, ?string $name): void
    {
        $data = ['credentials_enc' => Crypto::encryptJson($creds), 'account_name' => $name !== null ? mb_substr($name, 0, 200) : null, 'updated_at' => now_utc()];
        if ($this->db->value('SELECT 1 FROM media_connections WHERE user_id = ? AND source = ?', [$userId, $source])) {
            $this->db->update('media_connections', $data, 'user_id = ? AND source = ?', [$userId, $source]);
        } else {
            $this->db->insert('media_connections', $data + ['user_id' => $userId, 'source' => $source, 'created_at' => now_utc()]);
        }
    }

    public function disconnect(int $userId, string $source): void
    {
        $this->db->run('DELETE FROM media_connections WHERE user_id = ? AND source = ?', [$userId, $source]);
    }

    /** @return string URL to send the person to */
    public function authorizeUrl(string $source, string $state, string $challenge): string
    {
        if ($source === 'canva') {
            $app = self::setting('canva');
            return 'https://www.canva.com/api/oauth/authorize?' . http_build_query([
                'client_id' => $app['client_id'] ?? '', 'redirect_uri' => self::redirectUri('canva'), 'response_type' => 'code', 'state' => $state,
                'scope' => 'design:meta:read design:content:read', 'code_challenge' => $challenge, 'code_challenge_method' => 's256',
            ]);
        }
        return \App\Providers\GoogleOAuth::authorizeUrl(self::setting('google'), self::redirectUri('google'), $state, $challenge, self::GOOGLE_SCOPES);
    }

    public function callback(int $userId, string $source, array $query, string $verifier): void
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('Sign-in was cancelled.'));
        }
        if ($source === 'canva') {
            $t = $this->canvaToken(['grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'code_verifier' => $verifier, 'redirect_uri' => self::redirectUri('canva')]);
            $name = null;
            try {
                $p = Http::request('GET', self::CANVA_API . 'users/me/profile', ['headers' => ['Authorization: Bearer ' . $t['access_token']]]);
                $name = $p['json']['profile']['display_name'] ?? null;
            } catch (\Throwable) {
            }
            $this->saveConnection($userId, 'canva', $t, $name);
            return;
        }
        $creds = \App\Providers\GoogleOAuth::exchange(self::setting('google'), $query, self::redirectUri('google'), $verifier, 'Google');
        $this->saveConnection($userId, 'google', $creds, null);
    }

    private function canvaToken(array $form): array
    {
        $app = self::setting('canva');
        $r = Http::request('POST', self::CANVA_API . 'oauth/token', ['headers' => ['Authorization: Basic ' . base64_encode(($app['client_id'] ?? '') . ':' . ($app['client_secret'] ?? ''))], 'form' => $form]);
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            throw new ProviderException('Canva: ' . (string) ($r['json']['error_description'] ?? $r['json']['message'] ?? 'sign-in failed'), $r['status'] >= 500, true);
        }
        return ['access_token' => (string) $r['json']['access_token'], 'refresh_token' => (string) ($r['json']['refresh_token'] ?? ''), 'expires_at' => time() + (int) ($r['json']['expires_in'] ?? 14400)];
    }

    /** A fresh access token for a connected source, refreshing and saving it if needed. */
    public function token(int $userId, string $source): string
    {
        $c = $this->connection($userId, $source) ?? throw new ProviderException(__('Connect your {source} account first.', ['source' => $source === 'canva' ? 'Canva' : 'Google']), false, true);
        $creds = $c['credentials'];
        if ((int) ($creds['expires_at'] ?? 0) - time() > 120) {
            return (string) $creds['access_token'];
        }
        try {
            $new = $source === 'canva'
                ? $this->canvaToken(['grant_type' => 'refresh_token', 'refresh_token' => (string) $creds['refresh_token']])
                : (\App\Providers\GoogleOAuth::refresh($creds, self::setting('google'), 'Google') ?? []) + $creds;
        } catch (ProviderException $e) {
            if ($e->authError) {
                $this->disconnect($userId, $source);
            }
            throw $e;
        }
        $this->saveConnection($userId, $source, $new + $creds, $c['account_name']);
        return (string) $new['access_token'];
    }

    // ---------------------------------------------------------------- import helper

    /** Download a URL into the library. */
    private function importUrl(array $ws, int $userId, string $url, string $name, string $source, ?array $attribution, ?string $alt, array $headers = [], bool $publicOnly = true): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'npms');
        try {
            $r = Http::request('GET', $url, ['sink' => $tmp, 'public_only' => $publicOnly, 'headers' => $headers, 'timeout' => 300, 'max_bytes' => MediaService::maxBytes()]);
            if ($r['status'] !== 200) {
                throw new ProviderException(__('The file couldn’t be downloaded (HTTP {code}).', ['code' => $r['status']]));
            }
            $name = preg_replace('/[^\w.\- ]+/u', '', basename($name)) ?: 'import';
            $m = (new MediaService($this->db))->upload($ws, $userId, ['tmp_name' => $tmp, 'name' => mb_substr($name, 0, 200), 'error' => UPLOAD_ERR_OK], true);
        } catch (\InvalidArgumentException $e) {
            throw new ProviderException($e->getMessage());
        } finally {
            @unlink($tmp);
        }
        $upd = ['source' => $source];
        if ($attribution) {
            $upd['attribution'] = json_encode($attribution, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($alt !== null && trim($alt) !== '') {
            $upd['alt_text'] = mb_substr(trim($alt), 0, 1000);
        }
        $this->db->update('media', $upd, 'id = ?', [$m['id']]);
        return (array) $this->db->one('SELECT * FROM media WHERE id = ?', [$m['id']]);
    }

    // ---------------------------------------------------------------- Unsplash

    private function unsplash(string $path): array
    {
        $key = self::setting('unsplash')['client_id'] ?? throw new ProviderException(__('Unsplash isn’t set up on this site.'));
        $r = Http::request('GET', 'https://api.unsplash.com/' . ltrim($path, '/'), ['headers' => ['Authorization: Client-ID ' . $key, 'Accept-Version: v1']]);
        if ($r['status'] === 403 && str_contains((string) $r['body'], 'Rate Limit')) {
            throw new ProviderException(__('Unsplash’s hourly search limit was reached. Try again in a little while.'), true);
        }
        if ($r['status'] !== 200) {
            throw ProviderException::fromResponse('Unsplash', $r);
        }
        return (array) $r['json'];
    }

    public function unsplashSearch(string $query, int $page = 1): array
    {
        $query = trim(mb_substr($query, 0, 100));
        if ($query === '') {
            return ['results' => [], 'total_pages' => 0];
        }
        $j = $this->unsplash('search/photos?' . http_build_query(['query' => $query, 'page' => max(1, min(50, $page)), 'per_page' => 24, 'content_filter' => 'high']));
        return [
            'total_pages' => (int) ($j['total_pages'] ?? 0),
            'results' => array_map(static fn ($p) => [
                'id' => (string) $p['id'], 'thumb' => (string) ($p['urls']['small'] ?? ''), 'alt' => (string) ($p['alt_description'] ?? ''),
                'author' => (string) ($p['user']['name'] ?? ''), 'author_url' => (string) ($p['user']['links']['html'] ?? ''), 'width' => (int) ($p['width'] ?? 0), 'height' => (int) ($p['height'] ?? 0),
            ], (array) ($j['results'] ?? [])),
        ];
    }

    public function unsplashImport(array $ws, int $userId, string $photoId): array
    {
        if (!preg_match('/^[\w\-]{5,40}$/', $photoId)) {
            throw new ProviderException(__('Unknown photo.'));
        }
        $p = $this->unsplash('photos/' . $photoId);
        // Unsplash asks apps to trigger the download endpoint whenever a photo is used.
        try {
            $this->unsplash(ltrim((string) parse_url((string) ($p['links']['download_location'] ?? ''), PHP_URL_PATH), '/') . '?' . (string) parse_url((string) ($p['links']['download_location'] ?? ''), PHP_URL_QUERY));
        } catch (\Throwable) {
        }
        $url = (string) ($p['urls']['regular'] ?? '');
        $url .= (str_contains($url, '?') ? '&' : '?') . 'w=2160&fm=jpg&q=85';
        $utm = '?utm_source=' . rawurlencode(app_name()) . '&utm_medium=referral';
        return $this->importUrl($ws, $userId, $url, 'unsplash-' . $photoId . '.jpg', 'unsplash', [
            'provider' => 'Unsplash', 'author' => (string) ($p['user']['name'] ?? ''), 'author_url' => (string) ($p['user']['links']['html'] ?? '') . $utm,
            'source_url' => (string) ($p['links']['html'] ?? '') . $utm,
        ], (string) ($p['alt_description'] ?? ''));
    }

    // ---------------------------------------------------------------- Canva

    private function canva(int $userId, string $method, string $path, ?array $json = null): array
    {
        $opts = ['headers' => ['Authorization: Bearer ' . $this->token($userId, 'canva')]];
        if ($json !== null) {
            $opts['json'] = $json;
        }
        $r = Http::request($method, self::CANVA_API . ltrim($path, '/'), $opts);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw ProviderException::fromResponse('Canva', $r);
        }
        return (array) $r['json'];
    }

    public function canvaDesigns(int $userId, string $query = '', ?string $continuation = null): array
    {
        $q = array_filter(['query' => mb_substr(trim($query), 0, 100), 'continuation' => $continuation, 'ownership' => 'any', 'sort_by' => 'modified_descending']);
        $j = $this->canva($userId, 'GET', 'designs?' . http_build_query($q));
        return [
            'continuation' => $j['continuation'] ?? null,
            'designs' => array_map(static fn ($d) => ['id' => (string) $d['id'], 'title' => (string) ($d['title'] ?? 'Untitled design'), 'thumb' => (string) ($d['thumbnail']['url'] ?? ''), 'pages' => (int) ($d['page_count'] ?? 1)], (array) ($j['items'] ?? [])),
        ];
    }

    /** Export a design (each page becomes a file) and import the result. @return array media rows */
    public function canvaImport(array $ws, int $userId, string $designId, string $format = 'png'): array
    {
        if (!preg_match('/^[\w\-]{5,60}$/', $designId)) {
            throw new ProviderException(__('Unknown design.'));
        }
        $format = in_array($format, ['png', 'jpg', 'pdf', 'mp4'], true) ? $format : 'png';
        $spec = ['type' => $format] + ($format === 'jpg' ? ['quality' => 90] : []) + ($format === 'mp4' ? ['quality' => 'horizontal_1080p'] : []);
        $job = $this->canva($userId, 'POST', 'exports', ['design_id' => $designId, 'format' => $spec])['job'] ?? [];
        for ($i = 0; $i < 60 && ($job['status'] ?? '') === 'in_progress'; $i++) {
            sleep(2);
            $job = $this->canva($userId, 'GET', 'exports/' . rawurlencode((string) $job['id']))['job'] ?? [];
        }
        if (($job['status'] ?? '') !== 'success') {
            throw new ProviderException('Canva: ' . (string) ($job['error']['message'] ?? __('the export didn’t finish. Try again.')), ($job['status'] ?? '') === 'in_progress');
        }
        $rows = [];
        foreach (array_slice((array) ($job['urls'] ?? []), 0, 10) as $n => $url) {
            $rows[] = $this->importUrl($ws, $userId, (string) $url, 'canva-' . $designId . '-' . ($n + 1) . '.' . $format, 'canva', ['provider' => 'Canva', 'design_id' => $designId], null);
        }
        return $rows;
    }

    // ---------------------------------------------------------------- Google Photos (Picker API)

    private function photos(int $userId, string $method, string $path, mixed $json = null): array
    {
        $opts = ['headers' => ['Authorization: Bearer ' . $this->token($userId, 'google')]];
        if ($json !== null) {
            $opts['json'] = $json;
        }
        $r = Http::request($method, self::PHOTOS_API . ltrim($path, '/'), $opts);
        return \App\Providers\GoogleOAuth::check($r, 'Google Photos');
    }

    /** Start a picking session: the person chooses photos at pickerUri. */
    public function photosSession(int $userId): array
    {
        $s = $this->photos($userId, 'POST', 'sessions', (object) []);
        return ['id' => (string) $s['id'], 'picker_uri' => (string) $s['pickerUri'], 'done' => !empty($s['mediaItemsSet'])];
    }

    public function photosPoll(int $userId, string $sessionId): bool
    {
        if (!preg_match('/^[\w\-]{5,100}$/', $sessionId)) {
            throw new ProviderException(__('Unknown session.'));
        }
        return !empty($this->photos($userId, 'GET', 'sessions/' . $sessionId)['mediaItemsSet']);
    }

    public function photosImport(array $ws, int $userId, string $sessionId): array
    {
        if (!$this->photosPoll($userId, $sessionId)) {
            throw new ProviderException(__('Choose photos in Google Photos first.'));
        }
        $items = (array) ($this->photos($userId, 'GET', 'mediaItems?' . http_build_query(['sessionId' => $sessionId, 'pageSize' => 50]))['mediaItems'] ?? []);
        $token = $this->token($userId, 'google');
        $rows = [];
        foreach (array_slice($items, 0, 30) as $it) {
            $file = $it['mediaFile'] ?? [];
            $video = ($it['type'] ?? '') === 'VIDEO';
            $base = (string) ($file['baseUrl'] ?? '');
            if (!str_starts_with($base, 'https://')) {
                continue;
            }
            $rows[] = $this->importUrl($ws, $userId, $base . ($video ? '=dv' : '=d'), (string) ($file['filename'] ?? 'photo.jpg'), 'google_photos', ['provider' => 'Google Photos'], null, ['Authorization: Bearer ' . $token]);
        }
        try {
            $this->photos($userId, 'DELETE', 'sessions/' . $sessionId);
        } catch (\Throwable) {
        }
        return $rows;
    }

    // ---------------------------------------------------------------- Google Drive (Picker in the browser, download here)

    /** What the browser needs to open Google's file picker. */
    public function drivePickerConfig(int $userId): array
    {
        $p = self::setting('google_picker');
        return ['token' => $this->token($userId, 'google'), 'api_key' => (string) ($p['client_id'] ?? ''), 'app_id' => (string) ($p['client_secret'] ?? '')];
    }

    public function driveImport(array $ws, int $userId, array $fileIds): array
    {
        $token = $this->token($userId, 'google');
        $rows = [];
        foreach (array_slice(array_unique(array_filter($fileIds, fn ($f) => is_string($f) && preg_match('/^[\w\-]{10,100}$/', $f))), 0, 20) as $id) {
            $meta = \App\Providers\GoogleOAuth::check(Http::request('GET', 'https://www.googleapis.com/drive/v3/files/' . $id . '?fields=name,mimeType,size&supportsAllDrives=true', ['headers' => ['Authorization: Bearer ' . $token]]), 'Google Drive');
            $rows[] = $this->importUrl($ws, $userId, 'https://www.googleapis.com/drive/v3/files/' . $id . '?alt=media&supportsAllDrives=true', (string) ($meta['name'] ?? 'drive-file'), 'google_drive', ['provider' => 'Google Drive'], null, ['Authorization: Bearer ' . $token]);
        }
        return $rows;
    }
}

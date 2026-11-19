<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;

final class MastodonProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Mastodon', 'color' => '#6364FF', 'letter' => 'M', 'kind' => 'token',
            'description' => 'Post to any Mastodon server with an access token.'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'instance_url', 'label' => 'Server address', 'type' => 'url', 'placeholder' => 'https://mastodon.social', 'help' => 'The server your account lives on.'],
            ['name' => 'access_token', 'label' => 'Access token', 'type' => 'password', 'placeholder' => '', 'help' => 'Preferences → Development → New application (scopes: read:accounts, write:statuses, write:media) → copy “Your access token”.'],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => (int) ($meta['max_chars'] ?? 500), 'media_max' => 4, 'types' => ['image', 'video'], 'single_video' => true,
            'max_bytes' => ['image' => 16 * 1024 * 1024, 'video' => 99 * 1024 * 1024]];
    }

    private static function base(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    public function connect(array $input): array
    {
        $this->require($input, 'instance_url', 'access_token');
        $base = self::base((string) $input['instance_url']);
        if (!str_starts_with($base, 'https://')) {
            throw new ProviderException('Use the https:// address of your Mastodon server.');
        }
        Http::assertPublicUrl($base);
        $token = trim((string) $input['access_token']);
        $auth = ['Authorization: Bearer ' . $token];
        $acct = $this->ok($this->http('GET', $base . '/api/v1/accounts/verify_credentials', ['headers' => $auth, 'public_only' => true]), 'That token was rejected. Check the server address and token.');
        $max = 500;
        try {
            $inst = $this->http('GET', $base . '/api/v2/instance', ['timeout' => 15, 'public_only' => true]);
            $max = (int) ($inst['json']['configuration']['statuses']['max_characters'] ?? 500) ?: 500;
        } catch (\Throwable) {
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        return [
            'external_id' => (string) $acct['id'] . '@' . $host,
            'name' => (string) (($acct['display_name'] ?? '') ?: $acct['username']),
            'username' => '@' . $acct['username'] . '@' . $host,
            'avatar_url' => $acct['avatar'] ?? null,
            'credentials' => ['instance_url' => $base, 'access_token' => $token],
            'meta' => ['max_chars' => $max],
        ];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $base = (string) $credentials['instance_url'];
        $auth = ['Authorization: Bearer ' . $credentials['access_token']];
        $ids = [];
        foreach ($media as $m) {
            $parts = ['file' => new \CURLFile($m['path'], $m['mime'], basename($m['path']))];
            if (!empty($m['alt'])) {
                $parts['description'] = mb_substr((string) $m['alt'], 0, 1500);
            }
            $j = $this->ok($this->http('POST', $base . '/api/v2/media', ['headers' => $auth, 'multipart' => $parts, 'timeout' => 180, 'public_only' => true]));
            $id = (string) $j['id'];
            // Large files are processed asynchronously: wait until a URL is available.
            for ($i = 0; $i < 30 && empty($j['url']); $i++) {
                sleep(2);
                $r = $this->http('GET', $base . '/api/v1/media/' . rawurlencode($id), ['headers' => $auth, 'public_only' => true]);
                $j = is_array($r['json']) ? $r['json'] : [];
                if ($r['status'] === 200 && !empty($j['url'])) {
                    break;
                }
            }
            $ids[] = $id;
        }
        $headers = $auth;
        if (!empty($context['idempotency_key'])) {
            $headers[] = 'Idempotency-Key: ' . $context['idempotency_key'];
        }
        $body = ['status' => $text, 'visibility' => 'public'];
        if ($ids) {
            $body['media_ids'] = $ids;
        }
        $j = $this->ok($this->http('POST', $base . '/api/v1/statuses', ['headers' => $headers, 'json' => $body, 'public_only' => true]));
        return new PublishResult((string) $j['id'], $j['url'] ?? null);
    }
}

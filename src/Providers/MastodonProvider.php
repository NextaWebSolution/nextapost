<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;

final class MastodonProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Mastodon', 'color' => '#6364FF', 'letter' => 'M', 'kind' => 'token',
            'description' => 'Post to any Mastodon server with an access token.',
            'content_types' => ['post' => __('Post'), 'poll' => __('Poll')],
            'content_rules' => ['poll' => ['media' => 'none']],
        ];
    }

    public function fields(): array
    {
        return [
            ['name' => 'instance_url', 'label' => 'Server address', 'type' => 'url', 'placeholder' => 'https://mastodon.social', 'help' => 'The server your account lives on.'],
            ['name' => 'access_token', 'label' => 'Access token', 'type' => 'password', 'placeholder' => '', 'help' => 'Preferences → Development → New application (scopes: read:accounts, write:statuses, write:media) → copy “Your access token”.'],
        ];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'poll', 'label' => __('Poll choices'), 'type' => 'list', 'types' => ['poll'], 'required' => true, 'min' => 2, 'max' => 4, 'item_max' => 50, 'placeholder' => __('Choice')],
            ['key' => 'poll_seconds', 'label' => __('Poll length'), 'type' => 'select', 'types' => ['poll'], 'required' => true, 'default' => '86400',
                'choices' => ['3600' => __('1 hour'), '21600' => __('6 hours'), '86400' => __('1 day'), '259200' => __('3 days'), '604800' => __('7 days')]],
            ['key' => 'poll_multiple', 'label' => __('Allow several choices'), 'type' => 'checkbox', 'types' => ['poll'], 'default' => false],
        ];
    }

    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        $j = $this->ok($this->http('GET', rtrim((string) $credentials['instance_url'], '/') . '/api/v1/statuses/' . rawurlencode($externalId), ['headers' => ['Authorization: Bearer ' . $credentials['access_token']], 'public_only' => true]));
        return ['likes' => (int) ($j['favourites_count'] ?? 0), 'comments' => (int) ($j['replies_count'] ?? 0), 'shares' => (int) ($j['reblogs_count'] ?? 0)];
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
        $base = self::base(str_val($input['instance_url']));
        if (!str_starts_with($base, 'https://')) {
            throw new ProviderException('Use the https:// address of your Mastodon server.');
        }
        Http::assertPublicUrl($base);
        $token = trim(str_val($input['access_token']));
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
        foreach ($this->type($context) === 'poll' ? [] : $media as $m) {
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
        if ($this->type($context) === 'poll') {
            unset($body['media_ids']);
            $body['poll'] = [
                'options' => array_slice(array_values(array_filter(array_map('trim', (array) $this->opt($context, 'poll', [])), 'strlen')), 0, 4),
                'expires_in' => (int) $this->opt($context, 'poll_seconds', 86400),
                'multiple' => filter_var($this->opt($context, 'poll_multiple', false), FILTER_VALIDATE_BOOL),
            ];
        }
        $j = $this->ok($this->http('POST', $base . '/api/v1/statuses', ['headers' => $headers, 'json' => $body, 'public_only' => true]));
        return new PublishResult((string) $j['id'], $j['url'] ?? null);
    }
}

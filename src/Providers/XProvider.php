<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * X (Twitter) via OAuth 2.0 with PKCE. Note: X API posting is pay-per-use for new developer
 * accounts (billed by X to the app owner).
 */
final class XProvider extends AbstractProvider
{
    private const API = 'https://api.x.com/2';

    public function meta(): array
    {
        return ['name' => 'X (Twitter)', 'color' => '#0F1419', 'letter' => 'X', 'kind' => 'oauth', 'app' => 'x',
            'description' => 'Post to X. X bills API usage to the app owner (pay-per-use).',
            'content_types' => ['post' => __('Post'), 'poll' => __('Poll')],
            'content_rules' => ['poll' => ['media' => 'none']],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => (int) ($meta['max_chars'] ?? 280), 'media_max' => 4, 'types' => ['image'], 'max_bytes' => ['image' => 5 * 1024 * 1024]];
    }

    /** X counts every URL as 23 characters. */
    protected function countText(string $text): int
    {
        return text_length((string) preg_replace('#https?://\S+#u', str_repeat('x', 23), $text));
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://x.com/i/oauth2/authorize?' . http_build_query([
            'response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri,
            'scope' => 'tweet.read tweet.write users.read media.write offline.access', 'state' => $state,
            'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256',
        ]);
    }

    private function tokenRequest(array $app, array $form): array
    {
        $headers = [];
        if (!empty($app['client_secret'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($app['client_id'] . ':' . $app['client_secret']);
        }
        return $this->ok($this->http('POST', self::API . '/oauth2/token', ['headers' => $headers, 'form' => $form + ['client_id' => $app['client_id']]]));
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException('X sign-in was cancelled: ' . ($query['error_description'] ?? $query['error']));
        }
        $tok = $this->tokenRequest($app, ['grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $redirectUri, 'code_verifier' => $codeVerifier]);
        $me = $this->ok($this->http('GET', self::API . '/users/me?user.fields=profile_image_url,verified', ['headers' => ['Authorization: Bearer ' . $tok['access_token']]]))['data'] ?? [];
        return [[
            'external_id' => (string) $me['id'], 'name' => (string) $me['name'], 'username' => '@' . $me['username'],
            'avatar_url' => $me['profile_image_url'] ?? null,
            'credentials' => ['access_token' => $tok['access_token'], 'refresh_token' => $tok['refresh_token'] ?? null, 'expires_at' => time() + (int) ($tok['expires_in'] ?? 7200)],
            'meta' => ['username' => $me['username']],
        ]];
    }

    public function refresh(array $credentials, array $app): ?array
    {
        if ((int) ($credentials['expires_at'] ?? 0) > time() + 300) {
            return null;
        }
        if (empty($credentials['refresh_token'])) {
            throw new ProviderException('X: the connection expired. Reconnect this channel.', false, true);
        }
        try {
            $tok = $this->tokenRequest($app, ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token']]);
        } catch (ProviderException $e) {
            throw new ProviderException('X: could not renew the connection — reconnect this channel. (' . $e->getMessage() . ')', $e->retryable, !$e->retryable);
        }
        return ['access_token' => $tok['access_token'], 'refresh_token' => $tok['refresh_token'] ?? $credentials['refresh_token'], 'expires_at' => time() + (int) ($tok['expires_in'] ?? 7200)];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'poll', 'label' => __('Poll choices'), 'type' => 'list', 'types' => ['poll'], 'required' => true, 'min' => 2, 'max' => 4, 'item_max' => 25, 'placeholder' => __('Choice')],
            ['key' => 'poll_minutes', 'label' => __('Poll length'), 'type' => 'select', 'types' => ['poll'], 'required' => true, 'default' => '1440',
                'choices' => ['60' => __('1 hour'), '360' => __('6 hours'), '1440' => __('1 day'), '4320' => __('3 days'), '10080' => __('7 days')]],
        ];
    }

    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        $j = $this->ok($this->http('GET', self::API . '/tweets/' . rawurlencode($externalId) . '?tweet.fields=public_metrics', ['headers' => ['Authorization: Bearer ' . $credentials['access_token']]]));
        $pm = $j['data']['public_metrics'] ?? null;
        return $pm ? ['likes' => (int) ($pm['like_count'] ?? 0), 'comments' => (int) ($pm['reply_count'] ?? 0), 'shares' => (int) ($pm['retweet_count'] ?? 0) + (int) ($pm['quote_count'] ?? 0), 'impressions' => isset($pm['impression_count']) ? (int) $pm['impression_count'] : null] : null;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $auth = 'Authorization: Bearer ' . $credentials['access_token'];
        if ($this->type($context) === 'poll') {
            $media = [];
        }
        $ids = [];
        foreach ($media as $m) {
            $j = $this->ok($this->http('POST', self::API . '/media/upload', ['headers' => [$auth], 'multipart' => [
                'media' => new \CURLFile($m['path'], $m['mime'], basename($m['path'])),
                'media_category' => 'tweet_image',
            ], 'timeout' => 120]));
            $id = $j['data']['id'] ?? $j['media_id_string'] ?? null;
            if (!$id) {
                throw new ProviderException('X: media upload returned no id.', true);
            }
            $ids[] = (string) $id;
        }
        $body = ['text' => $text];
        if ($ids) {
            $body['media'] = ['media_ids' => $ids];
        }
        if ($this->type($context) === 'poll') {
            $choices = array_values(array_filter(array_map('trim', (array) $this->opt($context, 'poll', [])), 'strlen'));
            $body['poll'] = ['options' => array_slice($choices, 0, 4), 'duration_minutes' => (int) $this->opt($context, 'poll_minutes', 1440)];
        }
        $j = $this->ok($this->http('POST', self::API . '/tweets', ['headers' => [$auth], 'json' => $body]));
        $id = (string) ($j['data']['id'] ?? '');
        $user = $meta['username'] ?? 'i';
        return new PublishResult($id, 'https://x.com/' . $user . '/status/' . $id);
    }
}

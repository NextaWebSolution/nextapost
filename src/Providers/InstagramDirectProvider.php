<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Env;

/**
 * Instagram with Instagram Login: professional (Business/Creator) accounts without a Facebook Page.
 * Long-lived tokens (60 days) are refreshed automatically.
 */
final class InstagramDirectProvider extends InstagramBase
{
    public function meta(): array
    {
        return ['name' => 'Instagram', 'color' => '#C13584', 'letter' => 'I', 'kind' => 'oauth', 'app' => 'instagram',
            'description' => 'Instagram professional accounts with Instagram sign-in (no Facebook Page needed). Posts, carousels, Reels and Stories.'] + $this->contentMeta();
    }

    private static function version(): string
    {
        $v = (string) Env::get('META_GRAPH_VERSION', 'v21.0');
        return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v21.0';
    }

    protected function graph(string $method, string $path, array $params): array
    {
        $url = str_starts_with($path, 'https://') ? $path : 'https://graph.instagram.com/' . self::version() . '/' . ltrim($path, '/');
        $resp = $method === 'GET'
            ? $this->http('GET', $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params))
            : $this->http($method, $url, ['form' => $params]);
        $j = is_array($resp['json']) ? $resp['json'] : [];
        if ($resp['status'] < 200 || $resp['status'] >= 300 || isset($j['error'])) {
            $code = (int) ($j['error']['code'] ?? 0);
            $msg = (string) ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? $j['error_message'] ?? ('HTTP ' . $resp['status']));
            throw new ProviderException('Instagram: ' . $msg, $resp['status'] >= 500 || $resp['status'] === 0 || in_array($code, [1, 2, 4, 17, 613], true), in_array($code, [190, 102], true) || $resp['status'] === 401);
        }
        return $j;
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
            'scope' => 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments',
        ]);
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('Instagram sign-in was cancelled.'));
        }
        $short = $this->graph('POST', 'https://api.instagram.com/oauth/access_token', [
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri, 'code' => (string) ($query['code'] ?? ''),
        ]);
        $short = $short['data'][0] ?? $short;
        $long = $this->graph('GET', 'https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token', 'client_secret' => $app['client_secret'], 'access_token' => (string) $short['access_token'],
        ]);
        $token = (string) $long['access_token'];
        $me = $this->graph('GET', 'me', ['fields' => 'user_id,username,name,profile_picture_url,account_type', 'access_token' => $token]);
        $id = (string) ($me['user_id'] ?? $me['id']);
        return [[
            'external_id' => $id, 'name' => (string) (($me['name'] ?? '') ?: $me['username']), 'username' => '@' . ($me['username'] ?? ''),
            'avatar_url' => $me['profile_picture_url'] ?? null,
            'credentials' => ['ig_user_id' => $id, 'access_token' => $token, 'expires_at' => time() + (int) ($long['expires_in'] ?? 5184000), 'issued_at' => time()],
            'meta' => ['username' => $me['username'] ?? null, 'account_type' => $me['account_type'] ?? null],
        ]];
    }

    public function refresh(array $credentials, array $app): ?array
    {
        $exp = (int) ($credentials['expires_at'] ?? 0);
        if ($exp === 0 || $exp - time() > 10 * 86400 || time() - (int) ($credentials['issued_at'] ?? 0) < 86400) {
            return null;
        }
        if ($exp < time()) {
            throw new ProviderException(__('The Instagram sign-in has expired. Reconnect the channel.'), false, true);
        }
        $r = $this->graph('GET', 'https://graph.instagram.com/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => (string) $credentials['access_token']]);
        return ['access_token' => (string) $r['access_token'], 'expires_at' => time() + (int) ($r['expires_in'] ?? 5184000), 'issued_at' => time()];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        return $this->publishTo((string) $credentials['ig_user_id'], (string) $credentials['access_token'], $text, $media, $context);
    }
}

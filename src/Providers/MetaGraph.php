<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Env;
use App\Core\Http;

/** Shared Meta (Facebook Login for Business) OAuth + Graph helpers for Facebook Pages and Instagram. */
final class MetaGraph
{
    public const SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement,business_management,instagram_basic,instagram_content_publish';

    public static function version(): string
    {
        $v = (string) Env::get('META_GRAPH_VERSION', 'v21.0');
        return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v21.0';
    }

    public static function url(string $path): string
    {
        return 'https://graph.facebook.com/' . self::version() . '/' . ltrim($path, '/');
    }

    public static function authorizeUrl(array $app, string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/' . self::version() . '/dialog/oauth?' . http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri, 'state' => $state,
            'scope' => self::SCOPES, 'response_type' => 'code',
        ]);
    }

    public static function call(string $method, string $path, array $params = [], array $opts = []): array
    {
        $url = self::url($path);
        if ($method === 'GET') {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            $resp = Http::request('GET', $url, $opts);
        } else {
            $resp = Http::request($method, $url, $opts + ['form' => $params]);
        }
        $j = is_array($resp['json']) ? $resp['json'] : [];
        if ($resp['status'] < 200 || $resp['status'] >= 300 || isset($j['error'])) {
            $code = (int) ($j['error']['code'] ?? 0);
            $retry = $resp['status'] >= 500 || $resp['status'] === 0 || in_array($code, [1, 2, 4, 17, 32, 341, 613], true) || !empty($j['error']['is_transient']);
            $auth = in_array($code, [190, 102, 463, 467], true);
            $msg = (string) ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? ('HTTP ' . $resp['status']));
            throw new ProviderException('Meta: ' . $msg, $retry, $auth);
        }
        return $j;
    }

    /** Exchange code → long-lived user token → list of pages (with page tokens and linked IG accounts). */
    public static function pages(array $app, array $query, string $redirectUri): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException('Facebook sign-in was cancelled: ' . ($query['error_description'] ?? $query['error']));
        }
        $short = self::call('GET', 'oauth/access_token', [
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'redirect_uri' => $redirectUri, 'code' => (string) ($query['code'] ?? ''),
        ]);
        $long = self::call('GET', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'fb_exchange_token' => $short['access_token'] ?? '',
        ]);
        $pages = self::call('GET', 'me/accounts', [
            'access_token' => $long['access_token'] ?? '', 'limit' => 100,
            'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
        ]);
        return $pages['data'] ?? [];
    }
}

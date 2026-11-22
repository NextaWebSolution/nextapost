<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;

/** Google OAuth (offline access with PKCE) shared by YouTube and Google Business Profile. */
final class GoogleOAuth
{
    public static function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge, array $scopes): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
            'scope' => implode(' ', $scopes), 'access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true',
            'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256',
        ]);
    }

    private static function token(array $form, string $network): array
    {
        $r = Http::request('POST', 'https://oauth2.googleapis.com/token', ['form' => $form]);
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            $err = (string) ($r['json']['error'] ?? '');
            throw new ProviderException($network . ': ' . (string) ($r['json']['error_description'] ?? $err ?: 'sign-in failed (HTTP ' . $r['status'] . ')'), $r['status'] >= 500 || $r['status'] === 0, $err === 'invalid_grant');
        }
        return $r['json'];
    }

    /** @return array credentials */
    public static function exchange(array $app, array $query, string $redirectUri, string $verifier, string $network): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('{network} sign-in was cancelled.', ['network' => $network]));
        }
        $t = self::token([
            'grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $redirectUri,
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'code_verifier' => $verifier,
        ], $network);
        if (empty($t['refresh_token'])) {
            throw new ProviderException(__('{network} didn’t give a long-term sign-in. Remove NextaPost from your Google account’s third-party access, then connect again.', ['network' => $network]));
        }
        return ['access_token' => (string) $t['access_token'], 'refresh_token' => (string) $t['refresh_token'], 'expires_at' => time() + (int) ($t['expires_in'] ?? 3600)];
    }

    /** Refresh when the access token has less than 5 minutes left. */
    public static function refresh(array $credentials, array $app, string $network): ?array
    {
        if ((int) ($credentials['expires_at'] ?? 0) - time() > 300) {
            return null;
        }
        if (empty($credentials['refresh_token']) || !$app) {
            throw new ProviderException(__('The {network} sign-in has expired. Reconnect the channel.', ['network' => $network]), false, true);
        }
        $t = self::token(['grant_type' => 'refresh_token', 'refresh_token' => (string) $credentials['refresh_token'], 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']], $network);
        return ['access_token' => (string) $t['access_token'], 'expires_at' => time() + (int) ($t['expires_in'] ?? 3600)] + (!empty($t['refresh_token']) ? ['refresh_token' => (string) $t['refresh_token']] : []);
    }

    /** Throws a ProviderException with Google's error reason unless 2xx. */
    public static function check(array $resp, string $network): array
    {
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            return is_array($resp['json']) ? $resp['json'] : [];
        }
        $e = $resp['json']['error'] ?? [];
        $reason = (string) ($e['errors'][0]['reason'] ?? $e['status'] ?? '');
        $msg = (string) ($e['message'] ?? ('HTTP ' . $resp['status']));
        $retry = $resp['status'] >= 500 || $resp['status'] === 0 || $resp['status'] === 429 || in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true);
        if ($reason === 'quotaExceeded') {
            $msg = __('The daily YouTube upload quota of your Google project is used up. It resets at midnight Pacific time; the upload will be retried.');
        }
        throw new ProviderException($network . ': ' . $msg, $retry, $resp['status'] === 401);
    }
}

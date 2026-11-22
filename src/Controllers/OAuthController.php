<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\RedirectException;
use App\Core\Response;
use App\Core\View;
use App\Services\OAuthService;

/**
 * OAuth 2.1 endpoints. Discovery, registration, token and revoke are stateless (routes_api.php);
 * the consent screen at /oauth/authorize uses the normal signed-in session (routes.php).
 */
final class OAuthController extends Controller
{
    private function svc(): OAuthService
    {
        return new OAuthService($this->db);
    }

    private static function oauthError(string $error, string $description, int $status = 400): Response
    {
        $r = Response::json(['error' => $error, 'error_description' => $description], $status);
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['Pragma'] = 'no-cache';
        return $r;
    }

    // ---------------------------------------------------------------- discovery

    public function resourceMetadata(): Response
    {
        $path = (string) preg_replace('#^/\.well-known/oauth-protected-resource#', '', $this->req->path);
        $resource = match ($path) {
            '/mcp' => abs_url('/mcp'),
            '/api/v1' => abs_url('/api/v1'),
            default => OAuthService::issuer(),
        };
        return Response::json(OAuthService::resourceMetadata($resource));
    }

    public function serverMetadata(): Response
    {
        return Response::json(OAuthService::serverMetadata());
    }

    // ---------------------------------------------------------------- registration, token, revoke

    public function register(): Response
    {
        if (!RateLimiter::hit($this->db, 'oauth-register:' . $this->req->ip(), 20, 3600)) {
            return self::oauthError('slow_down', 'Too many registrations from this address. Try again later.', 429);
        }
        [$client, $err, $desc] = $this->svc()->register($this->req->body);
        if ($err) {
            return self::oauthError($err, (string) $desc);
        }
        $r = Response::json($client, 201);
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }

    public function token(): Response
    {
        if (!RateLimiter::hit($this->db, 'oauth-token:' . $this->req->ip(), 60, 60)) {
            return self::oauthError('slow_down', 'Too many requests. Wait a minute.', 429);
        }
        $grant = $this->req->str('grant_type');
        $clientId = $this->req->str('client_id');
        [$tokens, $err, $desc] = match ($grant) {
            'authorization_code' => $this->svc()->exchangeCode($this->req->str('code'), $clientId, $this->req->str('redirect_uri'), $this->req->str('code_verifier'), $this->req->str('resource') ?: null),
            'refresh_token' => $this->svc()->refresh($this->req->str('refresh_token'), $clientId),
            default => [null, 'unsupported_grant_type', 'Use authorization_code or refresh_token.'],
        };
        if ($err) {
            return self::oauthError($err, (string) $desc, $err === 'invalid_client' ? 401 : 400);
        }
        $r = Response::json($tokens);
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['Pragma'] = 'no-cache';
        return $r;
    }

    public function revoke(): Response
    {
        $token = $this->req->str('token');
        if ($token !== '') {
            $this->svc()->revoke($token);
        }
        return Response::json((object) []);
    }

    // ---------------------------------------------------------------- consent screen

    /**
     * Check an authorization request. Problems with client_id or redirect_uri are shown on the page
     * (never redirected, so the server can't be used as an open redirector); others go back to the client.
     * @return array{0:array,1:?string,2:?string} [params, page error, redirect error]
     */
    private function validate(array $in): array
    {
        $p = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k) {
            $p[$k] = isset($in[$k]) && is_string($in[$k]) ? $in[$k] : '';
        }
        $client = $this->svc()->client($p['client_id']);
        if (!$client) {
            return [$p, __('This app isn’t registered with NextaPost, or its details couldn’t be loaded. Go back to the app and try connecting again.'), null];
        }
        if ($p['redirect_uri'] === '' && count($client['redirect_uris']) === 1) {
            $p['redirect_uri'] = (string) $client['redirect_uris'][0];
        }
        if (!OAuthService::redirectMatches($client['redirect_uris'], $p['redirect_uri'])) {
            return [$p, __('This app asked to send you back to an address it didn’t register. For your safety the request was stopped.'), null];
        }
        $p['client'] = $client;
        if ($p['response_type'] !== 'code') {
            return [$p, null, 'unsupported_response_type'];
        }
        if ($p['code_challenge'] === '' || $p['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $p['code_challenge'])) {
            return [$p, null, 'invalid_request'];
        }
        // Scope is informational: a token always gets the member's own access in the chosen workspace.
        $p['scope'] = OAuthService::SCOPE;
        if ($p['resource'] !== '' && !in_array(rtrim($p['resource'], '/'), array_map(fn ($r) => rtrim($r, '/'), OAuthService::resources()), true)) {
            return [$p, null, 'invalid_target'];
        }
        return [$p, null, null];
    }

    private static function returnUrl(string $redirectUri, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null) + ['iss' => OAuthService::issuer()];
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params);
    }

    private function page(array $data, int $status = 200, ?string $redirectUri = null): Response
    {
        $res = Response::html(View::render('oauth/authorize', $data + ['title' => __('Connect an app')], 'auth'), $status);
        if ($redirectUri) {
            // The consent form redirects to the app after posting; allow that origin in form-action.
            $u = parse_url($redirectUri);
            $scheme = strtolower((string) ($u['scheme'] ?? ''));
            $target = in_array($scheme, ['http', 'https'], true) && \App\Services\OAuthService::validHost((string) ($u['host'] ?? ''))
                ? $scheme . '://' . $u['host'] . (isset($u['port']) ? ':' . (int) $u['port'] : '')
                : (preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) ? $scheme . ':' : '');
            if ($target !== '') {
                $res->headers['Content-Security-Policy'] = str_replace("form-action 'self'", "form-action 'self' " . $target, Response::csp());
            }
        }
        return $res;
    }

    public function consent(): Response
    {
        [$p, $pageError, $redirectError] = $this->validate($this->req->query);
        if ($pageError) {
            return $this->page(['error' => $pageError], 400);
        }
        if ($redirectError) {
            return Response::redirect(self::returnUrl($p['redirect_uri'], ['error' => $redirectError, 'state' => $p['state']]));
        }
        if (!Auth::user()) {
            $_SESSION['intended'] = '/oauth/authorize?' . http_build_query(array_diff_key($p, ['client' => 1]));
            throw new RedirectException(url('/login'));
        }
        $u = Auth::user();
        $workspaces = $this->db->all(
            'SELECT w.uuid, w.name, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ?
             WHERE w.deleted_at IS NULL ORDER BY w.name',
            [$u['id']]
        );
        if (!$workspaces) {
            return $this->page(['error' => __('You aren’t a member of any workspace yet.')], 403);
        }
        $redirectHost = parse_url($p['redirect_uri'], PHP_URL_HOST) ?: parse_url($p['redirect_uri'], PHP_URL_SCHEME);
        return $this->page([
            'client' => $p['client'], 'params' => array_diff_key($p, ['client' => 1]), 'workspaces' => $workspaces,
            'current' => $_SESSION['ws'] ?? null, 'redirectHost' => (string) $redirectHost, 'user' => $u,
            'verified' => (int) $p['client']['is_metadata_doc'] === 1,
        ], 200, $p['redirect_uri']);
    }

    public function consentSubmit(): Response
    {
        [$p, $pageError, $redirectError] = $this->validate($this->req->body);
        if ($pageError) {
            return $this->page(['error' => $pageError], 400);
        }
        if ($redirectError) {
            return Response::redirect(self::returnUrl($p['redirect_uri'], ['error' => $redirectError, 'state' => $p['state']]));
        }
        $u = $this->user();
        if ($this->req->str('decision') !== 'allow') {
            return Response::redirect(self::returnUrl($p['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user declined.', 'state' => $p['state']]));
        }
        $ws = $this->db->one(
            'SELECT w.id, w.name FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ? WHERE w.uuid = ? AND w.deleted_at IS NULL',
            [$u['id'], $this->req->str('workspace')]
        );
        if (!$ws) {
            return $this->page(['error' => __('Choose one of your workspaces.')], 422);
        }
        $code = $this->svc()->createCode($p['client'], (int) $u['id'], (int) $ws['id'], $p['redirect_uri'], $p['code_challenge'], $p['resource'] !== '' ? $p['resource'] : null);
        log_activity((int) $ws['id'], 'oauth.connected', __('{app} connected by {user}', ['app' => $p['client']['name'], 'user' => $u['name']]));
        return Response::redirect(self::returnUrl($p['redirect_uri'], ['code' => $code, 'state' => $p['state']]));
    }
}

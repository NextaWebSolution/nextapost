<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Http;

/**
 * OAuth 2.1 authorization server for AI apps (MCP clients) and integrations.
 * Public clients only, PKCE (S256) required, rotating refresh tokens with reuse detection.
 * Clients register dynamically (RFC 7591) or identify themselves with an https client metadata document.
 */
final class OAuthService
{
    public const SCOPE = 'nextapost';
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 60 * 86400;
    public const CODE_TTL = 600;
    private const BLOCKED_SCHEMES = ['http', 'https', 'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss'];

    public function __construct(private DB $db)
    {
    }

    /** Resource identifiers this server accepts (RFC 8707). */
    public static function resources(): array
    {
        return [rtrim(abs_url('/'), '/'), abs_url('/mcp'), abs_url('/api/v1')];
    }

    public static function issuer(): string
    {
        return rtrim(abs_url('/'), '/');
    }

    public static function serverMetadata(): array
    {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => abs_url('/oauth/authorize'),
            'token_endpoint' => abs_url('/oauth/token'),
            'registration_endpoint' => abs_url('/oauth/register'),
            'revocation_endpoint' => abs_url('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
            'service_documentation' => abs_url('/api/v1'),
        ];
    }

    public static function resourceMetadata(string $resource): array
    {
        return [
            'resource' => $resource,
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => app_name(),
        ];
    }

    // ---------------------------------------------------------------- redirect URIs

    /** A DNS name, IPv4 address or bracketed IPv6 address — nothing else. */
    public static function validHost(string $host): bool
    {
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])$/i', $host);
    }

    public static function validRedirect(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 700 || str_contains($uri, '#') || preg_match('/\s/', $uri)) {
            return false;
        }
        $p = parse_url($uri);
        if (!$p || empty($p['scheme'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        if (isset($p['host']) && !self::validHost((string) $p['host'])) {
            return false; // only real host names (also keeps the consent page's CSP form-action clean)
        }
        if ($scheme === 'https') {
            return !empty($p['host']) && !isset($p['user']) && !isset($p['pass']);
        }
        if ($scheme === 'http') {
            return in_array(strtolower((string) ($p['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true);
        }
        // Private-use scheme for native apps, e.g. com.example.app:/callback or myapp://callback
        return (bool) preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) && !in_array($scheme, self::BLOCKED_SCHEMES, true);
    }

    /** Exact match, except that the port of an http loopback address may differ (RFC 8252 §7.3). */
    public static function redirectMatches(array $registered, string $given): bool
    {
        foreach ($registered as $r) {
            if (hash_equals((string) $r, $given)) {
                return true;
            }
            $a = parse_url((string) $r);
            $b = parse_url($given);
            if ($a && $b && strtolower($a['scheme'] ?? '') === 'http' && strtolower($b['scheme'] ?? '') === 'http'
                && in_array(strtolower($a['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true)
                && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
                && ($a['path'] ?? '/') === ($b['path'] ?? '/') && ($a['query'] ?? '') === ($b['query'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- clients

    /** @return array{0:?array,1:?string,2:?string} [client response, error, description] */
    public function register(array $in): array
    {
        $uris = $in['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > 10) {
            return [null, 'invalid_redirect_uri', 'Send 1 to 10 redirect_uris.'];
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !self::validRedirect($u)) {
                return [null, 'invalid_redirect_uri', 'Redirect URIs must be https, http on localhost, or an app scheme, without a fragment.'];
            }
        }
        $method = $in['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return [null, 'invalid_client_metadata', 'Only public clients are supported: token_endpoint_auth_method must be "none".'];
        }
        foreach ((array) ($in['grant_types'] ?? ['authorization_code']) as $g) {
            if (!in_array($g, ['authorization_code', 'refresh_token'], true)) {
                return [null, 'invalid_client_metadata', 'Supported grant types: authorization_code, refresh_token.'];
            }
        }
        $name = trim(strip_tags(str_val($in['client_name'] ?? '')));
        $name = mb_substr($name !== '' ? $name : 'AI app', 0, 200);
        $clientUri = isset($in['client_uri']) && is_string($in['client_uri']) && preg_match('#^https://#i', $in['client_uri']) ? mb_substr($in['client_uri'], 0, 700) : null;
        $logo = isset($in['logo_uri']) && is_string($in['logo_uri']) && preg_match('#^https://#i', $in['logo_uri']) ? mb_substr($in['logo_uri'], 0, 700) : null;
        $clientId = 'npc_' . Crypto::token(24);
        $this->db->insert('oauth_clients', [
            'client_id' => $clientId, 'client_id_hash' => hash('sha256', $clientId), 'name' => $name,
            'redirect_uris' => json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), 'client_uri' => $clientUri, 'logo_uri' => $logo,
            'is_metadata_doc' => 0, 'created_at' => now_utc(),
        ]);
        return [[
            'client_id' => $clientId, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
        ] + ($clientUri ? ['client_uri' => $clientUri] : []), null, null];
    }

    public function client(string $clientId): ?array
    {
        if ($clientId === '' || strlen($clientId) > 700) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM oauth_clients WHERE client_id_hash = ?', [hash('sha256', $clientId)]);
        if (str_starts_with($clientId, 'https://')) {
            if (!$row || (int) $row['is_metadata_doc'] !== 1 || ($row['last_used_at'] ?? $row['created_at']) < now_utc(-86400)) {
                $row = $this->fetchMetadataDocument($clientId, $row) ?? $row;
            }
        }
        if ($row && !hash_equals((string) $row['client_id'], $clientId)) {
            return null;
        }
        if ($row) {
            $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        }
        return $row;
    }

    /** Client ID Metadata Document: the client_id is an https URL serving the client's JSON metadata. */
    private function fetchMetadataDocument(string $url, ?array $existing): ?array
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['path']) || $p['path'] === '/' || isset($p['fragment']) || isset($p['user'])) {
            return null;
        }
        try {
            $res = Http::request('GET', $url, ['public_only' => true, 'timeout' => 10, 'max_bytes' => 65536]);
        } catch (\Throwable) {
            return null;
        }
        $doc = $res['json'];
        if ($res['status'] !== 200 || !is_array($doc) || ($doc['client_id'] ?? null) !== $url || !is_array($doc['redirect_uris'] ?? null) || !$doc['redirect_uris']) {
            return null;
        }
        $uris = array_values(array_filter($doc['redirect_uris'], fn ($u) => is_string($u) && self::validRedirect($u)));
        if (count($uris) !== count($doc['redirect_uris']) || count($uris) > 10 || ($doc['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            return null;
        }
        $name = mb_substr(trim(strip_tags((string) ($doc['client_name'] ?? ''))) ?: (string) $p['host'], 0, 200);
        $data = [
            'name' => $name, 'redirect_uris' => json_encode($uris, JSON_UNESCAPED_SLASHES), 'is_metadata_doc' => 1,
            'client_uri' => is_string($doc['client_uri'] ?? null) && preg_match('#^https://#i', $doc['client_uri']) ? mb_substr($doc['client_uri'], 0, 700) : null,
            'logo_uri' => is_string($doc['logo_uri'] ?? null) && preg_match('#^https://#i', $doc['logo_uri']) ? mb_substr($doc['logo_uri'], 0, 700) : null,
            'last_used_at' => now_utc(),
        ];
        if ($existing) {
            $this->db->update('oauth_clients', $data, 'id = ?', [$existing['id']]);
            return $this->db->one('SELECT * FROM oauth_clients WHERE id = ?', [$existing['id']]);
        }
        $id = $this->db->insert('oauth_clients', $data + ['client_id' => $url, 'client_id_hash' => hash('sha256', $url), 'created_at' => now_utc()]);
        return $this->db->one('SELECT * FROM oauth_clients WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- codes and tokens

    public function createCode(array $client, int $userId, int $wsId, string $redirectUri, string $challenge, ?string $resource): string
    {
        $code = 'npa_' . Crypto::token(32);
        $this->db->insert('oauth_codes', [
            'code_hash' => hash('sha256', $code), 'client_pk' => $client['id'], 'user_id' => $userId, 'workspace_id' => $wsId,
            'redirect_uri' => $redirectUri, 'code_challenge' => $challenge, 'scope' => self::SCOPE, 'resource' => $resource,
            'expires_at' => now_utc(self::CODE_TTL),
        ]);
        return $code;
    }

    private function issue(int $clientPk, int $userId, int $wsId): array
    {
        $access = 'npo_' . Crypto::token(32);
        $refresh = 'npr_' . Crypto::token(32);
        $this->db->insert('oauth_tokens', [
            'client_pk' => $clientPk, 'user_id' => $userId, 'workspace_id' => $wsId,
            'access_hash' => hash('sha256', $access), 'refresh_hash' => hash('sha256', $refresh), 'scope' => self::SCOPE,
            'access_expires_at' => now_utc(self::ACCESS_TTL), 'refresh_expires_at' => now_utc(self::REFRESH_TTL), 'created_at' => now_utc(),
        ]);
        $this->db->update('oauth_clients', ['last_used_at' => now_utc()], 'id = ?', [$clientPk]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => self::SCOPE];
    }

    private function revokeGrant(int $clientPk, int $userId, int $wsId): void
    {
        $this->db->run('UPDATE oauth_tokens SET revoked_at = ? WHERE client_pk = ? AND user_id = ? AND workspace_id = ? AND revoked_at IS NULL', [now_utc(), $clientPk, $userId, $wsId]);
    }

    private static function memberOk(DB $db, int $userId, int $wsId): bool
    {
        return (bool) $db->value(
            'SELECT 1 FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id JOIN users u ON u.id = m.user_id
             WHERE m.user_id = ? AND m.workspace_id = ? AND w.deleted_at IS NULL AND u.deleted_at IS NULL AND u.is_active = 1',
            [$userId, $wsId]
        );
    }

    /** @return array{0:?array,1:?string,2:?string} [tokens, error, description] */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier, ?string $resource): array
    {
        $client = $this->client($clientId);
        if (!$client) {
            return [null, 'invalid_client', 'Unknown client_id.'];
        }
        $row = $this->db->one('SELECT * FROM oauth_codes WHERE code_hash = ?', [hash('sha256', $code)]);
        if (!$row || (int) $row['client_pk'] !== (int) $client['id']) {
            return [null, 'invalid_grant', 'The authorization code is not valid.'];
        }
        if ($row['used_at']) {
            // A code used twice may have been stolen: cancel everything issued for this grant.
            $this->revokeGrant((int) $row['client_pk'], (int) $row['user_id'], (int) $row['workspace_id']);
            return [null, 'invalid_grant', 'The authorization code was already used.'];
        }
        $this->db->update('oauth_codes', ['used_at' => now_utc()], 'id = ?', [$row['id']]);
        if ($row['expires_at'] < now_utc()) {
            return [null, 'invalid_grant', 'The authorization code has expired. Sign in again.'];
        }
        if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) {
            return [null, 'invalid_grant', 'redirect_uri does not match the one used to get the code.'];
        }
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || !hash_equals((string) $row['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
            return [null, 'invalid_grant', 'code_verifier does not match the code_challenge.'];
        }
        if ($resource !== null && $resource !== '' && $row['resource'] !== null && $resource !== $row['resource']) {
            return [null, 'invalid_target', 'resource does not match the authorization request.'];
        }
        if (!self::memberOk($this->db, (int) $row['user_id'], (int) $row['workspace_id'])) {
            return [null, 'invalid_grant', 'You no longer have access to that workspace.'];
        }
        return [$this->issue((int) $client['id'], (int) $row['user_id'], (int) $row['workspace_id']), null, null];
    }

    /** @return array{0:?array,1:?string,2:?string} */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $client = $this->client($clientId);
        if (!$client) {
            return [null, 'invalid_client', 'Unknown client_id.'];
        }
        $t = $this->db->one('SELECT * FROM oauth_tokens WHERE refresh_hash = ?', [hash('sha256', $refreshToken)]);
        if (!$t || (int) $t['client_pk'] !== (int) $client['id']) {
            return [null, 'invalid_grant', 'The refresh token is not valid.'];
        }
        if ($t['revoked_at']) {
            // Refresh tokens rotate; an old one coming back means it leaked. Cancel the whole grant.
            $this->revokeGrant((int) $t['client_pk'], (int) $t['user_id'], (int) $t['workspace_id']);
            return [null, 'invalid_grant', 'The refresh token was already used or revoked. Sign in again.'];
        }
        if ($t['refresh_expires_at'] < now_utc()) {
            return [null, 'invalid_grant', 'The refresh token has expired. Sign in again.'];
        }
        if (!self::memberOk($this->db, (int) $t['user_id'], (int) $t['workspace_id'])) {
            return [null, 'invalid_grant', 'You no longer have access to that workspace.'];
        }
        $this->db->update('oauth_tokens', ['revoked_at' => now_utc()], 'id = ?', [$t['id']]);
        return [$this->issue((int) $t['client_pk'], (int) $t['user_id'], (int) $t['workspace_id']), null, null];
    }

    /** RFC 7009: always succeeds from the caller's point of view. */
    public function revoke(string $token): void
    {
        $h = hash('sha256', $token);
        $this->db->run('UPDATE oauth_tokens SET revoked_at = ? WHERE (access_hash = ? OR refresh_hash = ?) AND revoked_at IS NULL', [now_utc(), $h, $h]);
    }

    /** Remove expired codes and long-dead tokens (called from the worker's housekeeping). */
    public function prune(): void
    {
        $this->db->run('DELETE FROM oauth_codes WHERE expires_at < ?', [now_utc(-86400)]);
        $this->db->run('DELETE FROM oauth_tokens WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR (refresh_expires_at < ?)', [now_utc(-30 * 86400), now_utc(-86400)]);
    }
}

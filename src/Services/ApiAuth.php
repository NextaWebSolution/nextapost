<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiError;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Gate;
use App\Core\RateLimiter;
use App\Core\Request;

/**
 * Bearer-token authentication for the REST API and the MCP server.
 * Two kinds of token: workspace API keys ("np_...") and OAuth access tokens ("npo_...").
 * Only SHA-256 hashes are stored. The effective role is never higher than the member's current role.
 */
final class ApiAuth
{
    public const RANK = ['viewer' => 0, 'approver' => 1, 'contributor' => 1, 'editor' => 2, 'admin' => 3];
    public const KEY_ROLES = ['admin', 'editor', 'contributor', 'viewer'];
    public const MAX_KEYS = 25;

    public function __construct(private DB $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Roles a member with $role may give to a key. */
    public static function grantableRoles(string $role): array
    {
        return array_values(array_filter(self::KEY_ROLES, fn ($r) => self::RANK[$r] <= (self::RANK[$role] ?? 0) && ($role !== 'approver' || $r === 'viewer')));
    }

    /** The key's role, lowered to the member's current role if that is lower. */
    public static function effectiveRole(string $keyRole, string $memberRole): string
    {
        if ($memberRole === 'approver') {
            return $keyRole === 'viewer' ? 'viewer' : 'approver';
        }
        return (self::RANK[$keyRole] ?? 0) <= (self::RANK[$memberRole] ?? 0) ? $keyRole : $memberRole;
    }

    /**
     * @return array{user:array,ws:array,role:string,via:string,token_id:int,client:?string}
     * @throws ApiError 401 with a WWW-Authenticate header pointing to the OAuth metadata
     */
    public function resolve(Request $req, string $resourceMetadataUrl): array
    {
        $h = (string) ($req->header('authorization') ?? '');
        $challenge = ['WWW-Authenticate' => 'Bearer resource_metadata="' . $resourceMetadataUrl . '"'];
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_\-\.~+\/]+=*)$/i', trim($h), $m)) {
            throw new ApiError(401, __('Send an API key or access token in the Authorization header: “Bearer <token>”.'), 'unauthorized', [], $challenge);
        }
        $token = $m[1];
        $ctx = str_starts_with($token, 'npo_') ? $this->fromOAuth($token) : (str_starts_with($token, 'np_') ? $this->fromKey($token) : null);
        if (!$ctx) {
            $challenge['WWW-Authenticate'] .= ', error="invalid_token"';
            throw new ApiError(401, __('This token is not valid, has expired or was revoked.'), 'invalid_token', [], $challenge);
        }
        $limit = max(10, (int) \App\Core\Env::get('API_RATE_LIMIT', 120));
        if (!RateLimiter::hit($this->db, 'api:' . $ctx['via'] . ':' . $ctx['token_id'], $limit, 60)) {
            throw new ApiError(429, __('Too many requests. Wait a minute and try again.'), 'rate_limited', [], ['Retry-After' => '60']);
        }
        return $ctx;
    }

    private function member(int $userId, int $wsId): ?array
    {
        $ws = $this->db->one(
            'SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ?
             WHERE w.id = ? AND w.deleted_at IS NULL',
            [$userId, $wsId]
        );
        $user = $this->db->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1', [$userId]);
        return $ws && $user ? [$ws, $user] : null;
    }

    private function fromKey(string $token): ?array
    {
        $k = $this->db->one('SELECT * FROM api_keys WHERE token_hash = ? AND revoked_at IS NULL', [self::hash($token)]);
        if (!$k || ($k['expires_at'] && $k['expires_at'] < now_utc())) {
            return null;
        }
        $mw = $this->member((int) $k['user_id'], (int) $k['workspace_id']);
        if (!$mw) {
            return null;
        }
        [$ws, $user] = $mw;
        if (!$k['last_used_at'] || $k['last_used_at'] < now_utc(-300)) {
            $this->db->update('api_keys', ['last_used_at' => now_utc()], 'id = ?', [$k['id']]);
        }
        $role = self::effectiveRole((string) $k['role'], (string) $ws['role']);
        $ws['role'] = $role;
        return ['user' => $user, 'ws' => $ws, 'role' => $role, 'via' => 'key', 'token_id' => (int) $k['id'], 'client' => null];
    }

    private function fromOAuth(string $token): ?array
    {
        $t = $this->db->one(
            'SELECT t.*, c.name AS client_name FROM oauth_tokens t JOIN oauth_clients c ON c.id = t.client_pk
             WHERE t.access_hash = ? AND t.revoked_at IS NULL AND t.access_expires_at > ?',
            [self::hash($token), now_utc()]
        );
        if (!$t) {
            return null;
        }
        $mw = $this->member((int) $t['user_id'], (int) $t['workspace_id']);
        if (!$mw) {
            return null;
        }
        [$ws, $user] = $mw;
        if (!$t['last_used_at'] || $t['last_used_at'] < now_utc(-300)) {
            $this->db->update('oauth_tokens', ['last_used_at' => now_utc()], 'id = ?', [$t['id']]);
        }
        return ['user' => $user, 'ws' => $ws, 'role' => (string) $ws['role'], 'via' => 'oauth', 'token_id' => (int) $t['id'], 'client' => (string) $t['client_name']];
    }

    // ---- API key management (used by the Developers page) ----

    public function keys(int $wsId, ?int $userId = null): array
    {
        $sql = 'SELECT k.*, u.name AS user_name FROM api_keys k JOIN users u ON u.id = k.user_id WHERE k.workspace_id = ? AND k.revoked_at IS NULL';
        $args = [$wsId];
        if ($userId !== null) {
            $sql .= ' AND k.user_id = ?';
            $args[] = $userId;
        }
        return $this->db->all($sql . ' ORDER BY k.created_at DESC', $args);
    }

    /** @return array{0:?array,1:array<string,string>,2:?string} [key row, errors, plain token shown once] */
    public function createKey(array $ws, array $user, string $memberRole, array $in): array
    {
        $errors = [];
        $name = trim(str_val($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('Give the key a name of up to 100 characters, e.g. “Zapier” or “My AI agent”.');
        }
        $role = str_val($in['role'] ?? 'editor');
        if (!in_array($role, self::grantableRoles($memberRole), true)) {
            $errors['role'] = __('You can’t give a key more access than your own role.');
        }
        $expires = null;
        $days = (int) ($in['expires_days'] ?? 0);
        if ($days < 0 || $days > 3650) {
            $errors['expires_days'] = __('Pick an expiry between 1 and 3650 days, or never.');
        } elseif ($days > 0) {
            $expires = now_utc($days * 86400);
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM api_keys WHERE workspace_id = ? AND revoked_at IS NULL', [$ws['id']]) >= self::MAX_KEYS) {
            $errors['name'] = __('A workspace can have up to {n} API keys. Revoke one you no longer use.', ['n' => self::MAX_KEYS]);
        }
        if ($errors) {
            return [null, $errors, null];
        }
        $token = 'np_' . Crypto::token(32);
        $id = $this->db->insert('api_keys', [
            'uuid' => uuid4(), 'workspace_id' => $ws['id'], 'user_id' => $user['id'], 'name' => $name,
            'prefix' => substr($token, 0, 10), 'token_hash' => self::hash($token), 'role' => $role,
            'expires_at' => $expires, 'created_at' => now_utc(),
        ]);
        log_activity((int) $ws['id'], 'api_key.created', __('API key created: {name}', ['name' => $name]));
        return [$this->db->one('SELECT * FROM api_keys WHERE id = ?', [$id]), [], $token];
    }

    public function revokeKey(array $key): void
    {
        $this->db->update('api_keys', ['revoked_at' => now_utc()], 'id = ?', [$key['id']]);
        log_activity((int) $key['workspace_id'], 'api_key.revoked', __('API key revoked: {name}', ['name' => $key['name']]));
    }

    public static function presentKey(array $k): array
    {
        return [
            'id' => $k['uuid'], 'name' => $k['name'], 'prefix' => $k['prefix'], 'role' => $k['role'],
            'owner' => $k['user_name'] ?? null, 'last_used_at' => $k['last_used_at'], 'expires_at' => $k['expires_at'], 'created_at' => $k['created_at'],
        ];
    }

    /** OAuth apps a user has connected to a workspace (one row per client). */
    public function connectedApps(int $wsId, int $userId): array
    {
        return $this->db->all(
            'SELECT c.id, c.name, c.client_uri, MAX(t.last_used_at) AS last_used_at, MIN(t.created_at) AS created_at
             FROM oauth_tokens t JOIN oauth_clients c ON c.id = t.client_pk
             WHERE t.workspace_id = ? AND t.user_id = ? AND t.revoked_at IS NULL AND (t.refresh_expires_at > ? OR t.access_expires_at > ?)
             GROUP BY c.id, c.name, c.client_uri ORDER BY c.name',
            [$wsId, $userId, now_utc(), now_utc()]
        );
    }

    public function disconnectApp(int $wsId, int $userId, int $clientPk): void
    {
        $this->db->run('UPDATE oauth_tokens SET revoked_at = ? WHERE workspace_id = ? AND user_id = ? AND client_pk = ? AND revoked_at IS NULL', [now_utc(), $wsId, $userId, $clientPk]);
    }

    public static function allowed(array $ctx, string $ability): bool
    {
        return Gate::can($ctx['role'], $ability, $ctx['ws']);
    }
}

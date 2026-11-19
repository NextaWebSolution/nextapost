<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;

final class AccountService
{
    public function __construct(private DB $db)
    {
    }

    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 8) {
            return 'Use at least 8 characters for the password.';
        }
        if (mb_strlen($pw) > 200) {
            return 'That password is too long.';
        }
        return null;
    }

    /** @return array{user:array,workspace:array}|string error */
    public function register(string $name, string $email, string $password, string $timezone, string $company = '', bool $superAdmin = false): array|string
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return 'Enter a valid email address.';
        }
        if ($err = self::passwordProblem($password)) {
            return $err;
        }
        if (!valid_timezone($timezone)) {
            $timezone = 'Asia/Kolkata';
        }
        if ($this->db->value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return 'An account with that email already exists. Sign in instead.';
        }
        return $this->db->tx(function () use ($name, $email, $password, $timezone, $company, $superAdmin): array {
            $now = now_utc();
            $uid = $this->db->insert('users', [
                'uuid' => uuid4(), 'name' => $name, 'email' => $email, 'password_hash' => \App\Core\Auth::hash($password),
                'timezone' => $timezone, 'theme' => 'system', 'is_super_admin' => $superAdmin ? 1 : 0, 'is_active' => 1, 'created_at' => $now,
            ]);
            $orgName = trim($company) !== '' ? mb_substr(trim($company), 0, 150) : $name . '’s team';
            $oid = $this->db->insert('organizations', ['uuid' => uuid4(), 'name' => $orgName, 'owner_id' => $uid, 'created_at' => $now]);
            $ws = $this->createWorkspace($oid, $uid, trim($company) !== '' ? $orgName : 'My Brand', $timezone);
            return ['user' => (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$uid]), 'workspace' => $ws];
        });
    }

    public function createWorkspace(int $orgId, int $ownerUserId, string $name, string $timezone): array
    {
        $colors = ['#7C3AED', '#2563EB', '#DB2777', '#059669', '#EA580C', '#0891B2'];
        $id = $this->db->insert('workspaces', [
            'uuid' => uuid4(), 'organization_id' => $orgId, 'name' => mb_substr($name, 0, 150), 'timezone' => $timezone,
            'color' => $colors[random_int(0, count($colors) - 1)], 'approval_mode' => 'editors', 'created_at' => now_utc(),
        ]);
        $this->db->insert('workspace_members', ['workspace_id' => $id, 'user_id' => $ownerUserId, 'role' => 'admin', 'created_at' => now_utc()]);
        return (array) $this->db->one('SELECT * FROM workspaces WHERE id = ?', [$id]);
    }

    public function resetLink(int $userId, int $ttlSeconds): string
    {
        $token = Crypto::token(32);
        $this->db->insert('password_resets', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => now_utc($ttlSeconds), 'created_at' => now_utc()]);
        return abs_url('reset/' . $token);
    }

    public function findReset(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token)) {
            return null;
        }
        return $this->db->one(
            'SELECT r.*, u.email FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.is_active = 1',
            [hash('sha256', $token), now_utc()]
        );
    }
}

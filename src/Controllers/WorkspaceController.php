<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Mailer;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AccountService;
use App\Services\RetentionService;

final class WorkspaceController extends Controller
{
    public function settings(string $ws): Response
    {
        $this->workspace($ws);
        $members = $this->db->all(
            'SELECT u.id, u.uuid, u.name, u.email, u.last_login_at, m.role FROM workspace_members m JOIN users u ON u.id = m.user_id
             WHERE m.workspace_id = ? ORDER BY u.name',
            [$this->ws['id']]
        );
        $activity = $this->db->all(
            'SELECT a.*, u.name AS user_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.workspace_id = ? ORDER BY a.id DESC LIMIT 30',
            [$this->ws['id']]
        );
        $usage = $this->db->one('SELECT COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes FROM media WHERE workspace_id = ?', [$this->ws['id']]);
        return $this->view('settings/workspace', [
            'title' => 'Workspace settings', 'members' => $members, 'activity' => $activity,
            'usage' => $usage, 'cleanupPreview' => (new RetentionService($this->db))->run($this->ws, true),
            'quota' => max(1, (int) \App\Core\Env::get('MAX_WORKSPACE_STORAGE_MB', 10240)) * 1024 * 1024,
            'canSettings' => $this->can('settings'), 'roles' => Gate::roles(), 'timezones' => \DateTimeZone::listIdentifiers(),
            'ai' => \App\Services\AiService::settingsFor($this->ws), 'aiProviders' => \App\Services\AiService::PROVIDERS,
        ]);
    }

    public function update(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        $name = $this->req->str('name');
        $tz = $this->req->str('timezone');
        $color = $this->req->str('color');
        $mode = $this->req->str('approval_mode');
        if ($name === '' || mb_strlen($name) > 150) {
            return $this->back($this->wsUrl('settings'), ['error' => __('Enter a workspace name (up to 150 characters).')]);
        }
        if (!valid_timezone($tz)) {
            return $this->back($this->wsUrl('settings'), ['error' => __('Choose a valid time zone.')]);
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = (string) $this->ws['color'];
        }
        if (!in_array($mode, ['none', 'editors', 'required'], true)) {
            $mode = 'editors';
        }
        $this->db->update('workspaces', ['name' => $name, 'timezone' => $tz, 'color' => $color, 'approval_mode' => $mode, 'updated_at' => now_utc()], 'id = ?', [$this->ws['id']]);
        log_activity((int) $this->ws['id'], 'workspace.updated', 'Workspace settings changed');
        return $this->back($this->wsUrl('settings'), ['success' => __('Workspace settings saved.')]);
    }

    public function saveRetention(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        $pick = fn (string $k, array $allowed): int => array_key_exists((int) $this->req->str($k), $allowed) ? (int) $this->req->str($k) : 0;
        $data = [
            'media_retention_days' => $pick('media_retention_days', RetentionService::MEDIA_OPTIONS),
            'post_retention_days' => $pick('post_retention_days', RetentionService::POST_OPTIONS),
            'library_retention_days' => $pick('library_retention_days', RetentionService::LIBRARY_OPTIONS),
        ];
        $this->db->update('workspaces', $data + ['updated_at' => now_utc()], 'id = ?', [$this->ws['id']]);
        log_activity((int) $this->ws['id'], 'storage.settings', sprintf('Clean-up rules: media %dd, posts %dd, unused files %dd (0 = never)', ...array_values($data)));
        return $this->back($this->wsUrl('settings'), ['success' => __('Clean-up rules saved. They run automatically once a day.')]);
    }

    public function runRetention(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        $r = (new RetentionService($this->db))->run($this->ws);
        return $this->back($this->wsUrl('settings'), ['success' => sprintf('Clean-up finished: %d file(s) removed (%s freed), %d old post(s) deleted.', $r['media_files'], human_bytes($r['media_bytes']), $r['posts'])]);
    }

    public function create(): Response
    {
        $u = $this->user();
        $name = $this->req->str('name');
        if ($name === '' || mb_strlen($name) > 150) {
            return $this->back(url('/'), ['error' => __('Enter a name for the new workspace.')]);
        }
        $tz = $this->req->str('timezone', (string) $u['timezone']);
        if (!valid_timezone($tz)) {
            $tz = (string) $u['timezone'];
        }
        $orgId = (int) $this->db->value('SELECT id FROM organizations WHERE owner_id = ? ORDER BY id LIMIT 1', [$u['id']]);
        if (!$orgId) {
            $orgId = $this->db->insert('organizations', ['uuid' => uuid4(), 'name' => $u['name'] . '’s team', 'owner_id' => $u['id'], 'created_at' => now_utc()]);
        }
        $ws = (new AccountService($this->db))->createWorkspace($orgId, (int) $u['id'], $name, $tz);
        Session::flash('success', __('Workspace “{name}” created. Connect its channels next.', ['name' => $name]));
        return $this->redirect(url('/w/' . $ws['uuid'] . '/channels'));
    }

    public function destroy(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        if ($this->req->str('confirm_name') !== $this->ws['name']) {
            return $this->back($this->wsUrl('settings'), ['error' => __('Type the workspace name exactly to confirm deletion.')]);
        }
        $count = (int) $this->db->value('SELECT COUNT(*) FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id WHERE m.user_id = ? AND w.deleted_at IS NULL', [Auth::id()]);
        if ($count <= 1) {
            return $this->back($this->wsUrl('settings'), ['error' => __('You can’t delete your only workspace. Create another one first.')]);
        }
        $this->db->run("UPDATE posts SET status = 'cancelled' WHERE workspace_id = ? AND status IN ('scheduled','in_review','draft')", [$this->ws['id']]);
        $this->db->run("UPDATE post_targets SET status = 'cancelled' WHERE status IN ('pending','queued') AND post_id IN (SELECT id FROM posts WHERE workspace_id = ?)", [$this->ws['id']]);
        $this->db->update('workspaces', ['deleted_at' => now_utc()], 'id = ?', [$this->ws['id']]);
        unset($_SESSION['ws']);
        Session::flash('success', __('Workspace deleted. Its scheduled posts were cancelled.'));
        return $this->redirect(url('/'));
    }

    public function addMember(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('members');
        $email = mb_strtolower($this->req->str('email'));
        $name = $this->req->str('name');
        $role = $this->req->str('role', 'editor');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->back($this->wsUrl('settings'), ['error' => __('Enter a valid email address.')]);
        }
        if (!isset(Gate::ROLES[$role])) {
            $role = 'editor';
        }
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        $link = null;
        $svc = new AccountService($this->db);
        if (!$user) {
            $uid = $this->db->insert('users', [
                'uuid' => uuid4(), 'name' => $name !== '' ? mb_substr($name, 0, 120) : explode('@', $email)[0], 'email' => $email,
                'password_hash' => \App\Core\Auth::hash(bin2hex(random_bytes(24))), 'timezone' => $this->ws['timezone'],
                'theme' => 'system', 'is_super_admin' => 0, 'is_active' => 1, 'created_at' => now_utc(),
            ]);
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$uid]);
            $link = $svc->resetLink($uid, 7 * 86400);
        }
        if ($this->db->value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$this->ws['id'], $user['id']])) {
            return $this->back($this->wsUrl('settings'), ['error' => $user['name'] . ' is already a member.']);
        }
        $this->db->insert('workspace_members', ['workspace_id' => $this->ws['id'], 'user_id' => $user['id'], 'role' => $role, 'created_at' => now_utc()]);
        log_activity((int) $this->ws['id'], 'member.added', $user['email'] . ' as ' . $role);
        $sent = false;
        try {
            Mailer::send($email, 'You’ve been invited to ' . $this->ws['name'] . ' on ' . app_name(), View::partial('emails/invite', [
                'inviter' => $this->user()['name'], 'workspace' => $this->ws['name'], 'role' => $role,
                'link' => $link ?? abs_url('login'), 'newUser' => $link !== null,
            ]));
            $sent = true;
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Invite email failed: ' . $e->getMessage());
        }
        if ($link && !$sent) {
            Session::flash('invite_link', $link);
        }
        return $this->back($this->wsUrl('settings'), ['success' => $user['name'] . ' was added as ' . $role . '.' . ($sent ? ' We emailed them.' : ' Email isn’t configured — share the link below with them.')]);
    }

    public function updateMember(string $ws, string $userUuid): Response
    {
        $this->workspace($ws);
        $this->authorize('members');
        $m = $this->member($userUuid);
        $role = $this->req->str('role');
        if (!isset(Gate::ROLES[$role])) {
            return $this->back($this->wsUrl('settings'), ['error' => __('Choose a valid role.')]);
        }
        if ($m['role'] === 'admin' && $role !== 'admin' && $this->adminCount() <= 1) {
            return $this->back($this->wsUrl('settings'), ['error' => __('A workspace needs at least one admin.')]);
        }
        $this->db->update('workspace_members', ['role' => $role], 'workspace_id = ? AND user_id = ?', [$this->ws['id'], $m['id']]);
        return $this->back($this->wsUrl('settings'), ['success' => $m['name'] . ' is now ' . $role . '.']);
    }

    public function removeMember(string $ws, string $userUuid): Response
    {
        $this->workspace($ws);
        $this->authorize('members');
        $m = $this->member($userUuid);
        if ($m['role'] === 'admin' && $this->adminCount() <= 1) {
            return $this->back($this->wsUrl('settings'), ['error' => __('A workspace needs at least one admin.')]);
        }
        $this->db->run('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$this->ws['id'], $m['id']]);
        // Their API keys and connected apps for this workspace are revoked too, so re-adding them later doesn't revive old access.
        $this->db->run('UPDATE api_keys SET revoked_at = ? WHERE workspace_id = ? AND user_id = ? AND revoked_at IS NULL', [now_utc(), $this->ws['id'], $m['id']]);
        $this->db->run('UPDATE oauth_tokens SET revoked_at = ? WHERE workspace_id = ? AND user_id = ? AND revoked_at IS NULL', [now_utc(), $this->ws['id'], $m['id']]);
        log_activity((int) $this->ws['id'], 'member.removed', $m['email']);
        if ((int) $m['id'] === Auth::id()) {
            unset($_SESSION['ws']);
            return $this->redirect(url('/'));
        }
        return $this->back($this->wsUrl('settings'), ['success' => $m['name'] . ' was removed from this workspace.']);
    }

    private function member(string $userUuid): array
    {
        $m = $this->db->one('SELECT u.*, m.role FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.uuid = ?', [$this->ws['id'], $userUuid]);
        if (!$m) {
            throw new HttpException(404, __('Member not found.'));
        }
        return $m;
    }

    private function adminCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM workspace_members WHERE workspace_id = ? AND role = 'admin'", [$this->ws['id']]);
    }
}

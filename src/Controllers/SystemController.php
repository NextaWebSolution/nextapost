<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Env;
use App\Core\Mailer;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Providers\ProviderFactory;
use App\Services\Backup;
use App\Services\Queue;
use App\Services\Updater;

/** System administration (super admin only): general, email, integrations, updates, backups, health. */
final class SystemController extends Controller
{
    private function withWorkspace(): void
    {
        if (isset($_SESSION['ws']) && is_string($_SESSION['ws'])) {
            try {
                $this->workspace($_SESSION['ws']);
            } catch (\Throwable) {
                $this->ws = null;
            }
        }
    }

    public function index(): Response
    {
        $this->superAdmin();
        $this->withWorkspace();
        $tab = $this->req->str('tab', 'general');
        $integrations = (array) Settings::get('integrations', []);
        $apps = [];
        foreach (ProviderFactory::APPS as $key => $def) {
            $apps[$key] = $def + [
                'client_id' => (string) ($integrations[$key]['client_id'] ?? ''),
                'has_secret' => !empty($integrations[$key]['client_secret_enc']),
                'redirect' => abs_url('oauth/' . $key . '/callback'),
            ];
        }
        $mail = Mailer::config();
        $hb = Settings::get('cron_heartbeat') ?? [];
        $stats = (new Queue($this->db))->stats();
        $checks = [
            ['PHP version ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')],
            ['Extensions: pdo_mysql, curl, mbstring, sodium, fileinfo, gd, zip', extension_loaded('pdo_mysql') && extension_loaded('curl') && extension_loaded('mbstring') && extension_loaded('sodium') && extension_loaded('fileinfo') && extension_loaded('gd') && extension_loaded('zip')],
            ['HTTPS site address (needed by Instagram/Facebook to fetch media)', str_starts_with((string) Env::get('APP_URL', ''), 'https://')],
            ['storage/ is writable', is_writable(APP_ROOT . '/storage')],
            ['public/media/ is writable', is_writable(APP_ROOT . '/public/media')],
            ['Cron job ran in the last 5 minutes', !empty($hb['at']) && $hb['at'] > now_utc(-300)],
            ['Installer locked', is_file(APP_ROOT . '/storage/installed.lock')],
            ['Debug mode is off', !Env::get('APP_DEBUG')],
        ];
        $users = $this->db->all('SELECT id, uuid, name, email, is_super_admin, is_active, last_login_at, created_at FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 500');
        return $this->view('system/index', [
            'title' => 'System', 'tab' => $tab, 'apps' => $apps, 'mail' => $mail, 'hb' => $hb, 'stats' => $stats, 'checks' => $checks,
            'updates' => Updater::config(), 'update' => Settings::get('update_available'), 'updateLast' => Settings::get('update_last'),
            'updateChecked' => Settings::get('update_checked_at'), 'updateError' => Settings::get('update_last_error'),
            'backups' => Backup::list(), 'users' => $users,
            'general' => ['app_name' => app_name(), 'allow_registration' => (bool) Settings::get('allow_registration', false)],
            'cronCmd' => '/usr/local/bin/php ' . APP_ROOT . '/bin/cron.php >/dev/null 2>&1',
            'failedJobs' => $this->db->all("SELECT id, type, last_error, finished_at FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 10"),
        ]);
    }

    public function saveGeneral(): Response
    {
        $this->superAdmin();
        $name = $this->req->str('app_name');
        Settings::set('app_name', $name !== '' ? mb_substr($name, 0, 60) : 'NextaPost');
        Settings::set('allow_registration', $this->req->str('allow_registration') === '1');
        return $this->back(url('/system?tab=general'), ['success' => 'Settings saved.']);
    }

    public function saveMail(): Response
    {
        $this->superAdmin();
        $old = Mailer::config();
        $driver = $this->req->str('driver');
        $c = [
            'driver' => in_array($driver, ['smtp', 'mail', 'log'], true) ? $driver : 'mail',
            'host' => $this->req->str('host'), 'port' => (int) $this->req->str('port', '587') ?: 587,
            'encryption' => in_array($this->req->str('encryption'), ['tls', 'ssl', 'none'], true) ? $this->req->str('encryption') : 'tls',
            'username' => $this->req->str('username'),
            'password_enc' => $this->req->str('password') !== '' ? Crypto::encrypt((string) $this->req->input('password')) : ($old['password_enc'] ?? ''),
            'from_email' => filter_var($this->req->str('from_email'), FILTER_VALIDATE_EMAIL) ? $this->req->str('from_email') : '',
            'from_name' => mb_substr($this->req->str('from_name'), 0, 80),
        ];
        Settings::set('mail', $c);
        if ($this->req->str('send_test') === '1') {
            try {
                Mailer::send($this->user()['email'], app_name() . ' test email', '<p>Email delivery works. 🎉</p>');
                return $this->back(url('/system?tab=email'), ['success' => 'Saved. A test email was sent to ' . $this->user()['email'] . '.']);
            } catch (\Throwable $e) {
                return $this->back(url('/system?tab=email'), ['error' => 'Saved, but the test email failed: ' . $e->getMessage()]);
            }
        }
        return $this->back(url('/system?tab=email'), ['success' => 'Email settings saved.']);
    }

    public function saveIntegrations(): Response
    {
        $this->superAdmin();
        $all = (array) Settings::get('integrations', []);
        foreach (array_keys(ProviderFactory::APPS) as $key) {
            $id = trim((string) ($this->req->arr($key)['client_id'] ?? ''));
            $secret = trim((string) ($this->req->arr($key)['client_secret'] ?? ''));
            $entry = $all[$key] ?? [];
            $entry['client_id'] = mb_substr($id, 0, 200);
            if ($secret !== '') {
                $entry['client_secret_enc'] = Crypto::encrypt($secret);
            }
            if ($id === '') {
                $entry = [];
            }
            $all[$key] = $entry;
        }
        Settings::set('integrations', $all);
        return $this->back(url('/system?tab=integrations'), ['success' => 'Integration keys saved.']);
    }

    public function saveUpdates(): Response
    {
        $this->superAdmin();
        $server = rtrim($this->req->str('server'), '/');
        if ($server !== '' && !preg_match('#^https://[^\s]+$#', $server)) {
            return $this->back(url('/system?tab=updates'), ['error' => 'The update server address must start with https://']);
        }
        $key = $this->req->str('public_key');
        if ($key !== '' && strlen((string) base64_decode($key, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $this->back(url('/system?tab=updates'), ['error' => 'The signing public key must be a base64 Ed25519 key.']);
        }
        Settings::set('updates', [
            'server' => $server ?: 'https://updates.nextawebsolution.com', 'channel' => in_array($this->req->str('channel'), ['stable', 'beta'], true) ? $this->req->str('channel') : 'stable',
            'auto_install' => $this->req->str('auto_install') === '1', 'public_key' => $key, 'license_key' => mb_substr($this->req->str('license_key'), 0, 200),
        ]);
        return $this->back(url('/system?tab=updates'), ['success' => 'Update settings saved.']);
    }

    public function checkUpdates(): Response
    {
        $this->superAdmin();
        try {
            $info = (new Updater($this->db))->check(true);
        } catch (\Throwable $e) {
            return $this->back(url('/system?tab=updates'), ['error' => $e->getMessage()]);
        }
        return $this->back(url('/system?tab=updates'), ['success' => $info ? 'Version ' . $info['version'] . ' is available.' : 'You’re on the latest version (' . APP_VERSION . ').']);
    }

    public function installUpdate(): Response
    {
        $this->superAdmin();
        try {
            $v = (new Updater($this->db))->installAvailable();
        } catch (\Throwable $e) {
            return $this->back(url('/system?tab=updates'), ['error' => $e->getMessage()]);
        }
        Session::flash('success', 'Updated to version ' . $v . '. A backup was saved first.');
        return $this->redirect(url('/system?tab=updates'));
    }

    public function uploadUpdate(): Response
    {
        $this->superAdmin();
        $f = $this->req->files['package'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            return $this->back(url('/system?tab=updates'), ['error' => 'Choose the update .zip file (max ' . ini_get('upload_max_filesize') . ').']);
        }
        $dest = APP_ROOT . '/storage/updates/manual-' . bin2hex(random_bytes(6)) . '.zip';
        move_uploaded_file((string) $f['tmp_name'], $dest);
        try {
            $v = (new Updater($this->db))->install($dest, $this->req->str('reinstall') === '1');
        } catch (\Throwable $e) {
            @unlink($dest);
            return $this->back(url('/system?tab=updates'), ['error' => $e->getMessage()]);
        }
        Session::flash('success', 'Installed version ' . $v . '. A backup was saved first.');
        return $this->redirect(url('/system?tab=updates'));
    }

    public function createBackup(): Response
    {
        $this->superAdmin();
        try {
            $file = (new Backup($this->db))->create('manual');
            Backup::prune(10);
        } catch (\Throwable $e) {
            return $this->back(url('/system?tab=backups'), ['error' => $e->getMessage()]);
        }
        return $this->back(url('/system?tab=backups'), ['success' => 'Backup created: ' . basename($file)]);
    }

    public function downloadBackup(string $name): Response
    {
        $this->superAdmin();
        $path = Backup::path($name);
        if (!$path) {
            return $this->back(url('/system?tab=backups'), ['error' => 'Backup not found.']);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function deleteBackup(string $name): Response
    {
        $this->superAdmin();
        $path = Backup::path($name);
        if ($path) {
            unlink($path);
        }
        return $this->back(url('/system?tab=backups'), ['success' => 'Backup deleted.']);
    }

    public function toggleUser(string $uuid): Response
    {
        $me = $this->superAdmin();
        $u = $this->db->one('SELECT * FROM users WHERE uuid = ?', [$uuid]);
        if (!$u || (int) $u['id'] === (int) $me['id']) {
            return $this->back(url('/system?tab=users'), ['error' => 'You can’t change your own account here.']);
        }
        $active = (int) $u['is_active'] ? 0 : 1;
        $this->db->update('users', ['is_active' => $active], 'id = ?', [$u['id']]);
        return $this->back(url('/system?tab=users'), ['success' => $u['name'] . ($active ? ' can sign in again.' : ' can no longer sign in.')]);
    }

    /** GDPR/DPDP-style erasure: anonymises the account, removes memberships and reset links. Posts stay with the workspace. */
    public function deleteUser(string $uuid): Response
    {
        $me = $this->superAdmin();
        $u = $this->db->one('SELECT * FROM users WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if (!$u || (int) $u['id'] === (int) $me['id']) {
            return $this->back(url('/system?tab=users'), ['error' => 'You can’t delete your own account here.']);
        }
        $soleAdmin = $this->db->value(
            "SELECT w.name FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
             WHERE m.user_id = ? AND m.role = 'admin' AND w.deleted_at IS NULL
               AND (SELECT COUNT(*) FROM workspace_members m2 WHERE m2.workspace_id = m.workspace_id AND m2.role = 'admin') = 1 LIMIT 1",
            [$u['id']]
        );
        if ($soleAdmin) {
            return $this->back(url('/system?tab=users'), ['error' => $u['name'] . ' is the only admin of “' . $soleAdmin . '”. Make someone else admin there first.']);
        }
        $this->db->tx(function () use ($u): void {
            $this->db->run('DELETE FROM workspace_members WHERE user_id = ?', [$u['id']]);
            $this->db->run('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
            $this->db->run('UPDATE activity_log SET ip = NULL WHERE user_id = ?', [$u['id']]);
            $this->db->update('users', [
                'name' => 'Deleted user', 'email' => 'deleted-' . $u['uuid'] . '@deleted.invalid',
                'password_hash' => \App\Core\Auth::hash(bin2hex(random_bytes(24))), 'is_active' => 0, 'is_super_admin' => 0,
                'deleted_at' => now_utc(), 'updated_at' => now_utc(),
            ], 'id = ?', [$u['id']]);
        });
        return $this->back(url('/system?tab=users'), ['success' => 'The account was deleted and its personal data removed.']);
    }

    public function runCron(): Response
    {
        $this->superAdmin();
        @set_time_limit(120);
        $r = (new \App\Services\Worker($this->db))->run(25, true);
        return $this->back(url('/system?tab=health'), ['success' => 'Scheduler ran: ' . $r['processed'] . ' job(s) processed.']);
    }
}

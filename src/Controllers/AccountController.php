<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Services\AccountService;

final class AccountController extends Controller
{
    public function show(): Response
    {
        $u = $this->user();
        if (isset($_SESSION['ws']) && is_string($_SESSION['ws'])) {
            try {
                $this->workspace($_SESSION['ws']);
            } catch (\Throwable) {
                $this->ws = null;
            }
        }
        return $this->view('settings/account', ['title' => 'Your account', 'timezones' => \DateTimeZone::listIdentifiers(), 'u' => $u]);
    }

    public function update(): Response
    {
        $u = $this->user();
        $name = $this->req->str('name');
        $email = mb_strtolower($this->req->str('email'));
        $tz = $this->req->str('timezone');
        if ($name === '' || mb_strlen($name) > 120) {
            return $this->back(url('/account'), ['error' => 'Enter your name.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->back(url('/account'), ['error' => 'Enter a valid email address.']);
        }
        if ($email !== $u['email'] && $this->db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
            return $this->back(url('/account'), ['error' => 'Another account already uses that email.']);
        }
        if (!valid_timezone($tz)) {
            $tz = (string) $u['timezone'];
        }
        $data = ['name' => $name, 'email' => $email, 'timezone' => $tz, 'updated_at' => now_utc()];
        $newPw = (string) $this->req->input('new_password', '');
        if ($newPw !== '' || $email !== $u['email']) {
            if (!password_verify((string) $this->req->input('current_password', ''), (string) $u['password_hash'])) {
                return $this->back(url('/account'), ['error' => 'Enter your current password to change your email or password.']);
            }
        }
        if ($newPw !== '') {
            if ($err = AccountService::passwordProblem($newPw)) {
                return $this->back(url('/account'), ['error' => $err]);
            }
            $data['password_hash'] = \App\Core\Auth::hash($newPw);
        }
        $this->db->update('users', $data, 'id = ?', [$u['id']]);
        if (isset($data['password_hash']) || $email !== $u['email']) {
            $this->db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_utc(), $u['id']]);
        }
        if (isset($data['password_hash'])) {
            Auth::login((array) $this->db->one('SELECT * FROM users WHERE id = ?', [$u['id']]));
        }
        return $this->back(url('/account'), ['success' => 'Your account was updated.']);
    }

    public function theme(): Response
    {
        $u = $this->user();
        $theme = $this->req->str('theme');
        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            return $this->json(['error' => ['message' => 'Unknown theme.']], 422);
        }
        $this->db->update('users', ['theme' => $theme], 'id = ?', [$u['id']]);
        return $this->json(['ok' => true]);
    }
}

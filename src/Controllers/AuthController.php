<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Services\AccountService;

final class AuthController extends Controller
{
    public function showLogin(): Response
    {
        if (Auth::user()) {
            return $this->redirect(url('/'));
        }
        return $this->view('auth/login', ['title' => 'Sign in', 'registration' => self::registrationOpen()], 'auth');
    }

    public function login(): Response
    {
        $email = mb_strtolower($this->req->str('email'));
        $ip = $this->req->ip();
        if (!RateLimiter::hit($this->db, 'login-ip:' . $ip, 20, 900) || !RateLimiter::hit($this->db, 'login:' . $email, 8, 900)) {
            $this->withOld();
            return $this->back(url('/login'), ['error' => 'Too many sign-in attempts. Wait 15 minutes and try again.']);
        }
        $user = Auth::attempt($email, (string) $this->req->input('password', ''));
        if (!$user) {
            $this->withOld();
            return $this->back(url('/login'), ['error' => 'That email and password don’t match. Check them and try again.']);
        }
        RateLimiter::clear($this->db, 'login:' . $email);
        Auth::login($user);
        $to = $_SESSION['intended'] ?? '/';
        unset($_SESSION['intended']);
        return $this->redirect(url(is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/'));
    }

    public function logout(): Response
    {
        Auth::logout();
        Session::flash('success', 'You’re signed out.');
        return $this->redirect(url('/login'));
    }

    public static function registrationOpen(): bool
    {
        return (bool) Settings::get('allow_registration', false);
    }

    public function showRegister(): Response
    {
        if (!self::registrationOpen()) {
            return $this->back(url('/login'), ['error' => 'Sign-ups are closed. Ask your administrator for an invite.']);
        }
        return $this->view('auth/register', ['title' => 'Create your account'], 'auth');
    }

    public function register(): Response
    {
        if (!self::registrationOpen()) {
            return $this->redirect(url('/login'));
        }
        if (!RateLimiter::hit($this->db, 'register:' . $this->req->ip(), 5, 3600)) {
            return $this->back(url('/register'), ['error' => 'Too many sign-ups from your network. Try again later.']);
        }
        $svc = new AccountService($this->db);
        $res = $svc->register($this->req->str('name'), $this->req->str('email'), (string) $this->req->input('password', ''), $this->req->str('timezone', 'Asia/Kolkata'), $this->req->str('company'));
        if (is_string($res)) {
            $this->withOld();
            return $this->back(url('/register'), ['error' => $res]);
        }
        Auth::login($res['user']);
        Session::flash('success', 'Welcome! Connect your first channel to start scheduling.');
        return $this->redirect(url('/w/' . $res['workspace']['uuid'] . '/channels'));
    }

    public function showForgot(): Response
    {
        return $this->view('auth/forgot', ['title' => 'Reset password'], 'auth');
    }

    public function forgot(): Response
    {
        $email = mb_strtolower($this->req->str('email'));
        $generic = 'If an account exists for that email, we’ve sent a reset link. It expires in 1 hour.';
        if (!RateLimiter::hit($this->db, 'forgot:' . $this->req->ip(), 5, 900) || !RateLimiter::hit($this->db, 'forgot-email:' . $email, 3, 3600)) {
            return $this->back(url('/forgot'), ['success' => $generic]);
        }
        $u = $this->db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($u) {
            $link = (new AccountService($this->db))->resetLink((int) $u['id'], 3600);
            try {
                Mailer::send($u['email'], 'Reset your ' . app_name() . ' password', View::partial('emails/reset', ['name' => $u['name'], 'link' => $link]));
            } catch (\Throwable $e) {
                \App\Core\Logger::error('Password reset email failed: ' . $e->getMessage());
            }
        }
        return $this->back(url('/forgot'), ['success' => $generic]);
    }

    public function showReset(string $token): Response
    {
        $row = (new AccountService($this->db))->findReset($token);
        if (!$row) {
            return $this->view('errors/error', ['status' => 410, 'heading' => 'Link expired', 'message' => 'This link has expired or was already used. Request a new one from the sign-in page.'], 'auth');
        }
        return $this->view('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'email' => $row['email']], 'auth');
    }

    public function reset(string $token): Response
    {
        $svc = new AccountService($this->db);
        $row = $svc->findReset($token);
        if (!$row) {
            return $this->redirect(url('/forgot'));
        }
        $pw = (string) $this->req->input('password', '');
        if ($err = AccountService::passwordProblem($pw)) {
            return $this->back(url('/reset/' . $token), ['error' => $err]);
        }
        $hash = \App\Core\Auth::hash($pw);
        $this->db->update('users', ['password_hash' => $hash, 'updated_at' => now_utc()], 'id = ?', [$row['user_id']]);
        // Invalidate every outstanding reset link for this user, not just the one used.
        $this->db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_utc(), $row['user_id']]);
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        Auth::login((array) $user);
        Session::flash('success', 'Your password is set. You’re signed in.');
        return $this->redirect(url('/'));
    }

}

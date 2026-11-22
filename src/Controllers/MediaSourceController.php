<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Providers\ProviderException;
use App\Services\MediaService;
use App\Services\MediaSourceService;

/** Add media from Unsplash, Canva, Google Photos and Google Drive. */
final class MediaSourceController extends Controller
{
    private function svc(): MediaSourceService
    {
        return new MediaSourceService($this->db);
    }

    private function ready(string $ws): int
    {
        $this->workspace($ws);
        $this->authorize('media');
        return (int) Auth::id();
    }

    /** Run an action, turning network problems into a JSON error. */
    private function attempt(callable $fn): Response
    {
        try {
            return $this->json($fn());
        } catch (ProviderException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()], 'reconnect' => $e->authError], 422);
        }
    }

    private function imported(array $rows): array
    {
        return ['media' => array_map([MediaService::class, 'toJson'], $rows)];
    }

    private function limit(int $uid): void
    {
        if (!RateLimiter::hit($this->db, 'upload:' . $uid, 120, 3600)) {
            throw new HttpException(429, __('Too many imports. Wait a while and try again.'));
        }
    }

    public function status(string $ws): Response
    {
        $uid = $this->ready($ws);
        $conn = [];
        foreach (['canva', 'google'] as $s) {
            $c = $this->svc()->connection($uid, $s);
            $conn[$s] = $c ? ['name' => $c['account_name']] : null;
        }
        return $this->json(['available' => MediaSourceService::available(), 'connected' => $conn]);
    }

    public function connect(string $ws, string $source): Response
    {
        $this->ready($ws);
        if (!in_array($source, ['canva', 'google'], true) || !(MediaSourceService::available()[$source === 'canva' ? 'canva' : 'google_photos'] ?? false)) {
            throw new HttpException(404, __('This source isn’t set up on this site.'));
        }
        $state = Crypto::token(16);
        $verifier = Crypto::token(48);
        $_SESSION['media_oauth'][$state] = ['ws' => $this->ws['uuid'], 'source' => $source, 'verifier' => $verifier, 'at' => time()];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return $this->redirect($this->svc()->authorizeUrl($source, $state, $challenge));
    }

    public function callback(string $source): Response
    {
        $u = $this->user();
        $state = $this->req->str('state');
        $pending = $_SESSION['media_oauth'][$state] ?? null;
        unset($_SESSION['media_oauth'][$state]);
        if (!$pending || $pending['source'] !== $source || time() - (int) $pending['at'] > 900) {
            Session::flash('error', __('The sign-in took too long or was opened in another browser. Please try again.'));
            return $this->redirect(url('/'));
        }
        $this->workspace((string) $pending['ws']);
        try {
            $this->svc()->callback((int) $u['id'], $source, $this->req->query, (string) $pending['verifier']);
            Session::flash('success', __('{source} connected. You can now add media from it.', ['source' => $source === 'canva' ? 'Canva' : 'Google']));
        } catch (ProviderException $e) {
            Session::flash('error', $e->getMessage());
        }
        return $this->redirect($this->wsUrl('media'));
    }

    public function disconnect(string $ws, string $source): Response
    {
        $uid = $this->ready($ws);
        $this->svc()->disconnect($uid, $source === 'canva' ? 'canva' : 'google');
        return $this->json(['ok' => true]);
    }

    public function unsplashSearch(string $ws): Response
    {
        $this->ready($ws);
        return $this->attempt(fn () => $this->svc()->unsplashSearch($this->req->str('q'), (int) $this->req->input('page', 1)));
    }

    public function unsplashImport(string $ws): Response
    {
        $uid = $this->ready($ws);
        $this->limit($uid);
        @set_time_limit(120);
        return $this->attempt(fn () => $this->imported([$this->svc()->unsplashImport($this->ws, $uid, $this->req->str('id'))]));
    }

    public function canvaDesigns(string $ws): Response
    {
        $uid = $this->ready($ws);
        return $this->attempt(fn () => $this->svc()->canvaDesigns($uid, $this->req->str('q'), $this->req->str('continuation') ?: null));
    }

    public function canvaImport(string $ws): Response
    {
        $uid = $this->ready($ws);
        $this->limit($uid);
        @set_time_limit(240);
        return $this->attempt(fn () => $this->imported($this->svc()->canvaImport($this->ws, $uid, $this->req->str('design'), $this->req->str('format'))));
    }

    public function photosSession(string $ws): Response
    {
        $uid = $this->ready($ws);
        return $this->attempt(fn () => $this->svc()->photosSession($uid));
    }

    public function photosPoll(string $ws, string $id): Response
    {
        $uid = $this->ready($ws);
        return $this->attempt(fn () => ['done' => $this->svc()->photosPoll($uid, $id)]);
    }

    public function photosImport(string $ws): Response
    {
        $uid = $this->ready($ws);
        $this->limit($uid);
        @set_time_limit(300);
        return $this->attempt(fn () => $this->imported($this->svc()->photosImport($this->ws, $uid, $this->req->str('session'))));
    }

    /** Import a public https link (same rules as the API's import_media_from_url). */
    public function urlImport(string $ws): Response
    {
        $uid = $this->ready($ws);
        $api = new \App\Services\AgentApi($this->db, ['user' => Auth::user(), 'ws' => $this->ws, 'role' => $this->role, 'via' => 'web', 'token_id' => 0, 'client' => null], 'web');
        try {
            $m = $api->call('import_media_from_url', ['url' => $this->req->str('url'), 'alt_text' => $this->req->str('alt')]);
        } catch (\App\Core\ApiError $e) {
            $msg = $e->details ? (string) (is_array($first = reset($e->details)) ? reset($first) : $first) : $e->getMessage();
            return $this->json(['error' => ['message' => $msg]], $e->status);
        }
        return $this->json($this->imported([(array) $this->db->one('SELECT * FROM media WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $m['id']])]));
    }

    public function drivePicker(string $ws): Response
    {
        $uid = $this->ready($ws);
        return $this->attempt(fn () => $this->svc()->drivePickerConfig($uid));
    }

    public function driveImport(string $ws): Response
    {
        $uid = $this->ready($ws);
        $this->limit($uid);
        @set_time_limit(300);
        return $this->attempt(fn () => $this->imported($this->svc()->driveImport($this->ws, $uid, $this->req->arr('files'))));
    }
}

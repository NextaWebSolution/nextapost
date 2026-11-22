<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;
use App\Services\ScheduleService;

final class ChannelController extends Controller
{
    /** Where a person signs out of a network to connect a different account. */
    private const SIGN_OUT = [
        'linkedin' => ['https://www.linkedin.com/m/logout/', 'Sign out of LinkedIn'],
        'linkedin_page' => ['https://www.linkedin.com/m/logout/', 'Sign out of LinkedIn'],
        'x' => ['https://x.com/logout', 'Sign out of X'],
        'facebook' => ['https://www.facebook.com/', 'Open Facebook to log out'],
        'instagram' => ['https://www.facebook.com/', 'Open Facebook to log out'],
        'instagram_direct' => ['https://www.instagram.com/accounts/logout/', 'Sign out of Instagram'],
        'threads' => ['https://www.threads.net/', 'Open Threads to log out'],
        'tiktok' => ['https://www.tiktok.com/logout', 'Sign out of TikTok'],
        'youtube' => ['https://accounts.google.com/Logout', 'Sign out of Google'],
        'google_business' => ['https://accounts.google.com/Logout', 'Sign out of Google'],
        'pinterest' => ['https://www.pinterest.com/logout/', 'Sign out of Pinterest'],
    ];

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $channels = $this->db->all('SELECT * FROM channels WHERE workspace_id = ? ORDER BY network, name', [$this->ws['id']]);
        $networks = [];
        foreach (ProviderFactory::all() as $key => $p) {
            $m = $p->meta();
            $networks[$key] = $m + ['configured' => $m['kind'] !== 'oauth' || ProviderFactory::appConfig($key) !== []];
        }
        $schedules = [];
        foreach ($channels as $c) {
            $schedules[$c['uuid']] = ['name' => $c['name'], 'network' => $c['network'], 'tz' => $c['timezone'] ?? '', 'goal' => (int) ($c['posting_goal'] ?? 0) ?: null,
                'schedule' => ScheduleService::decode($c['posting_schedule'] ?? null)];
        }
        return $this->view('channels/index', [
            'title' => 'Channels', 'channels' => $channels, 'networks' => $networks, 'canManage' => $this->can('channels'),
            'canSchedule' => $this->can('library'),
            'httpsOk' => str_starts_with((string) Env::get('APP_URL', ''), 'https://'),
            'scheduleCfg' => ['channels' => $schedules, 'base' => $this->wsUrl('channels'), 'timezones' => \DateTimeZone::listIdentifiers(),
                'days' => array_map(static fn ($d) => ['key' => $d, 'label' => weekday_name($d)], ScheduleService::DAYS)],
        ]);
    }

    /** Save a channel's weekly posting times (or generate suggestions for a weekly goal). */
    public function schedule(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $ch = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]) ?? throw new HttpException(404, __('That channel no longer exists.'));
        $goal = (int) $this->req->input('goal', 0);
        $goal = $goal > 0 ? min(ScheduleService::MAX_GOAL, $goal) : null;
        if ($this->req->input('generate')) {
            return $this->json(['schedule' => ScheduleService::generate((string) $ch['network'], $goal ?? 5)]);
        }
        [$sched, $err] = ScheduleService::validate($this->req->input('schedule', []));
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        $tz = trim($this->req->str('timezone'));
        if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return $this->json(['error' => ['message' => __('Pick a valid time zone.')]], 422);
        }
        $svc = new ScheduleService($this->db);
        $svc->save($ch, $sched, $tz ?: null, $goal);
        $ch = $this->db->one('SELECT * FROM channels WHERE id = ?', [$ch['id']]);
        $tzName = $svc->timezone($ch, $this->ws);
        $slots = array_map(static fn ($u) => to_local($u, $tzName, 'D d M H:i'), $svc->freeSlots($ch, $this->ws, 5));
        log_activity((int) $this->ws['id'], 'channel.schedule', __('Posting times updated: {name}', ['name' => (string) $ch['name']]), 'channel', (int) $ch['id']);
        return $this->json(['schedule' => $sched, 'slots' => $slots]);
    }

    public function connectForm(string $ws, string $network): Response
    {
        $this->workspace($ws);
        $this->authorize('channels');
        if (!ProviderFactory::has($network)) {
            throw new HttpException(404, __('Unknown network.'));
        }
        $p = ProviderFactory::make($network);
        $meta = $p->meta();
        if ($meta['kind'] === 'oauth') {
            $app = ProviderFactory::appConfig($network);
            if (!$app) {
                return $this->back($this->wsUrl('channels'), ['error' => $meta['name'] . ' isn’t set up yet. The system administrator needs to add the app keys in System → Integrations.']);
            }
            // Social networks silently reuse whichever account is signed in on this browser, so show a
            // short "which account?" step first with a way to switch accounts.
            if ($this->req->str('go') !== '1') {
                $related = match ($network) {
                    'linkedin' => 'linkedin_page',
                    'linkedin_page' => 'linkedin',
                    'facebook' => 'instagram',
                    'instagram' => 'facebook',
                    default => null,
                };
                return $this->view('channels/oauth-start', [
                    'title' => 'Connect ' . $meta['name'], 'network' => $network, 'meta' => $meta,
                    'signOut' => self::SIGN_OUT[$network] ?? null,
                    'related' => $related ? ['key' => $related, 'meta' => ProviderFactory::meta($related), 'configured' => ProviderFactory::appConfig($related) !== []] : null,
                    'connected' => $this->db->all('SELECT name, username FROM channels WHERE workspace_id = ? AND network = ?', [$this->ws['id'], $network]),
                ]);
            }
            $state = Crypto::token(24);
            $verifier = Crypto::token(48);
            $_SESSION['oauth'][$state] = ['ws' => $this->ws['uuid'], 'network' => $network, 'verifier' => $verifier, 'at' => time()];
            // Keep only the latest few pending OAuth attempts.
            $_SESSION['oauth'] = array_slice($_SESSION['oauth'], -5, null, true);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            return $this->redirect($p->authorizeUrl($app, ProviderFactory::redirectUri($network), $state, $challenge));
        }
        return $this->view('channels/connect', ['title' => 'Connect ' . $meta['name'], 'network' => $network, 'meta' => $meta, 'fields' => $p->fields()]);
    }

    public function connect(string $ws, string $network): Response
    {
        $this->workspace($ws);
        $this->authorize('channels');
        if (!ProviderFactory::has($network) || ProviderFactory::make($network)->meta()['kind'] !== 'token') {
            throw new HttpException(404, __('Unknown network.'));
        }
        $p = ProviderFactory::make($network);
        if (!\App\Core\RateLimiter::hit($this->db, 'connect:' . $this->user()['id'], 20, 600)) {
            return $this->back($this->wsUrl('channels/connect/' . $network), ['error' => __('Too many connection attempts. Wait 10 minutes and try again.')]);
        }
        $input = [];
        foreach ($p->fields() as $f) {
            $input[$f['name']] = $this->req->str($f['name']);
        }
        try {
            $account = $p->connect($input);
        } catch (ProviderException $e) {
            $this->withOld(array_merge(['_token'], array_column(array_filter($p->fields(), static fn ($f) => $f['type'] === 'password'), 'name')));
            return $this->back($this->wsUrl('channels/connect/' . $network), ['error' => $e->getMessage()]);
        }
        $name = $this->saveAccount($network, $account);
        Session::flash('success', __('{name} is connected.', ['name' => $name]));
        return $this->redirect($this->wsUrl('channels'));
    }

    /** Insert or refresh a connected account. Returns the channel name. */
    public function saveAccount(string $network, array $a): string
    {
        $existing = $this->db->one('SELECT id FROM channels WHERE workspace_id = ? AND network = ? AND external_id = ?', [$this->ws['id'], $network, $a['external_id']]);
        $data = [
            'name' => mb_substr((string) $a['name'], 0, 190), 'username' => $a['username'] ? mb_substr((string) $a['username'], 0, 190) : null,
            'avatar_url' => safe_url($a['avatar_url'] ?? null) !== null && mb_strlen((string) $a['avatar_url']) <= 700 ? (string) $a['avatar_url'] : null,
            'credentials_enc' => Crypto::encryptJson((array) $a['credentials']), 'meta_json' => (string) json_encode($a['meta'] ?? []),
            'status' => 'active', 'last_error' => null, 'updated_at' => now_utc(),
        ];
        if ($existing) {
            $this->db->update('channels', $data, 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('channels', $data + [
                'uuid' => uuid4(), 'workspace_id' => $this->ws['id'], 'network' => $network, 'external_id' => mb_substr((string) $a['external_id'], 0, 190),
                'created_by' => $this->user()['id'], 'created_at' => now_utc(),
            ]);
        }
        log_activity((int) $this->ws['id'], 'channel.connected', ProviderFactory::meta($network)['name'] . ': ' . $a['name']);
        return (string) $a['name'];
    }

    /** OAuth callback: /oauth/{app}/callback */
    public function callback(string $app): Response
    {
        $this->user();
        $state = $this->req->str('state');
        $pending = $_SESSION['oauth'][$state] ?? null;
        unset($_SESSION['oauth'][$state]);
        if (!$pending || time() - (int) $pending['at'] > 900) {
            Session::flash('error', __('The sign-in took too long or was opened in another browser. Please try connecting again.'));
            return $this->redirect(url('/'));
        }
        $this->workspace((string) $pending['ws']);
        $this->authorize('channels');
        $network = (string) $pending['network'];
        $p = ProviderFactory::make($network);
        try {
            $accounts = $p->handleCallback(ProviderFactory::appConfig($network), $this->req->query, ProviderFactory::redirectUri($network), (string) $pending['verifier']);
        } catch (ProviderException $e) {
            return $this->back($this->wsUrl('channels'), ['error' => $e->getMessage()]);
        }
        // Always confirm — even for a single account — so the person sees exactly which account the network returned.
        $key = Crypto::token(12);
        $_SESSION['oauth_pick'] = ['key' => $key, 'ws' => $this->ws['uuid'], 'network' => $network, 'accounts' => $accounts, 'at' => time()];
        $existing = array_column($this->db->all('SELECT external_id FROM channels WHERE workspace_id = ? AND network = ?', [$this->ws['id'], $network]), 'external_id');
        return $this->view('channels/pick', [
            'title' => 'Choose accounts', 'network' => $network, 'meta' => $p->meta(), 'accounts' => $accounts, 'key' => $key,
            'existing' => $existing, 'signOut' => self::SIGN_OUT[$network] ?? null,
        ]);
    }

    public function pick(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('channels');
        $pick = $_SESSION['oauth_pick'] ?? null;
        unset($_SESSION['oauth_pick']);
        if (!$pick || $pick['key'] !== $this->req->str('key') || $pick['ws'] !== $this->ws['uuid'] || time() - (int) $pick['at'] > 900) {
            return $this->back($this->wsUrl('channels'), ['error' => __('That selection expired. Please connect again.')]);
        }
        $chosen = array_map('strval', $this->req->arr('accounts'));
        $n = 0;
        foreach ($pick['accounts'] as $a) {
            if (in_array((string) $a['external_id'], $chosen, true)) {
                $this->saveAccount((string) $pick['network'], $a);
                $n++;
            }
        }
        return $this->back($this->wsUrl('channels'), $n ? ['success' => $n === 1 ? '1 account connected.' : "$n accounts connected."] : ['error' => __('No accounts were selected.')]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('channels');
        $ch = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]);
        if (!$ch) {
            throw new HttpException(404, __('Channel not found.'));
        }
        $pending = (int) $this->db->value("SELECT COUNT(*) FROM post_targets WHERE channel_id = ? AND status IN ('pending','queued','publishing')", [$ch['id']]);
        if ($pending > 0 && $this->req->str('confirm') !== 'yes') {
            return $this->back($this->wsUrl('channels'), ['error' => "{$ch['name']} has $pending scheduled post(s). Remove it again to confirm — those posts will skip this channel."]);
        }
        $this->db->tx(function () use ($ch): void {
            $postIds = array_column($this->db->all('SELECT DISTINCT post_id FROM post_targets WHERE channel_id = ?', [$ch['id']]), 'post_id');
            $this->db->run('DELETE FROM channels WHERE id = ?', [$ch['id']]);
            $pub = new \App\Services\PublishService($this->db, new \App\Services\Queue($this->db));
            foreach ($postIds as $pid) {
                $pub->refreshPostStatus((int) $pid);
            }
        });
        log_activity((int) $this->ws['id'], 'channel.removed', $ch['name']);
        return $this->back($this->wsUrl('channels'), ['success' => $ch['name'] . ' was removed.']);
    }
}

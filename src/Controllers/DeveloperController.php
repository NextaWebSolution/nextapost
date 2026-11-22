<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiError;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AgentApi;
use App\Services\ApiAuth;
use App\Services\WebhookService;

/** Developers page: API keys, connected AI apps (OAuth), MCP/REST connection details and webhooks. */
final class DeveloperController extends Controller
{
    private function auth(): ApiAuth
    {
        return new ApiAuth($this->db);
    }

    /** The same operations the API uses, run as the signed-in member. */
    private function agent(): AgentApi
    {
        return new AgentApi($this->db, ['user' => Auth::user(), 'ws' => $this->ws, 'role' => $this->role, 'via' => 'web', 'token_id' => 0, 'client' => null], 'web');
    }

    private function op(string $name, array $args): Response
    {
        try {
            return $this->json(['data' => $this->agent()->call($name, $args)]);
        } catch (ApiError $e) {
            $errors = array_map(fn ($v) => is_array($v) ? implode(' ', $v) : $v, $e->details);
            return $this->json(['error' => ['message' => $errors ? reset($errors) : $e->getMessage()], 'errors' => $errors], $e->status);
        }
    }

    private function keysFor(): array
    {
        $u = Auth::user();
        $rows = $this->auth()->keys((int) $this->ws['id'], $this->role === 'admin' ? null : (int) $u['id']);
        return array_map(fn ($k) => ApiAuth::presentKey($k) + ['mine' => (int) $k['user_id'] === (int) $u['id']], $rows);
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $u = Auth::user();
        $events = [];
        foreach (WebhookService::EVENTS as $k => $label) {
            $events[] = ['id' => $k, 'label' => __($label)];
        }
        return $this->view('developers/index', [
            'title' => __('Developers & AI agents'),
            'cfg' => [
                'base' => $this->wsUrl('developers'),
                'keys' => $this->keysFor(),
                'roles' => array_map(fn ($r) => ['id' => $r, 'label' => \App\Core\Gate::roleName($r)], ApiAuth::grantableRoles($this->role)),
                'apps' => array_map(fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'url' => safe_url((string) $a['client_uri']) ?: null, 'last_used_at' => $a['last_used_at']], $this->auth()->connectedApps((int) $this->ws['id'], (int) $u['id'])),
                'mcpUrl' => abs_url('/mcp'),
                'apiUrl' => abs_url('/api/v1'),
                'canWebhooks' => $this->can('settings'),
                'webhooks' => $this->can('settings') ? array_map([WebhookService::class, 'present'], (new WebhookService($this->db))->list((int) $this->ws['id'])) : [],
                'events' => $events,
                'timezone' => $this->ws['timezone'],
            ],
        ]);
    }

    public function keyStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        [$key, $errors, $token] = $this->auth()->createKey($this->ws, Auth::user(), $this->role, $this->req->body);
        if ($errors) {
            return $this->json(['error' => ['message' => reset($errors)], 'errors' => $errors], 422);
        }
        return $this->json(['key' => ApiAuth::presentKey($key + ['user_name' => Auth::user()['name']]) + ['mine' => true], 'token' => $token]);
    }

    public function keyRevoke(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $key = $this->db->one('SELECT * FROM api_keys WHERE workspace_id = ? AND uuid = ? AND revoked_at IS NULL', [$this->ws['id'], $id]);
        if (!$key) {
            throw new HttpException(404, __('This key doesn’t exist or was already revoked.'));
        }
        if ((int) $key['user_id'] !== (int) Auth::id() && $this->role !== 'admin') {
            throw new HttpException(403, __('Only the key’s owner or a workspace admin can revoke it.'));
        }
        $this->auth()->revokeKey($key);
        return $this->json(['ok' => true]);
    }

    public function appDisconnect(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $this->auth()->disconnectApp((int) $this->ws['id'], (int) Auth::id(), (int) $id);
        return $this->json(['ok' => true]);
    }

    // Webhooks (admins)
    public function hookStore(string $ws): Response
    {
        $this->workspace($ws);
        return $this->op('create_webhook', ['url' => $this->req->str('url'), 'events' => array_values($this->req->arr('events'))]);
    }

    public function hookUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $args = ['webhook_id' => $id];
        foreach (['url', 'status'] as $k) {
            if ($this->req->input($k) !== null) {
                $args[$k] = $this->req->str($k);
            }
        }
        if ($this->req->input('events') !== null) {
            $args['events'] = array_values($this->req->arr('events'));
        }
        return $this->op('update_webhook', $args);
    }

    public function hookDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        return $this->op('delete_webhook', ['webhook_id' => $id]);
    }

    public function hookRotate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        return $this->op('rotate_webhook_secret', ['webhook_id' => $id]);
    }

    public function hookDeliveries(string $ws, string $id): Response
    {
        $this->workspace($ws);
        return $this->op('list_webhook_deliveries', ['webhook_id' => $id]);
    }

    public function hookReplay(string $ws, string $id, string $d): Response
    {
        $this->workspace($ws);
        return $this->op('replay_webhook_delivery', ['webhook_id' => $id, 'delivery_id' => $d]);
    }

    /** Send a "ping" event right away so the receiver can be checked. */
    public function hookTest(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        $svc = new WebhookService($this->db);
        $w = $svc->find((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('Webhook not found.'));
        $d = $svc->enqueue($w, 'ping', ['message' => __('Test delivery from {app}.', ['app' => app_name()])], true);
        return $this->json(['data' => ['id' => $d['uuid'], 'status' => $d['status'], 'response_code' => $d['response_code'] !== null ? (int) $d['response_code'] : null]]);
    }
}

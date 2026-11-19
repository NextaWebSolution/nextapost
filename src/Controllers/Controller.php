<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

abstract class Controller
{
    protected DB $db;
    protected ?array $ws = null;
    protected string $role = 'viewer';

    public function __construct(protected App $app, protected Request $req)
    {
        $this->db = $app->db;
    }

    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            if ($this->req->wantsJson()) {
                throw new HttpException(401, 'Your session ended. Sign in again.');
            }
            if ($this->req->method === 'GET') {
                $_SESSION['intended'] = $this->req->path;
            }
            throw new RedirectException(url('/login'));
        }
        return $u;
    }

    protected function superAdmin(): array
    {
        $u = $this->user();
        if (!(int) $u['is_super_admin']) {
            throw new HttpException(403, 'Only the system administrator can open this page.');
        }
        return $u;
    }

    protected function workspace(string $uuid): array
    {
        $u = $this->user();
        $ws = $this->db->one(
            'SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ?
             WHERE w.uuid = ? AND w.deleted_at IS NULL',
            [$u['id'], $uuid]
        );
        if (!$ws) {
            throw new HttpException(404, 'This workspace doesn’t exist or you’re not a member of it.');
        }
        $this->ws = $ws;
        $this->role = (string) $ws['role'];
        $_SESSION['ws'] = $uuid;
        return $ws;
    }

    protected function authorize(string $ability): void
    {
        if (!$this->ws || !Gate::can($this->role, $ability, $this->ws)) {
            throw new HttpException(403, 'Your role in this workspace doesn’t allow that. Ask a workspace admin.');
        }
    }

    protected function can(string $ability): bool
    {
        return $this->ws !== null && Gate::can($this->role, $ability, $this->ws);
    }

    protected function wsUrl(string $path = ''): string
    {
        return url('/w/' . $this->ws['uuid'] . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }

    protected function view(string $template, array $data = [], string $layout = 'app'): Response
    {
        $user = Auth::user();
        $data += ['user' => $user, 'title' => app_name()];
        if ($this->ws) {
            $data += [
                'ws' => $this->ws,
                'role' => $this->role,
                'workspaces' => $this->db->all(
                    'SELECT w.uuid, w.name, w.color, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ?
                     WHERE w.deleted_at IS NULL ORDER BY w.name',
                    [$user['id']]
                ),
                'reviewCount' => (int) $this->db->value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'in_review' AND deleted_at IS NULL", [$this->ws['id']]),
                'failedCount' => (int) $this->db->value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status IN ('failed','partially_published') AND deleted_at IS NULL AND updated_at > ?", [$this->ws['id'], now_utc(-7 * 86400)]),
            ];
        }
        if ($user && (int) $user['is_super_admin']) {
            $data += ['updateAvailable' => Settings::get('update_available'), 'cronAt' => (Settings::get('cron_heartbeat') ?? [])['at'] ?? null];
        }
        return Response::html(View::render($template, $data, $layout));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(string $fallback, array $flash = []): Response
    {
        foreach ($flash as $k => $v) {
            Session::flash($k, $v);
        }
        $ref = (string) ($this->req->header('referer') ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0]) {
            return Response::redirect($ref);
        }
        return Response::redirect($fallback);
    }

    protected function withOld(array $except = ['password', '_token']): void
    {
        Session::flash('old', array_diff_key(array_filter($this->req->body, 'is_scalar'), array_flip($except)));
    }
}

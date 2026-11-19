<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Settings;

final class HomeController extends Controller
{
    public function index(): Response
    {
        $u = Auth::user();
        if (!$u) {
            return $this->redirect(url('/login'));
        }
        $last = $_SESSION['ws'] ?? null;
        $ws = null;
        if (is_string($last)) {
            $ws = $this->db->value(
                'SELECT w.uuid FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ? WHERE w.uuid = ? AND w.deleted_at IS NULL',
                [$u['id'], $last]
            );
        }
        $ws ??= $this->db->value(
            'SELECT w.uuid FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id AND m.user_id = ? WHERE w.deleted_at IS NULL ORDER BY w.id LIMIT 1',
            [$u['id']]
        );
        if (!$ws) {
            return $this->view('settings/no-workspace', ['title' => 'Create a workspace'], 'auth');
        }
        return $this->redirect(url('/w/' . $ws . '/calendar'));
    }

    public function health(): Response
    {
        $db = true;
        try {
            $this->db->value('SELECT 1');
        } catch (\Throwable) {
            $db = false;
        }
        $hb = $db ? (Settings::get('cron_heartbeat') ?? []) : [];
        $cronOk = !empty($hb['at']) && $hb['at'] > now_utc(-300);
        return $this->json(['ok' => $db, 'database' => $db, 'cron' => $cronOk ? 'ok' : 'stale', 'cron_at' => $hb['at'] ?? null], $db ? 200 : 503);
    }
}

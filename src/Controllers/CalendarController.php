<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\PostService;

final class CalendarController extends Controller
{
    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $channels = $this->db->all('SELECT * FROM channels WHERE workspace_id = ? ORDER BY network, name', [$this->ws['id']]);
        return $this->view('calendar/index', [
            'title' => 'Calendar',
            'channels' => $channels,
            'drafts' => (new PostService($this->db))->unscheduled($this->ws),
            'canEdit' => $this->can('edit'),
            'canChannels' => $this->can('channels'),
        ]);
    }

    public function events(string $ws): Response
    {
        $this->workspace($ws);
        $start = $this->req->str('start');
        $end = $this->req->str('end');
        $valid = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        if (!$valid($start) || !$valid($end) || $end <= $start) {
            return $this->json(['error' => ['message' => 'Invalid date range.']], 422);
        }
        if ((strtotime($end) - strtotime($start)) > 62 * 86400) {
            return $this->json(['error' => ['message' => 'Range too large.']], 422);
        }
        $svc = new PostService($this->db);
        return $this->json([
            'events' => $svc->calendar($this->ws, $start, $end),
            'drafts' => $svc->unscheduled($this->ws),
            'now' => to_local(now_utc(), (string) $this->ws['timezone'], 'Y-m-d H:i'),
        ]);
    }
}

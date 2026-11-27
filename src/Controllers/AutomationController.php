<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;
use App\Services\CommentDmService;

/** Comment-to-DM flows page. Every action answers 404 while the module is off. */
final class AutomationController extends Controller
{
    private function svc(): CommentDmService
    {
        if (!CommentDmService::enabled()) {
            throw new HttpException(404, __('Page not found.'));
        }
        return new CommentDmService($this->db);
    }

    private function flowOr404(string $id): array
    {
        return $this->svc()->flow((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That flow no longer exists.'));
    }

    private function channelOr404(string $id): array
    {
        $c = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]);
        if (!$c || !in_array($c['network'], CommentDmService::NETWORKS, true)) {
            throw new HttpException(404, __('That channel no longer exists.'));
        }
        return $c;
    }

    private function presentAll(): array
    {
        $svc = $this->svc();
        $flows = $svc->flows((int) $this->ws['id']);
        $stats = $svc->stats(array_map(static fn ($f) => (int) $f['id'], $flows));
        return array_map(fn ($f) => $svc->present($f, $stats), $flows);
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        $channels = array_map(function ($c) {
            $meta = json_decode((string) $c['meta_json'], true) ?: [];
            return ['id' => $c['uuid'], 'name' => $c['name'], 'network' => $c['network'], 'networkName' => ProviderFactory::meta($c['network'])['name'],
                'active' => $c['status'] === 'active', 'connected' => !empty($meta['dm_subscribed_at']), 'followCheck' => $c['network'] !== 'facebook'];
        }, $svc->channels((int) $this->ws['id']));
        return $this->view('automations/index', [
            'title' => __('Comment-to-DM'),
            'cfg' => [
                'base' => $this->wsUrl('automations'), 'flows' => $this->presentAll(), 'channels' => $channels,
                'channelsUrl' => $this->wsUrl('channels'), 'isAdmin' => $this->can('channels'),
                'webhookUrl' => CommentDmService::webhookUrl(), 'verifyToken' => $this->can('channels') ? CommentDmService::verifyToken() : '',
                'limits' => ['text' => CommentDmService::TEXT_MAX, 'button' => CommentDmService::BUTTON_MAX],
            ],
        ]);
    }

    public function store(string $ws): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        [$flow, $errors] = $svc->save($this->ws, Auth::user(), $this->req->body);
        if ($errors) {
            return $this->json(['error' => ['message' => (string) reset($errors)], 'errors' => $errors], 422);
        }
        return $this->json(['flow' => $svc->present($flow, $svc->stats([(int) $flow['id']]))]);
    }

    public function update(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        [$flow, $errors] = $svc->save($this->ws, Auth::user(), $this->req->body, $this->flowOr404($id));
        if ($errors) {
            return $this->json(['error' => ['message' => (string) reset($errors)], 'errors' => $errors], 422);
        }
        return $this->json(['flow' => $svc->present($flow, $svc->stats([(int) $flow['id']]))]);
    }

    public function status(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        $svc->setStatus($this->flowOr404($id), $this->req->str('status'));
        return $this->json(['ok' => true]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        $svc->delete($this->flowOr404($id));
        return $this->json(['ok' => true]);
    }

    public function activity(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        return $this->json(['events' => $svc->activity($this->flowOr404($id))]);
    }

    public function posts(string $ws, string $channel): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('automations');
        $posts = array_map(static fn ($p) => ['id' => $p['id'], 'kind' => $p['kind'], 'caption' => mb_substr((string) $p['caption'], 0, 120),
            'thumb' => $p['media'][0]['url'] ?? null, 'isVideo' => ($p['media'][0]['type'] ?? '') === 'video', 'at' => $p['at']], $svc->recentPosts($this->channelOr404($channel)));
        return $this->json(['posts' => $posts]);
    }

    /** Subscribe the account to webhooks (admins: it changes the connected app on Meta's side). */
    public function connect(string $ws, string $channel): Response
    {
        $this->workspace($ws);
        $svc = $this->svc();
        $this->authorize('channels');
        try {
            $svc->subscribe($this->channelOr404($channel));
        } catch (ProviderException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 422);
        }
        return $this->json(['ok' => true]);
    }

    /** Tracked link in the DM: counts the first click, then forwards. */
    public function go(string $id): Response
    {
        $url = (new CommentDmService($this->db))->click($id);
        if ($url === null) {
            throw new HttpException(404, __('This link has expired.'));
        }
        return Response::redirect($url);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Providers\ProviderFactory;
use App\Services\RepurposeService;

/** Repurpose rules page. */
final class RepurposeController extends Controller
{
    private function svc(): RepurposeService
    {
        return new RepurposeService($this->db);
    }

    private function rule(string $id): array
    {
        return $this->svc()->rule((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That rule no longer exists.'));
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $wsId = (int) $this->ws['id'];
        $channels = array_map(fn ($c) => ['id' => $c['uuid'], 'name' => $c['name'], 'network' => $c['network'], 'networkName' => ProviderFactory::meta($c['network'])['name'], 'source' => in_array($c['network'], RepurposeService::SOURCES, true)],
            $this->db->all('SELECT uuid, name, network FROM channels WHERE workspace_id = ? ORDER BY name', [$wsId]));
        return $this->view('repurpose/index', [
            'title' => __('Repurpose'),
            'cfg' => [
                'base' => $this->wsUrl('repurpose'),
                'rules' => array_map(fn ($r) => $this->svc()->present($r, $wsId), $this->svc()->rules($wsId)),
                'channels' => $channels,
                'formats' => array_map('__', RepurposeService::FORMATS), 'modes' => array_map('__', RepurposeService::MODES),
                'canManage' => $this->can('library'), 'channelsUrl' => $this->wsUrl('channels'),
            ],
        ]);
    }

    public function store(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        [$rule, $errors] = $this->svc()->create($this->ws, Auth::user(), $this->req->body);
        if ($errors) {
            return $this->json(['error' => ['message' => (string) reset($errors)], 'errors' => $errors], 422);
        }
        $row = array_values(array_filter($this->svc()->rules((int) $this->ws['id']), fn ($r) => $r['id'] == $rule['id']))[0];
        return $this->json(['rule' => $this->svc()->present($row, (int) $this->ws['id'])]);
    }

    public function status(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $this->svc()->setStatus($this->rule($id), $this->req->str('status'));
        return $this->json(['ok' => true]);
    }

    public function run(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        @set_time_limit(300);
        $rule = $this->rule($id);
        $made = $rule['status'] === 'active' ? $this->svc()->poll($rule) : 0;
        $row = array_values(array_filter($this->svc()->rules((int) $this->ws['id']), fn ($r) => $r['id'] == $rule['id']))[0];
        return $this->json(['made' => $made, 'rule' => $this->svc()->present($row, (int) $this->ws['id'])]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $this->svc()->delete($this->rule($id));
        return $this->json(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\FeedService;
use App\Services\IdeaService;
use App\Services\LibraryService;

/** Ideas board and feed inbox. */
final class IdeaController extends Controller
{
    private function ideas(): IdeaService
    {
        return new IdeaService($this->db);
    }

    private function feeds(): FeedService
    {
        return new FeedService($this->db);
    }

    private function fail(array $errors, int $status = 422): Response
    {
        return $this->json(['error' => ['message' => (string) reset($errors)], 'errors' => $errors], $status);
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $wsId = (int) $this->ws['id'];
        return $this->view('ideas/index', [
            'title' => __('Ideas'),
            'cfg' => [
                'base' => $this->wsUrl(''),
                'stages' => array_map(fn ($s) => ['id' => $s['uuid'], 'name' => $s['name']], $this->ideas()->stages($wsId)),
                'ideas' => array_map([IdeaService::class, 'present'], $this->ideas()->list($wsId)),
                'labels' => array_map(fn ($l) => ['id' => $l['uuid'], 'name' => $l['name'], 'color' => $l['color']], (new LibraryService($this->db))->labels($wsId)),
                'feeds' => array_map([FeedService::class, 'presentFeed'], $this->feeds()->feeds($wsId)),
                'items' => array_map([FeedService::class, 'presentItem'], $this->feeds()->inbox($wsId)),
                'canEdit' => $this->can('edit'), 'canFeeds' => $this->can('library'),
                'composeUrl' => $this->wsUrl('posts/new'),
            ],
        ]);
    }

    private function idea(string $id): array
    {
        return $this->ideas()->find((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That idea no longer exists.'));
    }

    public function store(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        [$idea, $err] = $this->ideas()->save($this->ws, Auth::user(), $this->req->body);
        return $err ? $this->fail($err) : $this->json(['idea' => IdeaService::present($idea)]);
    }

    public function update(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        [$idea, $err] = $this->ideas()->save($this->ws, Auth::user(), $this->req->body, $this->idea($id));
        return $err ? $this->fail($err) : $this->json(['idea' => IdeaService::present($idea)]);
    }

    public function move(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $err = $this->ideas()->move((int) $this->ws['id'], $this->idea($id), $this->req->str('stage'), (int) $this->req->input('position', 0));
        return $err ? $this->fail(['stage' => $err]) : $this->json(['ok' => true]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $this->ideas()->delete($this->idea($id));
        return $this->json(['ok' => true]);
    }

    // Columns
    public function stageStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        [$st, $err] = $this->ideas()->saveStage((int) $this->ws['id'], $this->req->body);
        return $err ? $this->fail($err) : $this->json(['stage' => ['id' => $st['uuid'], 'name' => $st['name']]]);
    }

    public function stageUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $st = $this->ideas()->stage((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That column no longer exists.'));
        [$st, $err] = $this->ideas()->saveStage((int) $this->ws['id'], $this->req->body, $st);
        return $err ? $this->fail($err) : $this->json(['stage' => ['id' => $st['uuid'], 'name' => $st['name']]]);
    }

    public function stageDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $st = $this->ideas()->stage((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That column no longer exists.'));
        $err = $this->ideas()->deleteStage((int) $this->ws['id'], $st);
        return $err ? $this->fail(['stage' => $err]) : $this->json(['ok' => true]);
    }

    // Feeds
    private function feed(string $id): array
    {
        return $this->feeds()->feed((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That feed no longer exists.'));
    }

    public function feedStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        @set_time_limit(60);
        [$feed, $err] = $this->feeds()->add($this->ws, Auth::user(), $this->req->str('url'), $this->req->str('auto'));
        if ($err) {
            return $this->fail(['url' => $err]);
        }
        $wsId = (int) $this->ws['id'];
        $one = array_values(array_filter($this->feeds()->feeds($wsId), fn ($f) => $f['id'] == $feed['id']))[0];
        return $this->json(['feed' => FeedService::presentFeed($one), 'items' => array_map([FeedService::class, 'presentItem'], $this->feeds()->inbox($wsId, (int) $feed['id']))]);
    }

    public function feedUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $this->feeds()->setMode($this->feed($id), $this->req->str('auto'));
        return $this->json(['ok' => true]);
    }

    public function feedDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $this->feeds()->delete($this->feed($id));
        return $this->json(['ok' => true]);
    }

    public function feedRefresh(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        @set_time_limit(60);
        $feed = $this->feed($id);
        $new = $this->feeds()->fetch($feed);
        $fresh = $this->feeds()->feed((int) $this->ws['id'], $id);
        return $this->json(['new' => $new, 'error' => $fresh['last_error'] ?? null, 'items' => array_map([FeedService::class, 'presentItem'], $this->feeds()->inbox((int) $this->ws['id'], (int) $feed['id']))]);
    }

    public function itemIdea(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $item = $this->feeds()->item((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That item no longer exists.'));
        $idea = $this->feeds()->toIdea($this->ws, Auth::user(), $item);
        return $idea ? $this->json(['idea' => IdeaService::present($idea)]) : $this->fail(['item' => __('Could not save the idea.')]);
    }

    public function itemDismiss(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $item = $this->feeds()->item((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That item no longer exists.'));
        $this->feeds()->dismiss($item);
        return $this->json(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\LibraryService;
use App\Services\NoteService;
use App\Services\PostService;

/** Labels, signatures and templates page + JSON actions, and post notes. */
final class LibraryController extends Controller
{
    private function lib(): LibraryService
    {
        return new LibraryService($this->db);
    }

    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $u = Auth::user();
        return $this->view('library/index', [
            'title' => __('Labels & templates'),
            'cfg' => [
                'labels' => $this->lib()->labels((int) $this->ws['id']),
                'signatures' => $this->lib()->signatures((int) $this->ws['id']),
                'templates' => array_map(fn ($t) => $t + ['mine' => (int) $t['user_id'] === (int) $u['id']], $this->lib()->templates((int) $this->ws['id'], (int) $u['id'])),
                'colors' => LibraryService::COLORS,
                'canManage' => $this->can('library'),
                'canEdit' => $this->can('edit'),
                'isAdmin' => $this->role === 'admin',
                'base' => $this->wsUrl(''),
            ],
        ]);
    }

    private function ok(string $key, ?array $row, array $errors): Response
    {
        if ($errors) {
            return $this->json(['error' => ['message' => reset($errors)], 'errors' => $errors], 422);
        }
        unset($row['workspace_id']);
        return $this->json([$key => $row]);
    }

    // Labels
    public function labelStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        [$row, $err] = $this->lib()->saveLabel((int) $this->ws['id'], $this->req->body);
        return $this->ok('label', $row, $err);
    }

    public function labelUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $l = $this->lib()->label((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That label no longer exists.'));
        [$row, $err] = $this->lib()->saveLabel((int) $this->ws['id'], $this->req->body, $l);
        return $this->ok('label', $row, $err);
    }

    public function labelDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $l = $this->lib()->label((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That label no longer exists.'));
        $this->lib()->deleteLabel($l);
        return $this->json(['ok' => true]);
    }

    // Signatures
    public function signatureStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        [$row, $err] = $this->lib()->saveSignature((int) $this->ws['id'], $this->req->body);
        return $this->ok('signature', $row, $err);
    }

    public function signatureUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $s = $this->lib()->signature((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That signature no longer exists.'));
        [$row, $err] = $this->lib()->saveSignature((int) $this->ws['id'], $this->req->body, $s);
        return $this->ok('signature', $row, $err);
    }

    public function signatureDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('library');
        $s = $this->lib()->signature((int) $this->ws['id'], $id) ?? throw new HttpException(404, __('That signature no longer exists.'));
        $this->lib()->deleteSignature($s);
        return $this->json(['ok' => true]);
    }

    // Templates: anyone who can write posts; shared ones are changed by their author or an admin.
    private function ownTemplate(string $id): array
    {
        $u = Auth::user();
        $t = $this->lib()->template((int) $this->ws['id'], (int) $u['id'], $id) ?? throw new HttpException(404, __('That template no longer exists.'));
        if ((int) $t['user_id'] !== (int) $u['id'] && $this->role !== 'admin') {
            throw new HttpException(403, __('Only the person who made this template or an admin can change it.'));
        }
        return $t;
    }

    public function templateStore(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        [$row, $err] = $this->lib()->saveTemplate((int) $this->ws['id'], (int) Auth::user()['id'], $this->req->body);
        return $this->ok('template', $row ? $row + ['mine' => true] : null, $err);
    }

    public function templateUpdate(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $t = $this->ownTemplate($id);
        [$row, $err] = $this->lib()->saveTemplate((int) $this->ws['id'], (int) Auth::user()['id'], $this->req->body, $t);
        return $this->ok('template', $row ? $row + ['mine' => (int) $row['user_id'] === (int) Auth::user()['id']] : null, $err);
    }

    public function templateDelete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $this->lib()->deleteTemplate($this->ownTemplate($id));
        return $this->json(['ok' => true]);
    }

    // Notes on a post: anyone in the workspace can read and add them (approvers and viewers included).
    private function notePost(string $id): array
    {
        $p = (new PostService($this->db))->find((int) $this->ws['id'], $id);
        if (!$p || $p['deleted_at']) {
            throw new HttpException(404, __('That post no longer exists.'));
        }
        return $p;
    }

    public static function presentNote(array $n, int $me): array
    {
        return ['id' => $n['uuid'], 'body' => $n['body'], 'author' => $n['user_name'] ?? null, 'mine' => (int) $n['user_id'] === $me,
            'created_at' => $n['created_at'], 'edited' => !empty($n['edited_at'])];
    }

    public function noteStore(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $p = $this->notePost($id);
        $u = Auth::user();
        [$note, $err] = (new NoteService($this->db))->add($this->ws, $p, $u, $this->req->str('body'), $this->req->arr('mentions'));
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        $note['user_name'] = $u['name'];
        return $this->json(['note' => self::presentNote($note, (int) $u['id'])]);
    }

    public function noteUpdate(string $ws, string $id, string $note): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $svc = new NoteService($this->db);
        $n = $svc->find((int) $this->notePost($id)['id'], $note) ?? throw new HttpException(404, __('That note no longer exists.'));
        if ($err = $svc->edit($n, Auth::user(), $this->req->str('body'))) {
            return $this->json(['error' => ['message' => $err]], 403);
        }
        return $this->json(['ok' => true]);
    }

    public function noteDelete(string $ws, string $id, string $note): Response
    {
        $this->workspace($ws);
        $this->authorize('view');
        $svc = new NoteService($this->db);
        $n = $svc->find((int) $this->notePost($id)['id'], $note) ?? throw new HttpException(404, __('That note no longer exists.'));
        if ($err = $svc->delete($n, Auth::user(), $this->role)) {
            return $this->json(['error' => ['message' => $err]], 403);
        }
        return $this->json(['ok' => true]);
    }
}

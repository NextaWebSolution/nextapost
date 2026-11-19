<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\MediaService;

final class MediaController extends Controller
{
    public function index(string $ws): Response
    {
        $this->workspace($ws);
        $rows = $this->db->all('SELECT * FROM media WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 300', [$this->ws['id']]);
        $used = (int) $this->db->value('SELECT COALESCE(SUM(size_bytes), 0) FROM media WHERE workspace_id = ?', [$this->ws['id']]);
        return $this->view('media/index', [
            'title' => 'Media library', 'media' => array_map([MediaService::class, 'toJson'], $rows), 'used' => $used,
            'canEdit' => $this->can('media'), 'uploadUrl' => $this->wsUrl('media'), 'maxUpload' => MediaService::maxBytes(),
        ]);
    }

    public function list(string $ws): Response
    {
        $this->workspace($ws);
        $rows = $this->db->all('SELECT * FROM media WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 200', [$this->ws['id']]);
        return $this->json(['media' => array_map([MediaService::class, 'toJson'], $rows)]);
    }

    public function upload(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('media');
        if (!\App\Core\RateLimiter::hit($this->db, 'upload:' . Auth::id(), 120, 3600)) {
            return $this->json(['error' => ['message' => 'Upload limit reached (120 files per hour). Try again later.']], 429);
        }
        $file = $this->req->files['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return $this->json(['error' => ['message' => 'Choose a file to upload. If you chose one, it may be larger than the server allows (' . ini_get('post_max_size') . ').']], 422);
        }
        try {
            $m = (new MediaService($this->db))->upload($this->ws, (int) Auth::id(), $file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], 422);
        }
        return $this->json(['ok' => true, 'media' => MediaService::toJson($m)]);
    }

    /** Authenticated download of a document (documents are not web-reachable). Members of the workspace only. */
    public function file(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $m = $this->db->one("SELECT * FROM media WHERE workspace_id = ? AND uuid = ? AND type = 'document'", [$this->ws['id'], $id]);
        $path = $m ? MediaService::absPath($m) : null;
        $root = realpath(APP_ROOT . '/storage/documents');
        if (!$m || !$root || !is_file((string) $path) || !str_starts_with((string) realpath((string) $path), $root . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'File not found.');
        }
        $name = (string) preg_replace('/[^\w .()-]+/u', '_', (string) $m['original_name']) ?: 'document';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('X-Powered-By');
        header('Content-Type: ' . $m['mime']);
        header('Content-Length: ' . filesize((string) $path));
        // Always download, never render in our origin; sandbox in case a browser ignores the disposition.
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $m['original_name']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cache-Control: private, no-store');
        readfile((string) $path);
        exit;
    }

    public function update(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('media');
        $m = $this->db->one('SELECT * FROM media WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]);
        if (!$m) {
            throw new HttpException(404, 'File not found.');
        }
        $this->db->update('media', ['alt_text' => mb_substr($this->req->str('alt'), 0, 1000) ?: null], 'id = ?', [$m['id']]);
        return $this->json(['ok' => true]);
    }

    public function delete(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('media');
        $m = $this->db->one('SELECT * FROM media WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]);
        if (!$m) {
            throw new HttpException(404, 'File not found.');
        }
        $svc = new MediaService($this->db);
        if ($svc->inUseByPendingPosts((int) $m['id']) > 0) {
            $msg = 'This file is attached to a draft or scheduled post. Remove it from those posts first.';
            return $this->req->wantsJson() ? $this->json(['error' => ['message' => $msg]], 422) : $this->back($this->wsUrl('media'), ['error' => $msg]);
        }
        $svc->delete($m);
        return $this->req->wantsJson() ? $this->json(['ok' => true]) : $this->back($this->wsUrl('media'), ['success' => 'File deleted.']);
    }
}

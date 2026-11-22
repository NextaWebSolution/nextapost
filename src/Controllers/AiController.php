<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AiService;
use App\Services\MediaService;

/** AI writing assistant endpoints used by the composer and the media library. */
final class AiController extends Controller
{
    public function assist(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('edit');
        $svc = new AiService($this->db);
        $opt = [];
        if (($ch = $this->req->str('channel')) !== '') {
            $opt = $svc->channelHint((int) $this->ws['id'], $ch);
        }
        $lang = $this->req->str('language');
        if ($lang !== '') {
            $opt['language'] = mb_substr(preg_replace('/[^\p{L}\p{M} ()\-]/u', '', $lang) ?? '', 0, 40);
        }
        $instruction = $this->req->str('instruction');
        if ($instruction !== '') {
            $opt['instruction'] = mb_substr($instruction, 0, 500);
        }
        [$text, $err] = $svc->assist($this->ws, (int) Auth::id(), $this->req->str('action'), $this->req->raw('text'), $opt);
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        return $this->json(['text' => $text]);
    }

    public function altText(string $ws, string $id): Response
    {
        $this->workspace($ws);
        $this->authorize('media');
        $m = $this->db->one('SELECT * FROM media WHERE workspace_id = ? AND uuid = ?', [$this->ws['id'], $id]);
        if (!$m) {
            throw new HttpException(404, __('That file no longer exists.'));
        }
        [$text, $err] = (new AiService($this->db))->altText($this->ws, (int) Auth::id(), MediaService::absPath($m), (string) $m['mime']);
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        return $this->json(['text' => $text]);
    }

    /** Workspace settings: provider, key, model, brand voice. */
    public function save(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        $errors = (new AiService($this->db))->saveSettings($this->ws, $this->req->body);
        if ($errors) {
            $this->withOld(['api_key', '_token']);
            return $this->back($this->wsUrl('settings') . '#ai', ['error' => reset($errors)]);
        }
        return $this->back($this->wsUrl('settings') . '#ai', ['success' => __('AI assistant settings saved.')]);
    }

    public function test(string $ws): Response
    {
        $this->workspace($ws);
        $this->authorize('settings');
        if (!AiService::configured($this->ws)) {
            return $this->json(['error' => ['message' => __('Save a provider and API key first.')]], 422);
        }
        [$text, $err] = (new AiService($this->db))->complete($this->ws, 'You are a connectivity check.', 'Reply with the single word OK.', null, 50);
        if ($err) {
            return $this->json(['error' => ['message' => $err]], 422);
        }
        return $this->json(['ok' => true, 'message' => __('Connected. The model replied: {t}', ['t' => mb_substr(trim((string) $text), 0, 40)])]);
    }
}

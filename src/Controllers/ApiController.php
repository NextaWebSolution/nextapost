<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiError;
use App\Core\Response;
use App\Services\AgentApi;
use App\Services\ApiAuth;

/** REST API v1. Thin mapping from routes to AgentApi operations. */
final class ApiController extends Controller
{
    private ?AgentApi $api = null;

    private function api(): AgentApi
    {
        if ($this->api === null) {
            $ctx = (new ApiAuth($this->db))->resolve($this->req, abs_url('/.well-known/oauth-protected-resource/api/v1'));
            $this->api = new AgentApi($this->db, $ctx, 'api');
        }
        return $this->api;
    }

    /** Arguments: query string for GET/DELETE, JSON or form body otherwise; path ids win. */
    private function args(array $path = []): array
    {
        $in = in_array($this->req->method, ['GET', 'HEAD', 'DELETE'], true) ? $this->req->query : $this->req->body;
        unset($in['_method']);
        return $path + $in;
    }

    private function run(string $op, array $path = [], int $status = 200): Response
    {
        return Response::json(['data' => $this->api()->call($op, $this->args($path))], $status);
    }

    public function index(): Response
    {
        $ops = [];
        foreach (AgentApi::operations() as $name => $op) {
            $ops[] = ['name' => $name, 'title' => $op['title'], 'description' => $op['description']];
        }
        return Response::json([
            'name' => app_name() . ' API', 'version' => 'v1', 'app_version' => APP_VERSION,
            'auth' => 'Authorization: Bearer <API key or OAuth access token>',
            'mcp' => abs_url('/mcp'), 'operations' => $ops,
        ]);
    }

    public function workspaceInfo(): Response { return $this->run('get_workspace'); }
    public function channels(): Response { return $this->run('list_channels'); }
    public function channel(string $id): Response { return $this->run('get_channel', ['channel_id' => $id]); }
    public function schedule(string $id): Response { return $this->run('get_posting_schedule', ['channel_id' => $id]); }
    public function scheduleUpdate(string $id): Response { return $this->run('set_posting_schedule', ['channel_id' => $id]); }

    public function posts(): Response { return $this->run('list_posts'); }
    public function postStore(): Response { return $this->run('create_post', [], 201); }
    public function postBatch(): Response { return $this->run('create_posts', [], 207); }
    public function post(string $id): Response { return $this->run('get_post', ['post_id' => $id]); }
    public function postUpdate(string $id): Response { return $this->run('update_post', ['post_id' => $id]); }
    public function postDelete(string $id): Response { return $this->run('delete_post', ['post_id' => $id]); }

    public function postAction(string $id, string $action): Response
    {
        $op = [
            'schedule' => 'schedule_post', 'unschedule' => 'unschedule_post', 'submit' => 'submit_post_for_review',
            'approve' => 'approve_post', 'reject' => 'reject_post', 'publish' => 'publish_post_now',
            'duplicate' => 'duplicate_post', 'retry' => 'retry_post',
        ][$action] ?? throw new ApiError(404, __('Unknown action.'), 'not_found');
        return $this->run($op, ['post_id' => $id], $op === 'duplicate_post' ? 201 : 200);
    }

    public function notes(string $id): Response { return $this->run('list_notes', ['post_id' => $id]); }
    public function noteStore(string $id): Response { return $this->run('add_note', ['post_id' => $id], 201); }
    public function noteDelete(string $id, string $note): Response { return $this->run('delete_note', ['post_id' => $id, 'note_id' => $note]); }

    public function media(): Response { return $this->run('list_media'); }
    public function mediaItem(string $id): Response { return $this->run('get_media', ['media_id' => $id]); }
    public function mediaUpdate(string $id): Response { return $this->run('update_media', ['media_id' => $id]); }
    public function mediaDelete(string $id): Response { return $this->run('delete_media', ['media_id' => $id]); }

    /** multipart "file" upload, or JSON {url} / {filename, data_base64}. */
    public function mediaStore(): Response
    {
        $api = $this->api();
        $file = $this->req->files['file'] ?? null;
        if (is_array($file) && isset($file['tmp_name']) && is_string($file['tmp_name'])) {
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw ApiError::invalid(['file' => __('The upload failed. The file may be larger than the server allows ({max}).', ['max' => ini_get('upload_max_filesize')])]);
            }
            $ctx = $api->context();
            if (!\App\Core\Gate::can($ctx['role'], 'media', $ctx['ws'])) {
                throw ApiError::forbidden();
            }
            $alt = $this->req->str('alt_text');
            return Response::json(['data' => $api->storeFile($file['tmp_name'], (string) $file['name'], $alt !== '' ? $alt : null, null, 'upload', true)], 201);
        }
        if ($this->req->str('url') !== '') {
            return $this->run('import_media_from_url', [], 201);
        }
        if ($this->req->str('data_base64') !== '') {
            return $this->run('upload_media', [], 201);
        }
        throw ApiError::invalid(['file' => __('Send a multipart “file”, or JSON with “url” or “filename” and “data_base64”.')]);
    }

    public function ideaColumns(): Response { return $this->run('list_idea_columns'); }
    public function ideas(): Response { return $this->run('list_ideas'); }
    public function ideaStore(): Response { return $this->run('create_idea', [], 201); }
    public function ideaUpdate(string $id): Response { return $this->run('update_idea', ['idea_id' => $id]); }
    public function ideaDelete(string $id): Response { return $this->run('delete_idea', ['idea_id' => $id]); }
    public function feeds(): Response { return $this->run('list_feeds'); }
    public function feedStore(): Response { return $this->run('follow_feed', [], 201); }
    public function feedDelete(string $id): Response { return $this->run('unfollow_feed', ['feed_id' => $id]); }
    public function feedItems(): Response { return $this->run('list_feed_items'); }
    public function feedItemIdea(string $id): Response { return $this->run('save_feed_item_as_idea', ['item_id' => $id], 201); }

    /** labels, signatures, templates, webhooks share one shape. */
    private const COLLECTIONS = [
        'labels' => ['list_labels', 'create_label', 'update_label', 'delete_label', 'label_id'],
        'signatures' => ['list_signatures', 'create_signature', 'update_signature', 'delete_signature', 'signature_id'],
        'templates' => ['list_templates', 'create_template', 'update_template', 'delete_template', 'template_id'],
        'webhooks' => ['list_webhooks', 'create_webhook', 'update_webhook', 'delete_webhook', 'webhook_id'],
    ];

    private function coll(string $c): array
    {
        return self::COLLECTIONS[$c] ?? $this->notFound();
    }

    public function collList(string $c): Response { return $this->run($this->coll($c)[0]); }
    public function collStore(string $c): Response { return $this->run($this->coll($c)[1], [], 201); }
    public function collUpdate(string $c, string $id): Response { $x = $this->coll($c); return $this->run($x[2], [$x[4] => $id]); }
    public function collDelete(string $c, string $id): Response { $x = $this->coll($c); return $this->run($x[3], [$x[4] => $id]); }

    public function webhookRotate(string $id): Response { return $this->run('rotate_webhook_secret', ['webhook_id' => $id]); }
    public function webhookDeliveries(string $id): Response { return $this->run('list_webhook_deliveries', ['webhook_id' => $id]); }
    public function webhookReplay(string $id, string $d): Response { return $this->run('replay_webhook_delivery', ['webhook_id' => $id, 'delivery_id' => $d]); }

    public function notFound(): never
    {
        throw new ApiError(404, __('No such API endpoint. GET {url} lists what is available.', ['url' => abs_url('/api/v1')]), 'not_found');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/** Post lifecycle events. Today they feed webhooks; failures never break the action that caused them. */
final class Events
{
    public static function emit(DB $db, int $wsId, string $event, int $postId): void
    {
        try {
            if (!$db->value("SELECT 1 FROM webhooks WHERE workspace_id = ? AND status = 'enabled' LIMIT 1", [$wsId])) {
                return;
            }
            $post = $db->one('SELECT * FROM posts WHERE id = ?', [$postId]);
            $ws = $db->one('SELECT * FROM workspaces WHERE id = ?', [$wsId]);
            if (!$post || !$ws) {
                return;
            }
            (new WebhookService($db))->dispatch($wsId, $event, ['post' => (new Presenter($db))->post($post, $ws)]);
        } catch (\Throwable $e) {
            Logger::error('Webhook event ' . $event . ' could not be queued: ' . $e->getMessage());
        }
    }
}

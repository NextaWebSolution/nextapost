<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\CommentDmService;

/**
 * Receives Instagram and Facebook webhook notifications for Comment-to-DM flows.
 * GET is Meta's one-time verification; POST carries comments and DM button taps, signed with your Meta app secret.
 * Off (404) unless the module is turned on in System → General.
 */
final class MetaWebhookController extends Controller
{
    private const MAX_BODY = 1048576;

    public function verify(): Response
    {
        if (!CommentDmService::enabled()) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $q = $this->req->query;
        $challenge = str_val($q['hub_challenge'] ?? '');
        if (str_val($q['hub_mode'] ?? '') === 'subscribe' && hash_equals(CommentDmService::verifyToken(), str_val($q['hub_verify_token'] ?? ''))
            && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $challenge)) {
            return new Response($challenge, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function receive(): Response
    {
        if (!CommentDmService::enabled()) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            return new Response('Too large', 413, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $svc = new CommentDmService($this->db);
        if (!$svc->signatureValid($raw, (string) $this->req->header('x-hub-signature-256'))) {
            return new Response('Invalid signature', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new Response('Bad request', 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $ids = $svc->ingest($payload);
        if ($ids) {
            // Answer Meta right away, then send the DMs. Anything not finished here is picked up by the cron worker.
            $this->app->defer(static function () use ($svc, $ids): void {
                $deadline = time() + 50;
                foreach ($ids as $id) {
                    if (time() >= $deadline) {
                        break;
                    }
                    $svc->process($id);
                }
            });
        }
        return new Response('EVENT_RECEIVED', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}

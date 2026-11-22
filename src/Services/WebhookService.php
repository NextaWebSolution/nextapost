<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Providers\ProviderException;

/**
 * Outgoing webhooks. Each delivery is signed:
 *   X-NextaPost-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the endpoint secret>
 * Receivers should check the signature and reject timestamps older than 5 minutes.
 * Failed deliveries retry after 1, 5 and 30 minutes; 20 failures in a row pause the endpoint.
 */
final class WebhookService
{
    public const EVENTS = [
        'post.created' => 'A post is created',
        'post.updated' => 'A post is edited',
        'post.scheduled' => 'A post is scheduled',
        'post.unscheduled' => 'A scheduled post goes back to draft',
        'post.approval_requested' => 'A post is sent for approval',
        'post.approved' => 'A post is approved',
        'post.rejected' => 'A post is sent back with changes',
        'post.published' => 'A post is published',
        'post.failed' => 'A post fails to publish',
        'post.deleted' => 'A post is deleted',
    ];
    public const RETRY = [60, 300, 1800];
    public const PAUSE_AFTER = 20;

    public function __construct(private DB $db)
    {
    }

    public function list(int $wsId): array
    {
        return $this->db->all('SELECT * FROM webhooks WHERE workspace_id = ? ORDER BY created_at', [$wsId]);
    }

    public function find(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT * FROM webhooks WHERE workspace_id = ? AND uuid = ?', [$wsId, $uuid]);
    }

    public static function present(array $w): array
    {
        return [
            'id' => $w['uuid'], 'url' => $w['url'], 'events' => json_decode((string) $w['events'], true) ?: [], 'status' => $w['status'],
            'consecutive_failures' => (int) $w['failures'], 'last_sent_at' => Presenter::iso($w['last_sent_at']),
            'paused_at' => Presenter::iso($w['paused_at']), 'created_at' => Presenter::iso($w['created_at']),
        ];
    }

    public static function checkUrl(string $url): ?string
    {
        if (mb_strlen($url) > 700 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return __('Enter a valid URL.');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && !($scheme === 'http' && Env::get('APP_DEBUG'))) {
            return __('Webhook URLs must start with https://.');
        }
        try {
            Http::assertPublicUrl($url);
        } catch (ProviderException $e) {
            return __('This address isn’t reachable from the internet (private or local addresses are blocked).');
        }
        return null;
    }

    /** @return array{0:?array,1:array,2:?string} [webhook, errors, new secret (shown once)] */
    public function save(int $wsId, array $in, ?array $existing = null): array
    {
        $errors = [];
        $url = trim(str_val($in['url'] ?? ($existing['url'] ?? '')));
        if ($err = self::checkUrl($url)) {
            $errors['url'] = $err;
        }
        $events = array_values(array_unique(array_filter((array) ($in['events'] ?? ($existing ? json_decode((string) $existing['events'], true) : [])), fn ($e) => is_string($e) && (isset(self::EVENTS[$e]) || $e === '*'))));
        if (!$events) {
            $errors['events'] = __('Pick at least one event.');
        }
        $status = $in['status'] ?? null;
        if ($status !== null && !in_array($status, ['enabled', 'disabled'], true)) {
            $errors['status'] = __('Status must be enabled or disabled.');
        }
        if (!$existing && (int) $this->db->value('SELECT COUNT(*) FROM webhooks WHERE workspace_id = ?', [$wsId]) >= 20) {
            $errors['url'] = __('A workspace can have up to 20 webhooks.');
        }
        if ($errors) {
            return [null, $errors, null];
        }
        if ($existing) {
            $data = ['url' => $url, 'events' => json_encode($events)];
            if ($status !== null) {
                $data['status'] = $status;
                if ($status === 'enabled') {
                    $data['failures'] = 0;
                    $data['paused_at'] = null;
                }
            }
            $this->db->update('webhooks', $data, 'id = ?', [$existing['id']]);
            return [$this->db->one('SELECT * FROM webhooks WHERE id = ?', [$existing['id']]), [], null];
        }
        $secret = 'whsec_' . Crypto::token(24);
        $id = $this->db->insert('webhooks', [
            'uuid' => uuid4(), 'workspace_id' => $wsId, 'url' => $url, 'events' => json_encode($events),
            'secret_enc' => Crypto::encrypt($secret), 'status' => $status ?? 'enabled', 'created_at' => now_utc(),
        ]);
        return [$this->db->one('SELECT * FROM webhooks WHERE id = ?', [$id]), [], $secret];
    }

    public function rotateSecret(array $w): string
    {
        $secret = 'whsec_' . Crypto::token(24);
        $this->db->update('webhooks', ['secret_enc' => Crypto::encrypt($secret)], 'id = ?', [$w['id']]);
        return $secret;
    }

    public function delete(array $w): void
    {
        $this->db->run('DELETE FROM webhooks WHERE id = ?', [$w['id']]);
    }

    public function logs(array $w, int $limit = 50): array
    {
        return array_map(static fn ($d) => [
            'id' => $d['uuid'], 'event' => $d['event'], 'status' => $d['status'], 'response_code' => $d['response_code'] !== null ? (int) $d['response_code'] : null,
            'response_excerpt' => $d['response_body'], 'attempts' => (int) $d['attempts'], 'created_at' => Presenter::iso($d['created_at']),
            'delivered_at' => Presenter::iso($d['delivered_at']), 'next_attempt_at' => Presenter::iso($d['next_attempt_at']),
        ], $this->db->all('SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$w['id']]));
    }

    /** Queue a delivery for every enabled endpoint subscribed to the event. */
    public function dispatch(int $wsId, string $event, array $data): int
    {
        $hooks = $this->db->all("SELECT * FROM webhooks WHERE workspace_id = ? AND status = 'enabled'", [$wsId]);
        $n = 0;
        foreach ($hooks as $w) {
            $events = json_decode((string) $w['events'], true) ?: [];
            if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
                continue;
            }
            $this->enqueue($w, $event, $data);
            $n++;
        }
        return $n;
    }

    public function enqueue(array $w, string $event, array $data, bool $now = false): array
    {
        $uuid = uuid4();
        $payload = json_encode([
            'id' => $uuid, 'event' => $event, 'created_at' => Presenter::iso(now_utc()),
            'workspace' => ['id' => $this->db->value('SELECT uuid FROM workspaces WHERE id = ?', [$w['workspace_id']])], 'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $id = $this->db->insert('webhook_deliveries', [
            'uuid' => $uuid, 'webhook_id' => $w['id'], 'event' => $event, 'payload' => $payload, 'status' => 'pending', 'attempts' => 0,
            'next_attempt_at' => now_utc(), 'created_at' => now_utc(),
        ]);
        if ($now) {
            $this->deliver($id);
        } else {
            (new Queue($this->db))->push('webhook', ['delivery_id' => $id], 'webhook:' . $id . ':0');
        }
        return $this->db->one('SELECT * FROM webhook_deliveries WHERE id = ?', [$id]);
    }

    public function replay(array $w, string $deliveryUuid): ?array
    {
        $d = $this->db->one('SELECT * FROM webhook_deliveries WHERE webhook_id = ? AND uuid = ?', [$w['id'], $deliveryUuid]);
        if (!$d) {
            return null;
        }
        $data = json_decode((string) $d['payload'], true)['data'] ?? [];
        return $this->enqueue($w, (string) $d['event'], is_array($data) ? $data : [], true);
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** Sends one delivery now. Returns its final row. */
    public function deliver(int $deliveryId): ?array
    {
        $d = $this->db->one('SELECT * FROM webhook_deliveries WHERE id = ?', [$deliveryId]);
        if (!$d || in_array($d['status'], ['delivered', 'failed', 'skipped'], true)) {
            return $d;
        }
        $w = $this->db->one('SELECT * FROM webhooks WHERE id = ?', [$d['webhook_id']]);
        if (!$w || ($w['status'] !== 'enabled' && $d['event'] !== 'webhook.test')) {
            $this->db->update('webhook_deliveries', ['status' => 'skipped'], 'id = ?', [$deliveryId]);
            return $this->db->one('SELECT * FROM webhook_deliveries WHERE id = ?', [$deliveryId]);
        }
        $ts = time();
        $body = (string) $d['payload'];
        $code = null;
        $excerpt = '';
        try {
            $res = Http::request('POST', (string) $w['url'], [
                'body' => $body, 'timeout' => 10, 'public_only' => !Env::get('APP_DEBUG') || !str_starts_with((string) $w['url'], 'http://'),
                'headers' => [
                    'Content-Type: application/json', 'X-NextaPost-Event: ' . $d['event'], 'X-NextaPost-Delivery: ' . $d['uuid'],
                    'X-NextaPost-Timestamp: ' . $ts, 'X-NextaPost-Signature: ' . self::sign(Crypto::decrypt((string) $w['secret_enc']), $ts, $body),
                ],
            ]);
            $code = (int) $res['status'];
            $excerpt = mb_substr(trim((string) $res['body']), 0, 300);
        } catch (\Throwable $e) {
            $excerpt = mb_substr($e->getMessage(), 0, 300);
        }
        $attempts = (int) $d['attempts'] + 1;
        $ok = $code !== null && $code >= 200 && $code < 300;
        if ($ok) {
            $this->db->update('webhook_deliveries', ['status' => 'delivered', 'response_code' => $code, 'response_body' => $excerpt, 'attempts' => $attempts, 'delivered_at' => now_utc(), 'next_attempt_at' => null], 'id = ?', [$deliveryId]);
            $this->db->update('webhooks', ['failures' => 0, 'last_sent_at' => now_utc()], 'id = ?', [$w['id']]);
        } else {
            $retryIn = $d['event'] === 'webhook.test' ? null : (self::RETRY[$attempts - 1] ?? null);
            $this->db->update('webhook_deliveries', [
                'status' => $retryIn ? 'retrying' : 'failed', 'response_code' => $code, 'response_body' => $excerpt, 'attempts' => $attempts,
                'next_attempt_at' => $retryIn ? now_utc($retryIn) : null,
            ], 'id = ?', [$deliveryId]);
            if ($retryIn) {
                (new Queue($this->db))->push('webhook', ['delivery_id' => $deliveryId], 'webhook:' . $deliveryId . ':' . $attempts, now_utc($retryIn));
            }
            if ($d['event'] !== 'webhook.test') {
                $this->db->run('UPDATE webhooks SET failures = failures + 1, last_sent_at = ? WHERE id = ?', [now_utc(), $w['id']]);
                if ((int) $this->db->value('SELECT failures FROM webhooks WHERE id = ?', [$w['id']]) >= self::PAUSE_AFTER) {
                    $this->db->update('webhooks', ['status' => 'paused', 'paused_at' => now_utc()], 'id = ?', [$w['id']]);
                    Logger::write('warning', 'Webhook paused after ' . self::PAUSE_AFTER . ' failed deliveries: ' . $w['url']);
                }
            }
        }
        return $this->db->one('SELECT * FROM webhook_deliveries WHERE id = ?', [$deliveryId]);
    }

    public function prune(int $days = 30): void
    {
        $this->db->run('DELETE FROM webhook_deliveries WHERE created_at < ?', [now_utc(-$days * 86400)]);
    }
}

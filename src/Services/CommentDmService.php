<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Settings;
use App\Providers\ProviderException;
use App\Providers\ProviderFactory;

/**
 * Comment-to-DM flows (optional module). Someone comments a keyword on an Instagram post or Facebook Page post;
 * NextaPost can reply under the comment, sends a private DM (Meta "private reply"), can ask them to follow first,
 * then sends the link. Everything goes through Meta's official APIs.
 *
 * Event states: queued → waiting (DM with a button sent) → follow_asked → done; or skipped / failed.
 */
final class CommentDmService
{
    /** Networks this module works with: Instagram (Instagram login), Instagram (via a Facebook Page), Facebook Page. */
    public const NETWORKS = ['instagram_direct', 'instagram', 'facebook'];
    /** Meta allows a private reply only within 7 days of the comment. */
    private const REPLY_WINDOW = 7 * 86400;
    public const BUTTON_MAX = 20;
    public const TEXT_MAX = 600;
    private const PAYLOAD_PREFIX = 'npdm';

    public function __construct(private DB $db)
    {
    }

    // ── Switch and setup ─────────────────────────────────────────────────

    public static function enabled(): bool
    {
        return (bool) Settings::get('comment_dm_enabled', false);
    }

    /** The token Meta echoes when you add the webhook in your Meta app. Created on first use. */
    public static function verifyToken(): string
    {
        $t = (string) Settings::get('comment_dm_verify_token', '');
        if ($t === '') {
            $t = 'np_' . bin2hex(random_bytes(16));
            Settings::set('comment_dm_verify_token', $t);
        }
        return $t;
    }

    public static function webhookUrl(): string
    {
        return abs_url('webhooks/meta');
    }

    /** Extra permissions requested at sign-in, only while the module is on. */
    public static function extraScopes(string $app): string
    {
        if (!self::enabled()) {
            return '';
        }
        return $app === 'instagram'
            ? ',instagram_business_manage_messages'
            : ',instagram_manage_comments,instagram_manage_messages,pages_messaging,pages_manage_metadata,pages_manage_engagement,pages_read_user_content';
    }

    private static function perHour(): int
    {
        // Meta allows 750 private replies an hour per account; stay a little under it.
        return max(1, min(750, (int) Env::get('DM_PRIVATE_REPLIES_PER_HOUR', 700)));
    }

    // ── Flows ────────────────────────────────────────────────────────────

    public function channels(int $wsId): array
    {
        $in = implode(',', array_fill(0, count(self::NETWORKS), '?'));
        return $this->db->all("SELECT * FROM channels WHERE workspace_id = ? AND network IN ($in) ORDER BY name", array_merge([$wsId], self::NETWORKS));
    }

    public function flows(int $wsId): array
    {
        return $this->db->all('SELECT f.*, c.uuid AS channel_uuid, c.name AS channel_name, c.network AS channel_network, c.status AS channel_status
            FROM dm_flows f JOIN channels c ON c.id = f.channel_id WHERE f.workspace_id = ? ORDER BY f.id DESC', [$wsId]);
    }

    public function flow(int $wsId, string $uuid): ?array
    {
        return $this->db->one('SELECT f.*, c.uuid AS channel_uuid, c.name AS channel_name, c.network AS channel_network, c.status AS channel_status
            FROM dm_flows f JOIN channels c ON c.id = f.channel_id WHERE f.workspace_id = ? AND f.uuid = ?', [$wsId, $uuid]);
    }

    /** @return array{0: ?array, 1: array<string,string>} the saved flow, or the field errors */
    public function save(array $ws, array $user, array $in, ?array $flow = null): array
    {
        $e = [];
        $s = static fn (string $k, int $max) => mb_substr(trim(str_val($in[$k] ?? '')), 0, $max + 1);
        $list = static function ($v, int $maxItems, int $maxLen): array {
            $items = is_array($v) ? $v : preg_split('/[\n,]+/', str_val($v));
            $out = [];
            foreach ($items as $i) {
                $i = trim(str_val($i));
                if ($i !== '' && mb_strlen($i) <= $maxLen) {
                    $out[mb_strtolower($i)] = $i;
                }
            }
            return array_slice(array_values($out), 0, $maxItems);
        };
        $channel = $this->db->one('SELECT * FROM channels WHERE workspace_id = ? AND uuid = ?', [$ws['id'], str_val($in['channel'] ?? '')]);
        if (!$channel || !in_array($channel['network'], self::NETWORKS, true)) {
            $e['channel'] = __('Pick an Instagram account or Facebook Page.');
        }
        $name = $s('name', 120);
        if ($name === '') {
            $e['name'] = __('Give the flow a name.');
        } elseif (mb_strlen($name) > 120) {
            $e['name'] = __('Use 120 characters or fewer.');
        }
        $mode = in_array($in['match_mode'] ?? '', ['keywords', 'any'], true) ? $in['match_mode'] : 'keywords';
        $keywords = $list($in['keywords'] ?? [], 20, 60);
        if ($mode === 'keywords' && !$keywords) {
            $e['keywords'] = __('Add at least one keyword, or choose “Any comment”.');
        }
        $scope = ($in['post_scope'] ?? '') === 'posts' ? 'posts' : 'all';
        $media = array_values(array_filter($list($in['media_ids'] ?? [], 50, 100), fn ($m) => preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $m)));
        if ($scope === 'posts' && !$media) {
            $e['media_ids'] = __('Pick at least one post, or choose “All posts”.');
        }
        $data = [
            'name' => $name, 'status' => $flow['status'] ?? 'active', 'post_scope' => $scope, 'media_ids' => json_encode($media),
            'match_mode' => $mode, 'keywords' => json_encode($keywords), 'exclude_words' => json_encode($list($in['exclude_words'] ?? [], 20, 60)),
            'public_replies' => json_encode($list($in['public_replies'] ?? [], 10, 300)),
            'ask_first' => !empty($in['ask_first']) ? 1 : 0, 'require_follow' => !empty($in['require_follow']) ? 1 : 0,
            'once_per_user' => !array_key_exists('once_per_user', $in) || !empty($in['once_per_user']) ? 1 : 0,
        ];
        if ($channel && $channel['network'] === 'facebook') {
            $data['require_follow'] = 0; // Facebook has no "follows this Page" check in the messaging API
        }
        if ($data['require_follow']) {
            $data['ask_first'] = 1; // Instagram only reveals follows after the person taps a button
        }
        foreach (['opening_text' => [$data['ask_first'], __('Write the first message.')], 'follow_text' => [$data['require_follow'], __('Write the message asking them to follow.')], 'link_text' => [1, __('Write the message that comes with the link.')]] as $k => [$need, $msg]) {
            $v = $s($k, self::TEXT_MAX);
            if ($need && $v === '') {
                $e[$k] = $msg;
            } elseif (mb_strlen($v) > self::TEXT_MAX) {
                $e[$k] = __('Use {n} characters or fewer.', ['n' => self::TEXT_MAX]);
            }
            $data[$k] = $v !== '' ? $v : null;
        }
        $buttons = ['opening_button' => [__('Send me the link'), $data['ask_first']], 'follow_profile_button' => [__('Visit profile'), $data['require_follow']],
            'follow_confirm_button' => [__('I’m following'), $data['require_follow']], 'link_button' => [__('Open link'), 1]];
        foreach ($buttons as $k => [$default, $need]) {
            $v = $s($k, self::BUTTON_MAX);
            if (mb_strlen($v) > self::BUTTON_MAX) {
                $e[$k] = __('Button text can be up to {n} characters.', ['n' => self::BUTTON_MAX]);
            }
            $data[$k] = $need ? ($v !== '' ? $v : mb_substr($default, 0, self::BUTTON_MAX)) : ($v !== '' ? $v : null);
        }
        $url = trim(str_val($in['link_url'] ?? ''));
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || mb_strlen($url) > 700)) {
            $e['link_url'] = __('Enter a full web address starting with https://');
        }
        $data['link_url'] = $url !== '' ? $url : null;
        if ($e) {
            return [null, $e];
        }
        $data['channel_id'] = (int) $channel['id'];
        $data['updated_at'] = now_utc();
        if ($flow) {
            $this->db->update('dm_flows', $data, 'id = ?', [$flow['id']]);
            $id = (int) $flow['id'];
        } else {
            $id = (int) $this->db->insert('dm_flows', $data + ['uuid' => uuid4(), 'workspace_id' => (int) $ws['id'], 'user_id' => (int) $user['id'], 'created_at' => now_utc()]);
        }
        return [$this->db->one('SELECT f.*, c.uuid AS channel_uuid, c.name AS channel_name, c.network AS channel_network, c.status AS channel_status
            FROM dm_flows f JOIN channels c ON c.id = f.channel_id WHERE f.id = ?', [$id]), []];
    }

    public function setStatus(array $flow, string $status): void
    {
        if (in_array($status, ['active', 'paused'], true)) {
            $this->db->update('dm_flows', ['status' => $status, 'updated_at' => now_utc()], 'id = ?', [$flow['id']]);
        }
    }

    public function delete(array $flow): void
    {
        $this->db->run('DELETE FROM dm_flows WHERE id = ?', [$flow['id']]);
    }

    public function stats(array $flowIds): array
    {
        if (!$flowIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($flowIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT flow_id, COUNT(*) AS comments,
                SUM(CASE WHEN dm_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS dms,
                SUM(CASE WHEN link_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS links,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicks,
                SUM(CASE WHEN status = 'follow_asked' THEN 1 ELSE 0 END) AS asked,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued
            FROM dm_events WHERE flow_id IN ($in) GROUP BY flow_id", $flowIds) as $r) {
            $out[(int) $r['flow_id']] = array_map('intval', array_diff_key($r, ['flow_id' => 1]));
        }
        return $out;
    }

    public function present(array $f, array $stats = []): array
    {
        $dec = static fn ($v) => json_decode((string) $v, true) ?: [];
        return [
            'id' => $f['uuid'], 'name' => $f['name'], 'status' => $f['status'], 'channel' => $f['channel_uuid'], 'channelName' => $f['channel_name'],
            'network' => $f['channel_network'], 'networkName' => ProviderFactory::meta((string) $f['channel_network'])['name'],
            'channelActive' => $f['channel_status'] === 'active', 'post_scope' => $f['post_scope'], 'media_ids' => $dec($f['media_ids']),
            'match_mode' => $f['match_mode'], 'keywords' => $dec($f['keywords']), 'exclude_words' => $dec($f['exclude_words']),
            'public_replies' => $dec($f['public_replies']), 'ask_first' => (bool) $f['ask_first'], 'opening_text' => (string) $f['opening_text'],
            'opening_button' => (string) $f['opening_button'], 'require_follow' => (bool) $f['require_follow'], 'follow_text' => (string) $f['follow_text'],
            'follow_profile_button' => (string) $f['follow_profile_button'], 'follow_confirm_button' => (string) $f['follow_confirm_button'],
            'link_text' => (string) $f['link_text'], 'link_button' => (string) $f['link_button'], 'link_url' => (string) $f['link_url'],
            'once_per_user' => (bool) $f['once_per_user'],
            'stats' => ($stats[(int) $f['id']] ?? []) + ['comments' => 0, 'dms' => 0, 'links' => 0, 'clicks' => 0, 'asked' => 0, 'failed' => 0, 'queued' => 0],
        ];
    }

    public function activity(array $flow, int $limit = 100): array
    {
        return array_map(static fn ($r) => [
            'name' => (string) ($r['commenter_name'] ?: '—'), 'comment' => (string) $r['comment_text'], 'status' => $r['status'],
            'at' => $r['comment_at'], 'dm' => $r['dm_sent_at'] !== null, 'link' => $r['link_sent_at'] !== null, 'clicked' => $r['clicked_at'] !== null,
            'follows' => $r['follows'] === null ? null : (bool) $r['follows'], 'error' => (string) $r['last_error'],
        ], $this->db->all('SELECT * FROM dm_events WHERE flow_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$flow['id']]));
    }

    // ── Matching ─────────────────────────────────────────────────────────

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/[^\p{L}\p{N}\p{M}\s]+/u', ' ', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Whole-word or phrase match, case-insensitive, with one-letter typo tolerance for longer Latin keywords. */
    public static function matches(array $flow, string $text, ?string $mediaId): bool
    {
        $dec = static fn ($v) => is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
        if (($flow['post_scope'] ?? 'all') === 'posts' && !in_array((string) $mediaId, $dec($flow['media_ids'] ?? []), true)) {
            return false;
        }
        $norm = ' ' . self::normalize($text) . ' ';
        $words = array_filter(explode(' ', trim($norm)));
        $has = static function (string $kw) use ($norm, $words, $text): bool {
            $k = self::normalize($kw);
            if ($k === '') {
                return $kw !== '' && mb_stripos($text, $kw) !== false; // emoji-only keyword
            }
            if (str_contains($norm, ' ' . $k . ' ')) {
                return true;
            }
            if (!str_contains($k, ' ') && strlen($k) >= 5 && preg_match('/^[a-z0-9]+$/', $k)) {
                foreach ($words as $w) {
                    if (preg_match('/^[a-z0-9]+$/', $w) && abs(strlen($w) - strlen($k)) <= 1 && levenshtein($w, $k) <= 1) {
                        return true;
                    }
                }
            }
            return false;
        };
        foreach ($dec($flow['exclude_words'] ?? []) as $x) {
            if ($has((string) $x)) {
                return false;
            }
        }
        if (($flow['match_mode'] ?? 'keywords') === 'any') {
            return trim($text) !== '';
        }
        foreach ($dec($flow['keywords'] ?? []) as $kw) {
            if ($has((string) $kw)) {
                return true;
            }
        }
        return false;
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    /** X-Hub-Signature-256 must be an HMAC of the exact body with one of your Meta app secrets. */
    public function signatureValid(string $raw, string $header): bool
    {
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        $given = substr($header, 7);
        foreach (['facebook', 'instagram_direct'] as $net) {
            $secret = ProviderFactory::appConfig($net)['client_secret'] ?? '';
            if ($secret !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $given)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read one webhook delivery. New matching comments become queued events (returned for processing);
     * button taps in DMs are answered straight away.
     * @return int[] ids of new events to process
     */
    public function ingest(array $payload): array
    {
        $object = (string) ($payload['object'] ?? '');
        if (!in_array($object, ['instagram', 'page'], true)) {
            return [];
        }
        $new = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $account = str_val($entry['id'] ?? '');
            if ($account === '') {
                continue;
            }
            $channels = $this->channelsFor($object, $account);
            if (!$channels) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $c = $this->commentFrom($object, $account, is_array($ch) ? $ch : []);
                if ($c) {
                    foreach ($channels as $channel) {
                        if ($id = $this->record($channel, $c)) {
                            $new[] = $id;
                        }
                    }
                }
            }
            foreach ((array) ($entry['messaging'] ?? []) as $m) {
                if (is_array($m) && isset($m['postback']['payload'])) {
                    $this->postback($channels, str_val($m['sender']['id'] ?? ''), str_val($m['postback']['payload']));
                }
            }
        }
        return $new;
    }

    private function channelsFor(string $object, string $account): array
    {
        $nets = $object === 'page' ? ['facebook'] : ['instagram_direct', 'instagram'];
        $in = implode(',', array_fill(0, count($nets), '?'));
        return $this->db->all("SELECT * FROM channels WHERE external_id = ? AND status = 'active' AND network IN ($in)", array_merge([$account], $nets));
    }

    /** @return ?array{comment_id:string,text:string,from_id:string,from_name:string,media_id:?string,at:string} */
    private function commentFrom(string $object, string $account, array $change): ?array
    {
        $v = is_array($change['value'] ?? null) ? $change['value'] : [];
        if ($object === 'instagram' && ($change['field'] ?? '') === 'comments') {
            $c = ['comment_id' => str_val($v['id'] ?? ''), 'text' => str_val($v['text'] ?? ''), 'from_id' => str_val($v['from']['id'] ?? ''),
                'from_name' => str_val($v['from']['username'] ?? ''), 'media_id' => str_val($v['media']['id'] ?? '') ?: null, 'at' => now_utc()];
        } elseif ($object === 'page' && ($change['field'] ?? '') === 'feed' && ($v['item'] ?? '') === 'comment' && ($v['verb'] ?? '') === 'add') {
            $postId = str_val($v['post_id'] ?? '');
            $c = ['comment_id' => str_val($v['comment_id'] ?? ''), 'text' => str_val($v['message'] ?? ''), 'from_id' => str_val($v['from']['id'] ?? ''),
                'from_name' => str_val($v['from']['name'] ?? ''), 'media_id' => $postId !== '' ? (string) preg_replace('/^\d+_/', '', $postId) : null,
                'at' => isset($v['created_time']) && is_numeric($v['created_time']) ? gmdate('Y-m-d H:i:s', (int) $v['created_time']) : now_utc()];
        } else {
            return null;
        }
        if ($c['comment_id'] === '' || !preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $c['comment_id']) || $c['from_id'] === $account) {
            return null; // nothing to answer, or the account's own reply
        }
        return $c;
    }

    /** Store a matching comment for the first active flow that wants it. Returns the event id, or null. */
    private function record(array $channel, array $c): ?int
    {
        $flows = $this->db->all("SELECT * FROM dm_flows WHERE channel_id = ? AND status = 'active' ORDER BY CASE WHEN post_scope = 'posts' THEN 0 ELSE 1 END, id", [$channel['id']]);
        foreach ($flows as $flow) {
            if (!self::matches($flow, $c['text'], $c['media_id'])) {
                continue;
            }
            if ($this->db->value('SELECT id FROM dm_events WHERE flow_id = ? AND comment_id = ?', [$flow['id'], $c['comment_id']])) {
                return null; // Meta re-sent the same comment
            }
            $from = $c['from_id'] !== '' ? $c['from_id'] : 'unknown:' . $c['comment_id'];
            $repeat = $flow['once_per_user'] && $c['from_id'] !== ''
                && $this->db->value("SELECT id FROM dm_events WHERE flow_id = ? AND commenter_id = ? AND status NOT IN ('skipped', 'failed')", [$flow['id'], $from]);
            try {
                $id = (int) $this->db->insert('dm_events', [
                    'uuid' => uuid4(), 'workspace_id' => (int) $flow['workspace_id'], 'flow_id' => (int) $flow['id'], 'channel_id' => (int) $channel['id'],
                    'comment_id' => $c['comment_id'], 'media_id' => $c['media_id'] !== null ? mb_substr($c['media_id'], 0, 100) : null,
                    'commenter_id' => mb_substr($from, 0, 100), 'commenter_name' => mb_substr($c['from_name'], 0, 190) ?: null,
                    'comment_text' => mb_substr($c['text'], 0, 1000), 'status' => $repeat ? 'skipped' : 'queued',
                    'last_error' => $repeat ? 'Already received this flow' : null, 'next_at' => now_utc(), 'comment_at' => $c['at'], 'created_at' => now_utc(),
                ]);
                return $repeat || !$id ? null : $id; // a repeat is logged, not sent
            } catch (\PDOException) {
                return null; // same comment arriving twice at once
            }
        }
        return null;
    }

    // ── Sending ──────────────────────────────────────────────────────────

    /** Process queued events whose time has come, until the deadline. */
    public function runDue(int $deadline, int $max = 200): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $done = 0;
        $ids = $this->db->all("SELECT id FROM dm_events WHERE status = 'queued' AND (next_at IS NULL OR next_at <= ?) ORDER BY id LIMIT " . $max, [now_utc()]);
        foreach ($ids as $r) {
            if (time() >= $deadline) {
                break;
            }
            $this->process((int) $r['id']);
            $done++;
        }
        return $done;
    }

    /** First step for a matching comment: optional public reply, then the private DM. */
    public function process(int $eventId): void
    {
        // Claim it, so the webhook and the cron worker never both send it.
        $claimed = $this->db->run("UPDATE dm_events SET status = 'sending', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'queued'", [now_utc(), $eventId])->rowCount();
        if (!$claimed) {
            return;
        }
        $ev = $this->db->one('SELECT * FROM dm_events WHERE id = ?', [$eventId]);
        $flow = $this->db->one('SELECT * FROM dm_flows WHERE id = ?', [$ev['flow_id']]);
        $channel = $this->db->one('SELECT * FROM channels WHERE id = ?', [$ev['channel_id']]);
        $end = fn (string $status, ?string $err = null, array $extra = []) => $this->db->update('dm_events', $extra + ['status' => $status, 'last_error' => $err !== null ? mb_substr($err, 0, 500) : null, 'next_at' => null, 'updated_at' => now_utc()], 'id = ?', [$eventId]);
        if (!$flow || $flow['status'] !== 'active' || !$channel || $channel['status'] !== 'active') {
            $end('skipped', 'Flow paused or channel disconnected');
            return;
        }
        if (strtotime($ev['comment_at'] . ' UTC') < time() - self::REPLY_WINDOW) {
            $end('skipped', 'Comment is older than 7 days (Meta’s limit for private replies)');
            return;
        }
        $sentLastHour = (int) $this->db->value('SELECT COUNT(*) FROM dm_events WHERE channel_id = ? AND dm_sent_at > ?', [$channel['id'], now_utc(-3600)]);
        if ($sentLastHour >= self::perHour()) {
            // Viral post: wait instead of hitting Meta's limit. Comments stay answerable for 7 days.
            $this->db->update('dm_events', ['status' => 'queued', 'attempts' => max(0, (int) $ev['attempts'] - 1), 'next_at' => now_utc(120), 'updated_at' => now_utc()], 'id = ?', [$eventId]);
            return;
        }
        try {
            $creds = $this->credentials($channel);
            $replies = json_decode((string) $flow['public_replies'], true) ?: [];
            if ($replies && !$ev['public_replied']) {
                try {
                    $this->publicReply($channel, $creds, (string) $ev['comment_id'], $this->fill((string) $replies[array_rand($replies)], $ev));
                    $this->db->update('dm_events', ['public_replied' => 1], 'id = ?', [$eventId]);
                } catch (ProviderException $e) {
                    Logger::info('Comment-to-DM public reply failed: ' . $e->getMessage()); // the DM still goes out
                }
            }
            $recipient = ['comment_id' => (string) $ev['comment_id']];
            if ($flow['ask_first']) {
                $this->send($channel, $creds, $recipient, $this->buttons($this->fill((string) $flow['opening_text'], $ev), [
                    ['type' => 'postback', 'title' => (string) $flow['opening_button'], 'payload' => $this->payload($ev, 'link')],
                ]));
                $end('waiting', null, ['dm_sent_at' => now_utc()]);
            } else {
                $this->send($channel, $creds, $recipient, $this->linkMessage($flow, $ev));
                $end('done', null, ['dm_sent_at' => now_utc(), 'link_sent_at' => now_utc()]);
            }
        } catch (ProviderException $e) {
            if ($e->retryable && (int) $ev['attempts'] < 3) {
                $this->db->update('dm_events', ['status' => 'queued', 'next_at' => now_utc([60, 300, 900][(int) $ev['attempts'] - 1] ?? 900), 'last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now_utc()], 'id = ?', [$eventId]);
            } else {
                $end('failed', $e->getMessage());
            }
        } catch (\Throwable $e) {
            Logger::error($e);
            $end('failed', 'Unexpected error');
        }
    }

    /** A tap on a DM button: check the follow if the flow asks for it, then send the link (or ask them to follow). */
    private function postback(array $channels, string $sender, string $payload): void
    {
        if (!preg_match('/^' . self::PAYLOAD_PREFIX . ':([0-9a-f\-]{36}):(link|recheck)$/', $payload, $m) || $sender === '') {
            return;
        }
        $ids = array_map(static fn ($c) => (int) $c['id'], $channels);
        $ev = $this->db->one('SELECT * FROM dm_events WHERE uuid = ?', [$m[1]]);
        if (!$ev || !in_array((int) $ev['channel_id'], $ids, true)) {
            return;
        }
        if (!str_starts_with((string) $ev['commenter_id'], 'unknown:') && !hash_equals((string) $ev['commenter_id'], $sender)) {
            return; // someone else's button
        }
        $flow = $this->db->one('SELECT * FROM dm_flows WHERE id = ?', [$ev['flow_id']]);
        $channel = $this->db->one('SELECT * FROM channels WHERE id = ?', [$ev['channel_id']]);
        if (!$flow || !$channel || $channel['status'] !== 'active') {
            return;
        }
        try {
            $creds = $this->credentials($channel);
            $to = ['id' => $sender];
            $follows = null;
            if ($flow['require_follow'] && $channel['network'] !== 'facebook') {
                $follows = $this->follows($channel, $creds, $sender);
                if ($follows === false) {
                    $meta = json_decode((string) $channel['meta_json'], true) ?: [];
                    $user = ltrim((string) ($meta['username'] ?? $channel['username'] ?? ''), '@');
                    $buttons = [];
                    if ($user !== '' && preg_match('/^[A-Za-z0-9._]{1,30}$/', $user)) {
                        $buttons[] = ['type' => 'web_url', 'title' => (string) $flow['follow_profile_button'], 'url' => 'https://www.instagram.com/' . $user . '/'];
                    }
                    $buttons[] = ['type' => 'postback', 'title' => (string) $flow['follow_confirm_button'], 'payload' => $this->payload($ev, 'recheck')];
                    $this->send($channel, $creds, $to, $this->buttons($this->fill((string) $flow['follow_text'], $ev), $buttons));
                    $this->db->update('dm_events', ['status' => 'follow_asked', 'follows' => 0, 'commenter_id' => $sender, 'updated_at' => now_utc()], 'id = ?', [$ev['id']]);
                    return;
                }
            }
            $this->send($channel, $creds, $to, $this->linkMessage($flow, $ev));
            $this->db->update('dm_events', ['status' => 'done', 'follows' => $follows === null ? null : 1, 'link_sent_at' => now_utc(), 'commenter_id' => $sender, 'last_error' => null, 'updated_at' => now_utc()], 'id = ?', [$ev['id']]);
        } catch (ProviderException $e) {
            $this->db->update('dm_events', ['last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now_utc()], 'id = ?', [$ev['id']]);
        }
    }

    private function payload(array $ev, string $action): string
    {
        return self::PAYLOAD_PREFIX . ':' . $ev['uuid'] . ':' . $action;
    }

    private function fill(string $text, array $ev): string
    {
        $name = (string) ($ev['commenter_name'] ?? '');
        return mb_substr(trim(strtr($text, ['{name}' => $name, '{username}' => $name])), 0, 640);
    }

    private function buttons(string $text, array $buttons): array
    {
        foreach ($buttons as &$b) {
            $b['title'] = mb_substr((string) $b['title'], 0, self::BUTTON_MAX);
        }
        return ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => $text, 'buttons' => $buttons]]];
    }

    private function linkMessage(array $flow, array $ev): array
    {
        $text = $this->fill((string) $flow['link_text'], $ev);
        if (empty($flow['link_url'])) {
            return ['text' => $text];
        }
        return $this->buttons($text, [['type' => 'web_url', 'title' => (string) ($flow['link_button'] ?: __('Open link')), 'url' => abs_url('go/' . $ev['uuid'])]]);
    }

    /** Where the tracked link goes; records the first click. */
    public function click(string $uuid): ?string
    {
        if (!self::enabled() || !preg_match('/^[0-9a-f\-]{36}$/', $uuid)) {
            return null;
        }
        $row = $this->db->one('SELECT e.id, e.clicked_at, f.link_url FROM dm_events e JOIN dm_flows f ON f.id = e.flow_id WHERE e.uuid = ?', [$uuid]);
        $url = (string) ($row['link_url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        if ($row['clicked_at'] === null) {
            $this->db->update('dm_events', ['clicked_at' => now_utc()], 'id = ?', [$row['id']]);
        }
        return $url;
    }

    // ── Meta API ─────────────────────────────────────────────────────────

    private function credentials(array $channel): array
    {
        $creds = Crypto::decryptJson((string) $channel['credentials_enc']);
        $provider = ProviderFactory::make((string) $channel['network']);
        if ($fresh = $provider->refresh($creds, ProviderFactory::appConfig((string) $channel['network']))) {
            $creds = $fresh + $creds;
            $this->db->update('channels', ['credentials_enc' => Crypto::encryptJson($creds)], 'id = ?', [$channel['id']]);
        }
        return $creds;
    }

    private static function base(string $network): string
    {
        $v = (string) Env::get('META_GRAPH_VERSION', 'v21.0');
        $v = preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v21.0';
        return ($network === 'instagram_direct' ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/') . $v . '/';
    }

    private function sender(array $channel, array $creds): string
    {
        $meta = json_decode((string) $channel['meta_json'], true) ?: [];
        return (string) match ($channel['network']) {
            'instagram_direct' => $creds['ig_user_id'] ?? $channel['external_id'],
            'instagram' => $meta['page_id'] ?? '',
            default => $creds['page_id'] ?? $channel['external_id'],
        };
    }

    private function call(string $method, string $url, string $token, array $opts = []): array
    {
        $resp = Http::request($method, $url, $opts + ['headers' => ['Authorization: Bearer ' . $token], 'timeout' => 20]);
        $j = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        if (($resp['status'] ?? 0) < 200 || $resp['status'] >= 300 || isset($j['error'])) {
            $code = (int) ($j['error']['code'] ?? 0);
            $msg = (string) ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? ('HTTP ' . ($resp['status'] ?? 0)));
            if (in_array($code, [10, 200, 230], true) || str_contains($msg, 'permission')) {
                $msg .= ' — reconnect the channel so NextaPost can send messages, and check your Meta app has messaging access.';
            }
            throw new ProviderException('Meta: ' . $msg, ($resp['status'] ?? 0) >= 500 || ($resp['status'] ?? 0) === 0 || in_array($code, [1, 2, 4, 17, 32, 341, 613], true), in_array($code, [190, 102], true));
        }
        return $j;
    }

    private function send(array $channel, array $creds, array $recipient, array $message): array
    {
        $body = ['recipient' => $recipient, 'message' => $message];
        if ($channel['network'] === 'facebook') {
            $body['messaging_type'] = 'RESPONSE';
        }
        return $this->call('POST', self::base((string) $channel['network']) . rawurlencode($this->sender($channel, $creds)) . '/messages', (string) $creds['access_token'], ['json' => $body]);
    }

    private function publicReply(array $channel, array $creds, string $commentId, string $text): void
    {
        if ($text === '') {
            return;
        }
        $edge = $channel['network'] === 'facebook' ? 'comments' : 'replies';
        $this->call('POST', self::base((string) $channel['network']) . rawurlencode($commentId) . '/' . $edge, (string) $creds['access_token'], ['form' => ['message' => $text]]);
    }

    /** true / false from Instagram; null when Instagram doesn't say (then the link is sent anyway). */
    private function follows(array $channel, array $creds, string $userId): ?bool
    {
        try {
            $j = $this->call('GET', self::base((string) $channel['network']) . rawurlencode($userId) . '?fields=is_user_follow_business', (string) $creds['access_token']);
        } catch (ProviderException $e) {
            Logger::info('Comment-to-DM follow check failed: ' . $e->getMessage());
            return null;
        }
        return array_key_exists('is_user_follow_business', $j) ? (bool) $j['is_user_follow_business'] : null;
    }

    /** Ask Meta to send this account's comments and DM button taps to NextaPost. */
    public function subscribe(array $channel): void
    {
        $creds = $this->credentials($channel);
        if ($channel['network'] === 'instagram_direct') {
            $this->call('POST', self::base('instagram_direct') . 'me/subscribed_apps', (string) $creds['access_token'], ['form' => ['subscribed_fields' => 'comments,messages,messaging_postbacks']]);
        } else {
            $page = $this->sender($channel, $creds);
            if ($page === '') {
                throw new ProviderException(__('Reconnect this channel first.'));
            }
            $this->call('POST', self::base('facebook') . rawurlencode($page) . '/subscribed_apps', (string) $creds['access_token'], ['form' => ['subscribed_fields' => 'feed,messages,messaging_postbacks']]);
        }
        $meta = json_decode((string) $channel['meta_json'], true) ?: [];
        $meta['dm_subscribed_at'] = now_utc();
        $this->db->update('channels', ['meta_json' => json_encode($meta)], 'id = ?', [$channel['id']]);
    }

    /** Recent posts of a channel, for picking which posts a flow listens to. */
    public function recentPosts(array $channel): array
    {
        $provider = ProviderFactory::make((string) $channel['network']);
        if (!method_exists($provider, 'recentMedia')) {
            return [];
        }
        try {
            return array_slice($provider->recentMedia($this->credentials($channel), json_decode((string) $channel['meta_json'], true) ?: []), 0, 24);
        } catch (ProviderException) {
            return [];
        }
    }

    /** Old activity holds other people's names and comments; keep 90 days. */
    public function prune(): void
    {
        $this->db->run("DELETE FROM dm_events WHERE created_at < ? AND status IN ('done', 'skipped', 'failed', 'waiting', 'follow_asked')", [now_utc(-90 * 86400)]);
    }
}

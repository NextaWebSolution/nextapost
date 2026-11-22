<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * Threads (Meta) through the Threads API: text, image, video and carousel posts, polls and ghost posts.
 * Needs a Meta app with the Threads use case; long-lived tokens (60 days) are refreshed automatically.
 */
final class ThreadsProvider extends AbstractProvider
{
    private const API = 'https://graph.threads.net/v1.0/';

    public function meta(): array
    {
        return ['name' => 'Threads', 'color' => '#101010', 'letter' => '@', 'kind' => 'oauth', 'app' => 'threads',
            'description' => 'Post text, photos, videos, carousels and polls to a Threads profile.',
            'content_types' => ['post' => __('Post'), 'poll' => __('Poll'), 'ghost' => __('Ghost post (disappears after 24 hours)')],
            'content_rules' => ['poll' => ['media' => 'none'], 'ghost' => ['media' => 'none']],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 500, 'media_max' => 20, 'types' => ['image', 'video'],
            'max_bytes' => ['image' => 8 * 1024 * 1024, 'video' => 1024 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'poll', 'label' => __('Poll choices'), 'type' => 'list', 'types' => ['poll'], 'required' => true, 'min' => 2, 'max' => 4, 'item_max' => 25, 'placeholder' => __('Choice')],
            ['key' => 'reply_control', 'label' => __('Who can reply'), 'type' => 'select', 'types' => ['post', 'poll'], 'empty' => __('Everyone'),
                'choices' => ['accounts_you_follow' => __('Profiles you follow'), 'mentioned_only' => __('Profiles you mention'), 'followers_only' => __('Your followers')]],
            ['key' => 'topic_tag', 'label' => __('Topic'), 'type' => 'text', 'types' => ['post', 'poll'], 'max' => 50, 'help' => __('One topic tag, without the #.')],
        ];
    }

    public function validate(string $text, array $media, array $meta = []): array
    {
        $errors = parent::validate($text, $media, $meta);
        if (preg_match_all('#https?://\S+#i', $text) > 5) {
            $errors[] = __('Threads allows up to 5 links in a post.');
        }
        return $errors;
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://threads.net/oauth/authorize?' . http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
            'scope' => 'threads_basic,threads_content_publish',
        ]);
    }

    private function call(string $method, string $path, array $params): array
    {
        $url = str_starts_with($path, 'https://') ? $path : self::API . ltrim($path, '/');
        $resp = $method === 'GET'
            ? $this->http('GET', $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params))
            : $this->http($method, $url, ['form' => $params]);
        $j = is_array($resp['json']) ? $resp['json'] : [];
        if ($resp['status'] < 200 || $resp['status'] >= 300 || isset($j['error'])) {
            $code = (int) ($j['error']['code'] ?? 0);
            $msg = (string) ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? ('HTTP ' . $resp['status']));
            throw new ProviderException('Threads: ' . $msg, $resp['status'] >= 500 || $resp['status'] === 0 || in_array($code, [1, 2, 4, 17, 613], true), in_array($code, [190, 102], true) || $resp['status'] === 401);
        }
        return $j;
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('Threads sign-in was cancelled.'));
        }
        $short = $this->call('POST', 'https://graph.threads.net/oauth/access_token', [
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri, 'code' => (string) ($query['code'] ?? ''),
        ]);
        $long = $this->call('GET', 'https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token', 'client_secret' => $app['client_secret'], 'access_token' => (string) $short['access_token'],
        ]);
        $token = (string) $long['access_token'];
        $me = $this->call('GET', 'me', ['fields' => 'id,username,name,threads_profile_picture_url', 'access_token' => $token]);
        return [[
            'external_id' => (string) $me['id'], 'name' => (string) (($me['name'] ?? '') ?: $me['username']), 'username' => '@' . ($me['username'] ?? ''),
            'avatar_url' => $me['threads_profile_picture_url'] ?? null,
            'credentials' => ['user_id' => (string) $me['id'], 'access_token' => $token, 'expires_at' => time() + (int) ($long['expires_in'] ?? 5184000), 'issued_at' => time()],
            'meta' => ['username' => $me['username'] ?? null],
        ]];
    }

    /** Long-lived tokens last 60 days; refresh once they are a day old and within 10 days of expiry. */
    public function refresh(array $credentials, array $app): ?array
    {
        $exp = (int) ($credentials['expires_at'] ?? 0);
        if ($exp === 0 || $exp - time() > 10 * 86400 || time() - (int) ($credentials['issued_at'] ?? 0) < 86400) {
            return null;
        }
        if ($exp < time()) {
            throw new ProviderException(__('The Threads sign-in has expired. Reconnect the channel.'), false, true);
        }
        $r = $this->call('GET', 'https://graph.threads.net/refresh_access_token', ['grant_type' => 'th_refresh_token', 'access_token' => (string) $credentials['access_token']]);
        return ['access_token' => (string) $r['access_token'], 'expires_at' => time() + (int) ($r['expires_in'] ?? 5184000), 'issued_at' => time()];
    }

    private function waitUntilReady(string $container, string $token): void
    {
        for ($i = 0; $i < 40; $i++) {
            $s = $this->call('GET', $container, ['fields' => 'status,error_message', 'access_token' => $token]);
            $st = (string) ($s['status'] ?? 'FINISHED');
            if ($st === 'FINISHED' || $st === 'PUBLISHED') {
                return;
            }
            if ($st === 'ERROR' || $st === 'EXPIRED') {
                throw new ProviderException('Threads could not process the media: ' . ($s['error_message'] ?? $st));
            }
            sleep(3);
        }
        throw new ProviderException(__('Threads is still processing the video. It will be retried.'), true);
    }

    private function item(array $m, array $base): array
    {
        $p = $base + ['media_type' => $m['type'] === 'video' ? 'VIDEO' : 'IMAGE'];
        $p[$m['type'] === 'video' ? 'video_url' : 'image_url'] = $m['url'];
        if (!empty($m['alt'])) {
            $p['alt_text'] = mb_substr((string) $m['alt'], 0, 1000);
        }
        return $p;
    }

    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        $out = [];
        foreach ((array) ($this->call('GET', $externalId . '/insights', ['metric' => 'views,likes,replies,reposts,quotes', 'access_token' => (string) $credentials['access_token']])['data'] ?? []) as $i) {
            $out[$i['name'] ?? ''] = (int) ($i['values'][0]['value'] ?? $i['total_value']['value'] ?? 0);
        }
        return $out ? ['views' => $out['views'] ?? null, 'likes' => $out['likes'] ?? null, 'comments' => $out['replies'] ?? null, 'shares' => ($out['reposts'] ?? 0) + ($out['quotes'] ?? 0)] : null;
    }

    /** Recent posts of the profile (for repurposing), newest first. */
    public function recentMedia(array $credentials, array $meta = []): array
    {
        $j = $this->call('GET', (string) $credentials['user_id'] . '/threads', [
            'fields' => 'id,media_type,media_url,text,permalink,timestamp,children{media_type,media_url}', 'limit' => 25, 'access_token' => (string) $credentials['access_token'],
        ]);
        $out = [];
        foreach ((array) ($j['data'] ?? []) as $m) {
            if (in_array($m['media_type'] ?? '', ['REPOST_FACADE', 'AUDIO'], true)) {
                continue;
            }
            $items = [];
            foreach ((array) ($m['children']['data'] ?? [$m]) as $c) {
                if (!empty($c['media_url'])) {
                    $items[] = ['type' => ($c['media_type'] ?? '') === 'VIDEO' ? 'video' : 'image', 'url' => (string) $c['media_url']];
                }
            }
            $out[] = [
                'id' => (string) $m['id'], 'kind' => ($m['media_type'] ?? '') === 'VIDEO' ? 'video' : (($m['media_type'] ?? '') === 'TEXT_POST' ? 'text' : 'post'),
                'media' => $items, 'caption' => (string) ($m['text'] ?? ''), 'url' => $m['permalink'] ?? null,
                'at' => isset($m['timestamp']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $m['timestamp'])) : null,
            ];
        }
        return $out;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $uid = (string) $credentials['user_id'];
        $token = (string) $credentials['access_token'];
        $type = $this->type($context);
        $p = ['access_token' => $token];
        if ($type !== 'ghost') {
            if ($rc = $this->opt($context, 'reply_control')) {
                $p['reply_control'] = (string) $rc;
            }
            if ($topic = $this->opt($context, 'topic_tag')) {
                $p['topic_tag'] = ltrim((string) $topic, '#');
            }
        }
        if ($type === 'poll' || $type === 'ghost' || !$media) {
            $p += ['media_type' => 'TEXT', 'text' => $text];
            if ($type === 'ghost') {
                $p['is_ghost_post'] = 'true';
            }
            if ($type === 'poll') {
                $choices = array_values(array_filter(array_map('trim', (array) $this->opt($context, 'poll', [])), 'strlen'));
                $poll = [];
                foreach (array_slice($choices, 0, 4) as $i => $c) {
                    $poll['option_' . chr(97 + $i)] = $c;
                }
                $p['poll_attachment'] = (string) json_encode($poll, JSON_UNESCAPED_UNICODE);
            }
            $container = (string) $this->call('POST', $uid . '/threads', $p)['id'];
        } elseif (count($media) === 1) {
            $container = (string) $this->call('POST', $uid . '/threads', $this->item($media[0], $p + ['text' => $text]))['id'];
            if ($media[0]['type'] === 'video') {
                $this->waitUntilReady($container, $token);
            }
        } else {
            $children = [];
            foreach ($media as $m) {
                $child = (string) $this->call('POST', $uid . '/threads', $this->item($m, ['access_token' => $token, 'is_carousel_item' => 'true']))['id'];
                if ($m['type'] === 'video') {
                    $this->waitUntilReady($child, $token);
                }
                $children[] = $child;
            }
            $container = (string) $this->call('POST', $uid . '/threads', $p + ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'text' => $text])['id'];
            $this->waitUntilReady($container, $token);
        }
        $id = (string) $this->call('POST', $uid . '/threads_publish', ['creation_id' => $container, 'access_token' => $token])['id'];
        $url = null;
        try {
            $url = $this->call('GET', $id, ['fields' => 'permalink', 'access_token' => $token])['permalink'] ?? null;
        } catch (\Throwable) {
        }
        return new PublishResult($id, $url);
    }
}

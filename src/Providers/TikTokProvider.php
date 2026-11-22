<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * TikTok Content Posting API (direct post): videos uploaded in chunks, and photo posts.
 * Apps that TikTok hasn't audited yet can only post privately ("Only me").
 */
final class TikTokProvider extends AbstractProvider
{
    private const API = 'https://open.tiktokapis.com/v2/';
    private const CHUNK = 10 * 1024 * 1024;

    public function meta(): array
    {
        return ['name' => 'TikTok', 'color' => '#010101', 'letter' => 'T', 'kind' => 'oauth', 'app' => 'tiktok',
            'description' => 'Post videos and photo posts to a TikTok account.',
            'content_types' => ['video' => __('Video'), 'photo' => __('Photo post')],
            'content_rules' => ['video' => ['media' => 'video', 'max_media' => 1], 'photo' => ['media' => 'image', 'max_media' => 35]],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 2200, 'media_max' => 35, 'types' => ['image', 'video'], 'media_required' => true,
            'max_bytes' => ['image' => 20 * 1024 * 1024, 'video' => 4 * 1024 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'privacy', 'label' => __('Who can view'), 'type' => 'select', 'required' => true, 'empty' => __('Choose…'),
                'choices' => ['PUBLIC_TO_EVERYONE' => __('Everyone'), 'MUTUAL_FOLLOW_FRIENDS' => __('Friends'), 'FOLLOWER_OF_CREATOR' => __('Followers'), 'SELF_ONLY' => __('Only me')],
                'help' => __('Apps not yet audited by TikTok can only post with “Only me”.')],
            ['key' => 'allow_comment', 'label' => __('Allow comments'), 'type' => 'checkbox', 'default' => false],
            ['key' => 'allow_duet', 'label' => __('Allow Duet'), 'type' => 'checkbox', 'types' => ['video'], 'default' => false],
            ['key' => 'allow_stitch', 'label' => __('Allow Stitch'), 'type' => 'checkbox', 'types' => ['video'], 'default' => false],
            ['key' => 'brand_organic', 'label' => __('Promotes my own business'), 'type' => 'checkbox', 'default' => false],
            ['key' => 'brand_content', 'label' => __('Paid partnership (branded content)'), 'type' => 'checkbox', 'default' => false],
            ['key' => 'is_aigc', 'label' => __('Made with AI'), 'type' => 'checkbox', 'types' => ['video'], 'default' => false],
            ['key' => 'title', 'label' => __('Photo post title'), 'type' => 'text', 'types' => ['photo'], 'max' => 90],
        ];
    }

    public function validateOptions(?string $contentType, array $options, string $text, array $media = [], array $meta = [], bool $strict = true): array
    {
        $errors = parent::validateOptions($contentType, $options, $text, $media, $meta, $strict);
        if (!empty($options['brand_content']) && ($options['privacy'] ?? '') === 'SELF_ONLY') {
            $errors[] = __('TikTok: branded content can’t be private. Choose who can view it.');
        }
        return $errors;
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
            'client_key' => $app['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
            'scope' => 'user.info.basic,video.publish,video.upload',
        ]);
    }

    /** TikTok answers {data, error:{code:"ok"|…, message}}. */
    private function api(string $method, string $path, string $token, mixed $json = null): array
    {
        $opts = ['headers' => ['Authorization: Bearer ' . $token]];
        if ($json !== null) {
            $opts['json'] = $json;
        }
        $r = $this->http($method, self::API . ltrim($path, '/'), $opts);
        $code = (string) ($r['json']['error']['code'] ?? ($r['status'] === 200 ? 'ok' : 'http_' . $r['status']));
        if ($r['status'] < 200 || $r['status'] >= 300 || $code !== 'ok') {
            $msg = (string) ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']);
            $retry = $r['status'] >= 500 || $r['status'] === 0 || in_array($code, ['rate_limit_exceeded', 'internal_error'], true);
            $auth = in_array($code, ['access_token_invalid', 'scope_not_authorized'], true) || $r['status'] === 401;
            $friendly = match ($code) {
                'spam_risk_too_many_posts' => __('TikTok: this account has reached its daily posting limit. Try again tomorrow.'),
                'unaudited_client_can_only_post_to_private_accounts' => __('TikTok: the app isn’t audited yet, so it can only post with “Only me”.'),
                'privacy_level_option_mismatch' => __('TikTok: that privacy setting isn’t available for this account.'),
                default => 'TikTok: ' . $msg . ' (' . $code . ')',
            };
            throw new ProviderException($friendly, $retry, $auth);
        }
        return (array) ($r['json']['data'] ?? []);
    }

    private function token(array $app, array $form): array
    {
        $r = $this->http('POST', self::API . 'oauth/token/', ['form' => $form + ['client_key' => $app['client_id'], 'client_secret' => $app['client_secret']]]);
        if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
            throw new ProviderException('TikTok: ' . (string) ($r['json']['error_description'] ?? $r['json']['error'] ?? 'sign-in failed'), $r['status'] >= 500, true);
        }
        $j = $r['json'];
        return [
            'access_token' => (string) $j['access_token'], 'refresh_token' => (string) $j['refresh_token'], 'open_id' => (string) ($j['open_id'] ?? ''),
            'expires_at' => time() + (int) ($j['expires_in'] ?? 86400), 'refresh_expires_at' => time() + (int) ($j['refresh_expires_in'] ?? 31536000),
        ];
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('TikTok sign-in was cancelled.'));
        }
        $creds = $this->token($app, ['grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $redirectUri]);
        $u = $this->api('GET', 'user/info/?fields=open_id,avatar_url,display_name', $creds['access_token'])['user'] ?? [];
        $creator = [];
        try {
            $creator = $this->api('POST', 'post/publish/creator_info/query/', $creds['access_token'], (object) []);
        } catch (\Throwable) {
        }
        return [[
            'external_id' => (string) ($u['open_id'] ?? $creds['open_id']), 'name' => (string) (($u['display_name'] ?? '') ?: 'TikTok'),
            'username' => isset($creator['creator_username']) ? '@' . $creator['creator_username'] : null, 'avatar_url' => $u['avatar_url'] ?? null,
            'credentials' => $creds, 'meta' => ['username' => $creator['creator_username'] ?? null],
        ]];
    }

    public function refresh(array $credentials, array $app): ?array
    {
        if ((int) ($credentials['expires_at'] ?? 0) - time() > 600) {
            return null;
        }
        if ((int) ($credentials['refresh_expires_at'] ?? 0) < time()) {
            throw new ProviderException(__('The TikTok sign-in has expired. Reconnect the channel.'), false, true);
        }
        return $this->token($app, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $credentials['refresh_token']]);
    }

    /** Chunk plan: files under 5 MB go in one piece; the last chunk takes the remainder (TikTok's rule). */
    public static function chunks(int $size): array
    {
        if ($size <= self::CHUNK) {
            return ['chunk_size' => $size, 'count' => 1];
        }
        return ['chunk_size' => self::CHUNK, 'count' => intdiv($size, self::CHUNK)];
    }

    private function postInfo(string $text, array $context, bool $video): array
    {
        $info = [
            'privacy_level' => (string) $this->opt($context, 'privacy', ''),
            'disable_comment' => !filter_var($this->opt($context, 'allow_comment', false), FILTER_VALIDATE_BOOL),
            'brand_organic_toggle' => filter_var($this->opt($context, 'brand_organic', false), FILTER_VALIDATE_BOOL),
            'brand_content_toggle' => filter_var($this->opt($context, 'brand_content', false), FILTER_VALIDATE_BOOL),
        ];
        if ($video) {
            $info += [
                'title' => mb_substr($text, 0, 2200),
                'disable_duet' => !filter_var($this->opt($context, 'allow_duet', false), FILTER_VALIDATE_BOOL),
                'disable_stitch' => !filter_var($this->opt($context, 'allow_stitch', false), FILTER_VALIDATE_BOOL),
                'is_aigc' => filter_var($this->opt($context, 'is_aigc', false), FILTER_VALIDATE_BOOL),
            ];
        } else {
            $info += ['title' => mb_substr((string) ($this->opt($context, 'title') ?: str_excerpt($text, 90)), 0, 90), 'description' => mb_substr($text, 0, 4000), 'auto_add_music' => true];
        }
        return $info;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $token = (string) $credentials['access_token'];
        $privacy = (string) $this->opt($context, 'privacy', '');
        if ($privacy === '') {
            throw new ProviderException(__('Choose who can view this TikTok post.'));
        }
        $creator = $this->api('POST', 'post/publish/creator_info/query/', $token, (object) []);
        if (!in_array($privacy, (array) ($creator['privacy_level_options'] ?? [$privacy]), true)) {
            throw new ProviderException(__('TikTok: that privacy setting isn’t available for this account (allowed: {list}).', ['list' => implode(', ', (array) $creator['privacy_level_options'])]));
        }
        $isVideo = $this->type($context) !== 'photo' && ($media[0]['type'] ?? '') === 'video';
        if ($isVideo) {
            $v = $media[0];
            $plan = self::chunks((int) $v['size']);
            $init = $this->api('POST', 'post/publish/video/init/', $token, [
                'post_info' => $this->postInfo($text, $context, true),
                'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => (int) $v['size'], 'chunk_size' => $plan['chunk_size'], 'total_chunk_count' => $plan['count']],
            ]);
            for ($i = 0; $i < $plan['count']; $i++) {
                $start = $i * $plan['chunk_size'];
                $len = $i === $plan['count'] - 1 ? (int) $v['size'] - $start : $plan['chunk_size'];
                $r = $this->http('PUT', (string) $init['upload_url'], [
                    'infile' => $v['path'], 'infile_offset' => $start, 'infile_length' => $len, 'timeout' => 600,
                    'headers' => ['Content-Type: ' . $v['mime'], 'Content-Range: bytes ' . $start . '-' . ($start + $len - 1) . '/' . (int) $v['size']],
                ]);
                if ($r['status'] < 200 || $r['status'] >= 300) {
                    throw new ProviderException('TikTok: the video upload failed (HTTP ' . $r['status'] . ').', true);
                }
            }
        } else {
            $init = $this->api('POST', 'post/publish/content/init/', $token, [
                'post_info' => $this->postInfo($text, $context, false), 'post_mode' => 'DIRECT_POST', 'media_type' => 'PHOTO',
                'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_map(fn ($m) => $m['url'], array_slice($media, 0, 35))],
            ]);
        }
        $publishId = (string) $init['publish_id'];
        for ($i = 0; $i < 40; $i++) {
            $s = $this->api('POST', 'post/publish/status/fetch/', $token, ['publish_id' => $publishId]);
            $st = (string) ($s['status'] ?? '');
            if ($st === 'PUBLISH_COMPLETE') {
                $postId = (string) (($s['publicaly_available_post_id'] ?? [])[0] ?? '');
                $user = $meta['username'] ?? ($creator['creator_username'] ?? null);
                return new PublishResult($postId !== '' ? $postId : $publishId, $postId !== '' && $user ? 'https://www.tiktok.com/@' . $user . '/video/' . $postId : null);
            }
            if ($st === 'FAILED') {
                throw new ProviderException('TikTok: ' . (string) ($s['fail_reason'] ?? 'the post failed'));
            }
            sleep(4);
        }
        // Still processing on TikTok's side: the post will appear on its own; keep the publish id.
        return new PublishResult($publishId, null);
    }
}

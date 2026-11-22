<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Env;

/**
 * Pinterest API v5: image pins, video pins and carousels (2–5 images) on a chosen board.
 * Trial apps can only create pins in Pinterest's sandbox; set PINTEREST_SANDBOX=1 until the app has Standard access.
 */
final class PinterestProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Pinterest', 'color' => '#E60023', 'letter' => 'P', 'kind' => 'oauth', 'app' => 'pinterest',
            'description' => 'Create image pins, video pins and carousel pins on your boards.',
            'content_types' => ['pin' => __('Image pin'), 'video' => __('Video pin'), 'carousel' => __('Carousel pin (2–5 images)')],
            'content_rules' => [
                'pin' => ['media' => 'image', 'max_media' => 1],
                'video' => ['media' => 'video', 'max_media' => 1],
                'carousel' => ['media' => 'image', 'min_media' => 2, 'max_media' => 5],
            ],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 800, 'media_max' => 5, 'types' => ['image', 'video'], 'media_required' => true,
            'max_bytes' => ['image' => 20 * 1024 * 1024, 'video' => 1024 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        $boards = [];
        foreach ((array) ($meta['boards'] ?? []) as $b) {
            $boards[(string) $b['id']] = (string) $b['name'];
        }
        return [
            ['key' => 'board', 'label' => __('Board'), 'type' => 'select', 'required' => true, 'dynamic' => true, 'choices' => $boards,
                'help' => $boards ? '' : __('No boards found. Create a board on Pinterest, then reconnect this channel.')],
            ['key' => 'title', 'label' => __('Pin title'), 'type' => 'text', 'max' => 100],
            ['key' => 'link', 'label' => __('Destination link'), 'type' => 'url', 'placeholder' => 'https://'],
        ];
    }

    private static function base(): string
    {
        return Env::get('PINTEREST_SANDBOX') ? 'https://api-sandbox.pinterest.com/v5/' : 'https://api.pinterest.com/v5/';
    }

    private function api(string $method, string $path, string $token, array $opts = []): array
    {
        $opts['headers'] = array_merge(['Authorization: Bearer ' . $token], $opts['headers'] ?? []);
        return $this->ok($this->http($method, self::base() . ltrim($path, '/'), $opts));
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://www.pinterest.com/oauth/?' . http_build_query([
            'client_id' => $app['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
            'scope' => 'boards:read,pins:read,pins:write,user_accounts:read',
        ]);
    }

    private function token(array $app, array $form): array
    {
        return $this->ok($this->http('POST', 'https://api.pinterest.com/v5/oauth/token', [
            'headers' => ['Authorization: Basic ' . base64_encode($app['client_id'] . ':' . $app['client_secret'])], 'form' => $form,
        ]));
    }

    private function creds(array $t, array $old = []): array
    {
        return [
            'access_token' => (string) $t['access_token'],
            'refresh_token' => (string) ($t['refresh_token'] ?? ($old['refresh_token'] ?? '')),
            'expires_at' => time() + (int) ($t['expires_in'] ?? 2592000),
            'refresh_expires_at' => isset($t['refresh_token_expires_in']) ? time() + (int) $t['refresh_token_expires_in'] : (int) ($old['refresh_expires_at'] ?? 0),
        ];
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        if (!empty($query['error'])) {
            throw new ProviderException(__('Pinterest sign-in was cancelled.'));
        }
        $creds = $this->creds($this->token($app, ['grant_type' => 'authorization_code', 'code' => (string) ($query['code'] ?? ''), 'redirect_uri' => $redirectUri]));
        $me = $this->api('GET', 'user_account', $creds['access_token']);
        $boards = [];
        $bookmark = null;
        for ($i = 0; $i < 5; $i++) {
            $page = $this->api('GET', 'boards?page_size=100' . ($bookmark ? '&bookmark=' . rawurlencode($bookmark) : ''), $creds['access_token']);
            foreach ((array) ($page['items'] ?? []) as $b) {
                $boards[] = ['id' => (string) $b['id'], 'name' => (string) $b['name']];
            }
            $bookmark = $page['bookmark'] ?? null;
            if (!$bookmark) {
                break;
            }
        }
        $username = (string) ($me['username'] ?? '');
        return [[
            'external_id' => (string) ($me['id'] ?? $username), 'name' => (string) (($me['business_name'] ?? '') ?: $username),
            'username' => '@' . $username, 'avatar_url' => $me['profile_image'] ?? null,
            'credentials' => $creds, 'meta' => ['username' => $username, 'boards' => $boards],
        ]];
    }

    public function refresh(array $credentials, array $app): ?array
    {
        if ((int) ($credentials['expires_at'] ?? 0) - time() > 3 * 86400) {
            return null;
        }
        if (empty($credentials['refresh_token']) || ((int) ($credentials['refresh_expires_at'] ?? 0) && (int) $credentials['refresh_expires_at'] < time())) {
            throw new ProviderException(__('The Pinterest sign-in has expired. Reconnect the channel.'), false, true);
        }
        try {
            return $this->creds($this->token($app, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $credentials['refresh_token']]), $credentials);
        } catch (ProviderException $e) {
            throw new ProviderException(__('Pinterest refused to renew the sign-in. Reconnect the channel.'), $e->retryable, !$e->retryable);
        }
    }

    private static function b64(array $m): array
    {
        return ['content_type' => $m['mime'] === 'image/png' ? 'image/png' : 'image/jpeg', 'data' => base64_encode((string) file_get_contents($m['path']))];
    }

    /** Register → upload to Pinterest's storage → wait until processed. */
    private function uploadVideo(array $m, string $token): string
    {
        $reg = $this->api('POST', 'media', $token, ['json' => ['media_type' => 'video']]);
        $fields = (array) ($reg['upload_parameters'] ?? []);
        $fields['file'] = new \CURLFile($m['path'], $m['mime']);
        $up = $this->http('POST', (string) $reg['upload_url'], ['multipart' => $fields, 'timeout' => 900]);
        if ($up['status'] < 200 || $up['status'] >= 300) {
            throw new ProviderException('Pinterest: the video upload failed (HTTP ' . $up['status'] . ').', $up['status'] >= 500);
        }
        for ($i = 0; $i < 60; $i++) {
            $s = $this->api('GET', 'media/' . rawurlencode((string) $reg['media_id']), $token);
            if (($s['status'] ?? '') === 'succeeded') {
                return (string) $reg['media_id'];
            }
            if (($s['status'] ?? '') === 'failed') {
                throw new ProviderException(__('Pinterest could not process the video.'));
            }
            sleep(3);
        }
        throw new ProviderException(__('Pinterest is still processing the video. It will be retried.'), true);
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $token = (string) $credentials['access_token'];
        $type = $this->type($context);
        $board = (string) $this->opt($context, 'board', '');
        if ($board === '') {
            throw new ProviderException(__('Choose a Pinterest board for this pin.'));
        }
        $pin = ['board_id' => $board, 'description' => $text];
        if ($title = $this->opt($context, 'title')) {
            $pin['title'] = mb_substr((string) $title, 0, 100);
        }
        if ($link = $this->opt($context, 'link')) {
            $pin['link'] = (string) $link;
        }
        $videos = array_values(array_filter($media, fn ($m) => $m['type'] === 'video'));
        $images = array_values(array_filter($media, fn ($m) => $m['type'] === 'image'));
        if ($type === 'video' || $videos) {
            $source = ['source_type' => 'video_id', 'media_id' => $this->uploadVideo($videos[0], $token), 'cover_image_key_frame_time' => 1];
        } elseif ($type === 'carousel' || count($images) > 1) {
            $source = ['source_type' => 'multiple_image_base64', 'items' => array_map(fn ($m) => self::b64($m), array_slice($images, 0, 5)), 'index' => 0];
        } else {
            $source = ['source_type' => 'image_base64'] + self::b64($images[0]);
            if (!empty($images[0]['alt'])) {
                $pin['alt_text'] = mb_substr((string) $images[0]['alt'], 0, 500);
            }
        }
        $pin['media_source'] = $source;
        $res = $this->api('POST', 'pins', $token, ['json' => $pin, 'timeout' => 120]);
        return new PublishResult((string) $res['id'], 'https://www.pinterest.com/pin/' . rawurlencode((string) $res['id']) . '/');
    }
}

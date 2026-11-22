<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * YouTube Data API v3: uploads a video (or a Short) with a resumable upload streamed from disk.
 * Each upload costs about 1,600 of the Google project's 10,000 daily quota units (about 6 uploads a day by default).
 */
final class YouTubeProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'YouTube', 'color' => '#FF0000', 'letter' => 'Y', 'kind' => 'oauth', 'app' => 'google',
            'description' => 'Upload videos and Shorts to your YouTube channel.',
            'content_types' => ['video' => __('Video'), 'short' => __('Short (vertical, up to 3 minutes)')],
            'content_rules' => ['video' => ['media' => 'video', 'max_media' => 1], 'short' => ['media' => 'video', 'max_media' => 1]],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 5000, 'media_max' => 1, 'types' => ['video'], 'media_required' => true, 'single_video' => true,
            'max_bytes' => ['video' => 4 * 1024 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'title', 'label' => __('Video title'), 'type' => 'text', 'max' => 100, 'help' => __('Leave empty to use the first line of the text.')],
            ['key' => 'privacy', 'label' => __('Visibility'), 'type' => 'select', 'required' => true, 'default' => 'public',
                'choices' => ['public' => __('Public'), 'unlisted' => __('Unlisted'), 'private' => __('Private')]],
            ['key' => 'made_for_kids', 'label' => __('Made for kids'), 'type' => 'checkbox', 'default' => false],
            ['key' => 'tags', 'label' => __('Tags'), 'type' => 'text', 'max' => 400, 'help' => __('Separate tags with commas.')],
            ['key' => 'notify', 'label' => __('Notify subscribers'), 'type' => 'checkbox', 'default' => true],
        ];
    }

    public function validate(string $text, array $media, array $meta = []): array
    {
        $errors = parent::validate($text, $media, $meta);
        if (preg_match('/[<>]/', $text)) {
            $errors[] = __('YouTube descriptions can’t contain < or >.');
        }
        return $errors;
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return GoogleOAuth::authorizeUrl($app, $redirectUri, $state, $codeChallenge, [
            'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly',
        ]);
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        $creds = GoogleOAuth::exchange($app, $query, $redirectUri, $codeVerifier, 'YouTube');
        $res = GoogleOAuth::check($this->http('GET', 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', ['headers' => ['Authorization: Bearer ' . $creds['access_token']]]), 'YouTube');
        $accounts = [];
        foreach ((array) ($res['items'] ?? []) as $ch) {
            $accounts[] = [
                'external_id' => (string) $ch['id'], 'name' => (string) ($ch['snippet']['title'] ?? 'YouTube'),
                'username' => isset($ch['snippet']['customUrl']) ? (string) $ch['snippet']['customUrl'] : null,
                'avatar_url' => $ch['snippet']['thumbnails']['default']['url'] ?? null,
                'credentials' => $creds, 'meta' => [],
            ];
        }
        if (!$accounts) {
            throw new ProviderException(__('This Google account has no YouTube channel. Create one on YouTube first.'));
        }
        return $accounts;
    }

    public function refresh(array $credentials, array $app): ?array
    {
        return GoogleOAuth::refresh($credentials, $app, 'YouTube');
    }

    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        $j = GoogleOAuth::check($this->http('GET', 'https://www.googleapis.com/youtube/v3/videos?part=statistics&id=' . rawurlencode($externalId), ['headers' => ['Authorization: Bearer ' . $credentials['access_token']]]), 'YouTube');
        $s = $j['items'][0]['statistics'] ?? null;
        return $s ? ['views' => (int) ($s['viewCount'] ?? 0), 'likes' => (int) ($s['likeCount'] ?? 0), 'comments' => (int) ($s['commentCount'] ?? 0)] : null;
    }

    /** @return array{0:string,1:string} title (≤100, no < >) and description */
    public static function titleAndDescription(string $text, ?string $title, bool $short): array
    {
        $clean = static fn (string $s) => trim(str_replace(['<', '>'], '', $s));
        $t = $title !== null && trim($title) !== '' ? $title : (string) (preg_split("/\n/", trim($text))[0] ?? '');
        $t = mb_substr($clean($t), 0, 100) ?: 'Video';
        $d = $clean($text);
        if ($short && !preg_match('/#shorts\b/i', $t . ' ' . $d)) {
            $d = trim($d . "\n\n#Shorts");
        }
        return [$t, $d];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $video = $media[0] ?? null;
        if (!$video || $video['type'] !== 'video') {
            throw new ProviderException(__('Attach one video for YouTube.'));
        }
        $short = $this->type($context) === 'short';
        [$title, $desc] = self::titleAndDescription($text, $this->opt($context, 'title'), $short);
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->opt($context, 'tags', '')))));
        $body = [
            'snippet' => ['title' => $title, 'description' => $desc, 'categoryId' => '22'] + ($tags ? ['tags' => array_slice($tags, 0, 30)] : []),
            'status' => ['privacyStatus' => (string) $this->opt($context, 'privacy', 'public'), 'selfDeclaredMadeForKids' => filter_var($this->opt($context, 'made_for_kids', false), FILTER_VALIDATE_BOOL)],
        ];
        $auth = 'Authorization: Bearer ' . $credentials['access_token'];
        $notify = filter_var($this->opt($context, 'notify', true), FILTER_VALIDATE_BOOL) ? 'true' : 'false';
        $init = $this->http('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&notifySubscribers=' . $notify, [
            'json' => $body, 'headers' => [$auth, 'X-Upload-Content-Length: ' . (int) $video['size'], 'X-Upload-Content-Type: ' . $video['mime']],
        ]);
        GoogleOAuth::check($init, 'YouTube');
        $location = (string) ($init['headers']['location'] ?? '');
        if (!str_starts_with($location, 'https://')) {
            throw new ProviderException(__('YouTube didn’t return an upload address. Try again.'), true);
        }
        $up = GoogleOAuth::check($this->http('PUT', $location, ['infile' => $video['path'], 'headers' => [$auth, 'Content-Type: ' . $video['mime']], 'timeout' => 3600]), 'YouTube');
        $id = (string) ($up['id'] ?? '');
        if ($id === '') {
            throw new ProviderException(__('YouTube accepted the upload but returned no video id.'), true);
        }
        return new PublishResult($id, $short ? 'https://youtube.com/shorts/' . $id : 'https://www.youtube.com/watch?v=' . $id);
    }
}

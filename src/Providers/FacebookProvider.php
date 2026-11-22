<?php

declare(strict_types=1);

namespace App\Providers;

final class FacebookProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Facebook Page', 'color' => '#1877F2', 'letter' => 'f', 'kind' => 'oauth', 'app' => 'meta',
            'description' => 'Publish posts, Reels and Stories to Facebook Pages you manage (personal profiles can’t be posted to by any app).',
            'content_types' => ['post' => __('Post'), 'reel' => __('Reel'), 'story' => __('Story')],
            'content_rules' => ['reel' => ['media' => 'video', 'max_media' => 1], 'story' => ['media' => 'required', 'max_media' => 1]],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 63206, 'media_max' => 10, 'types' => ['image', 'video'], 'single_video' => true,
            'max_bytes' => ['image' => 10 * 1024 * 1024, 'video' => 1024 * 1024 * 1024]];
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return MetaGraph::authorizeUrl($app, $redirectUri, $state);
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        $accounts = [];
        foreach (MetaGraph::pages($app, $query, $redirectUri) as $p) {
            $accounts[] = [
                'external_id' => (string) $p['id'], 'name' => (string) $p['name'], 'username' => null,
                'avatar_url' => $p['picture']['data']['url'] ?? null,
                'credentials' => ['page_id' => (string) $p['id'], 'access_token' => (string) $p['access_token']],
                'meta' => [],
            ];
        }
        if (!$accounts) {
            throw new ProviderException('No Facebook Pages were shared with NextaPost. Make sure you manage a Page and tick it during sign-in.');
        }
        return $accounts;
    }

    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        if (!str_contains($externalId, '_')) {
            return null; // Reels and Stories: Facebook reports these elsewhere.
        }
        $m = MetaGraph::call('GET', $externalId, ['fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares', 'access_token' => (string) $credentials['access_token']]);
        return ['likes' => (int) ($m['reactions']['summary']['total_count'] ?? 0), 'comments' => (int) ($m['comments']['summary']['total_count'] ?? 0), 'shares' => (int) ($m['shares']['count'] ?? 0)];
    }

    /** Reels and video Stories: start → Facebook fetches the hosted file → finish. */
    private function uploadVideo(string $page, string $token, string $edge, array $m, array $finish): array
    {
        $start = MetaGraph::call('POST', $page . '/' . $edge, ['upload_phase' => 'start', 'access_token' => $token]);
        $videoId = (string) $start['video_id'];
        $up = $this->http('POST', (string) ($start['upload_url'] ?? 'https://rupload.facebook.com/video-upload/' . MetaGraph::version() . '/' . $videoId), [
            'headers' => ['Authorization: OAuth ' . $token, 'file_url: ' . $m['url']], 'timeout' => 300,
        ]);
        if ($up['status'] < 200 || $up['status'] >= 300 || empty($up['json']['success'])) {
            throw new ProviderException('Facebook: the video upload failed' . (isset($up['json']['debug_info']['message']) ? ': ' . $up['json']['debug_info']['message'] : '.'), $up['status'] >= 500);
        }
        return ['video_id' => $videoId] + MetaGraph::call('POST', $page . '/' . $edge, ['upload_phase' => 'finish', 'video_id' => $videoId, 'access_token' => $token] + $finish);
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $page = (string) $credentials['page_id'];
        $token = (string) $credentials['access_token'];
        $type = $this->type($context);
        if ($type === 'reel') {
            $r = $this->uploadVideo($page, $token, 'video_reels', $media[0], ['video_state' => 'PUBLISHED', 'description' => $text]);
            return new PublishResult($r['video_id'], 'https://www.facebook.com/reel/' . $r['video_id']);
        }
        if ($type === 'story') {
            if ($media[0]['type'] === 'video') {
                $r = $this->uploadVideo($page, $token, 'video_stories', $media[0], []);
            } else {
                $photo = MetaGraph::call('POST', $page . '/photos', ['url' => $media[0]['url'], 'published' => 'false', 'access_token' => $token]);
                $r = MetaGraph::call('POST', $page . '/photo_stories', ['photo_id' => (string) $photo['id'], 'access_token' => $token]);
            }
            $id = (string) ($r['post_id'] ?? $r['video_id'] ?? '');
            return new PublishResult($id, null);
        }
        if ($media && $media[0]['type'] === 'video') {
            $j = MetaGraph::call('POST', $page . '/videos', ['file_url' => $media[0]['url'], 'description' => $text, 'access_token' => $token], ['timeout' => 300]);
            $id = (string) $j['id'];
            return new PublishResult($id, 'https://www.facebook.com/' . $id);
        }
        if (count($media) === 1) {
            $j = MetaGraph::call('POST', $page . '/photos', ['url' => $media[0]['url'], 'message' => $text, 'access_token' => $token]);
            $id = (string) ($j['post_id'] ?? $j['id']);
            return new PublishResult($id, 'https://www.facebook.com/' . $id);
        }
        $params = ['message' => $text, 'access_token' => $token];
        foreach (array_values($media) as $i => $m) {
            $photo = MetaGraph::call('POST', $page . '/photos', ['url' => $m['url'], 'published' => 'false', 'access_token' => $token]);
            $params['attached_media[' . $i . ']'] = (string) json_encode(['media_fbid' => $photo['id']]);
        }
        $j = MetaGraph::call('POST', $page . '/feed', $params);
        $id = (string) $j['id'];
        return new PublishResult($id, 'https://www.facebook.com/' . $id);
    }
}

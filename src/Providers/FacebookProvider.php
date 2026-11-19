<?php

declare(strict_types=1);

namespace App\Providers;

final class FacebookProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Facebook Page', 'color' => '#1877F2', 'letter' => 'f', 'kind' => 'oauth', 'app' => 'meta',
            'description' => 'Publish to Facebook Pages you manage (personal profiles can’t be posted to by any app).'];
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

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $page = (string) $credentials['page_id'];
        $token = (string) $credentials['access_token'];
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

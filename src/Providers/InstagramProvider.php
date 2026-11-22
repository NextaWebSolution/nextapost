<?php

declare(strict_types=1);

namespace App\Providers;


final class InstagramProvider extends InstagramBase
{
    public function meta(): array
    {
        return ['name' => 'Instagram (via Facebook)', 'color' => '#E1306C', 'letter' => 'I', 'kind' => 'oauth', 'app' => 'meta',
            'description' => 'Instagram Business or Creator accounts linked to a Facebook Page. Posts, carousels, Reels and Stories.'] + $this->contentMeta();
    }

    protected function graph(string $method, string $path, array $params): array
    {
        return MetaGraph::call($method, $path, $params);
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        return MetaGraph::authorizeUrl($app, $redirectUri, $state);
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        $accounts = [];
        foreach (MetaGraph::pages($app, $query, $redirectUri) as $p) {
            $ig = $p['instagram_business_account'] ?? null;
            if (!$ig) {
                continue;
            }
            $accounts[] = [
                'external_id' => (string) $ig['id'], 'name' => (string) (($ig['name'] ?? '') ?: $ig['username']),
                'username' => '@' . ($ig['username'] ?? ''), 'avatar_url' => $ig['profile_picture_url'] ?? null,
                'credentials' => ['ig_user_id' => (string) $ig['id'], 'access_token' => (string) $p['access_token']],
                'meta' => ['username' => $ig['username'] ?? null, 'page_id' => (string) $p['id']],
            ];
        }
        if (!$accounts) {
            throw new ProviderException('No Instagram Business/Creator account is linked to the Pages you shared. Link Instagram to a Facebook Page first, or connect with “Instagram” (direct login) instead.');
        }
        return $accounts;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        return $this->publishTo((string) $credentials['ig_user_id'], (string) $credentials['access_token'], $text, $media, $context);
    }
}

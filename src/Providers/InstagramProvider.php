<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ImageTool;

final class InstagramProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Instagram', 'color' => '#E1306C', 'letter' => 'I', 'kind' => 'oauth', 'app' => 'meta',
            'description' => 'Instagram Business or Creator accounts linked to a Facebook Page. Feed posts, carousels and Reels.'];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 2200, 'media_max' => 10, 'types' => ['image', 'video'], 'media_required' => true,
            'max_bytes' => ['image' => 8 * 1024 * 1024, 'video' => 300 * 1024 * 1024]];
    }

    public function validate(string $text, array $media, array $meta = []): array
    {
        $errors = parent::validate($text, $media, $meta);
        if (preg_match_all('/(?<=^|\s)#[\p{L}\p{N}_]+/u', $text) > 30) {
            $errors[] = 'Instagram allows up to 30 hashtags.';
        }
        return $errors;
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
            throw new ProviderException('No Instagram Business/Creator account is linked to the Pages you shared. Link Instagram to a Facebook Page first.');
        }
        return $accounts;
    }

    /** Instagram fetches media by URL, accepts JPEG only for images. */
    private function mediaUrl(array $m): string
    {
        if ($m['type'] === 'image' && $m['mime'] !== 'image/jpeg') {
            return ImageTool::publicJpegCopy($m['path'], $m['url']);
        }
        return $m['url'];
    }

    private function waitUntilReady(string $container, string $token): void
    {
        for ($i = 0; $i < 36; $i++) {
            $s = MetaGraph::call('GET', $container, ['fields' => 'status_code,status', 'access_token' => $token]);
            $code = $s['status_code'] ?? 'FINISHED';
            if ($code === 'FINISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new ProviderException('Instagram could not process the media: ' . ($s['status'] ?? $code));
            }
            sleep(5);
        }
        throw new ProviderException('Instagram is still processing the video. It will be retried.', true);
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $ig = (string) $credentials['ig_user_id'];
        $token = (string) $credentials['access_token'];
        if (count($media) === 1) {
            $m = $media[0];
            $params = ['caption' => $text, 'access_token' => $token];
            if ($m['type'] === 'video') {
                $params += ['media_type' => 'REELS', 'video_url' => $m['url'], 'share_to_feed' => 'true'];
            } else {
                $params['image_url'] = $this->mediaUrl($m);
            }
            $container = (string) MetaGraph::call('POST', $ig . '/media', $params)['id'];
        } else {
            $children = [];
            foreach ($media as $m) {
                $p = ['is_carousel_item' => 'true', 'access_token' => $token];
                if ($m['type'] === 'video') {
                    $p += ['media_type' => 'VIDEO', 'video_url' => $m['url']];
                } else {
                    $p['image_url'] = $this->mediaUrl($m);
                }
                $child = (string) MetaGraph::call('POST', $ig . '/media', $p)['id'];
                if ($m['type'] === 'video') {
                    $this->waitUntilReady($child, $token);
                }
                $children[] = $child;
            }
            $container = (string) MetaGraph::call('POST', $ig . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $text, 'access_token' => $token])['id'];
        }
        $this->waitUntilReady($container, $token);
        $id = (string) MetaGraph::call('POST', $ig . '/media_publish', ['creation_id' => $container, 'access_token' => $token])['id'];
        $url = null;
        try {
            $url = MetaGraph::call('GET', $id, ['fields' => 'permalink', 'access_token' => $token])['permalink'] ?? null;
        } catch (\Throwable) {
        }
        return new PublishResult($id, $url);
    }
}

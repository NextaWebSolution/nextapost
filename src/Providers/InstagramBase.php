<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ImageTool;

/**
 * Instagram publishing shared by both sign-in routes (through a Facebook Page, or Instagram Login).
 * Feed posts and carousels, Reels and Stories, with an optional first comment.
 */
abstract class InstagramBase extends AbstractProvider
{
    /** Graph call for this sign-in route. Throws ProviderException on errors. */
    abstract protected function graph(string $method, string $path, array $params): array;

    protected function contentMeta(): array
    {
        return [
            'content_types' => ['feed' => __('Post'), 'reel' => __('Reel'), 'story' => __('Story')],
            'content_rules' => ['reel' => ['media' => 'video', 'max_media' => 1], 'story' => ['media' => 'required', 'max_media' => 1]],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 2200, 'media_max' => 10, 'types' => ['image', 'video'], 'media_required' => true,
            'max_bytes' => ['image' => 8 * 1024 * 1024, 'video' => 300 * 1024 * 1024]];
    }

    public function options(array $meta = []): array
    {
        return [
            ['key' => 'first_comment', 'label' => __('First comment'), 'type' => 'textarea', 'types' => ['feed', 'reel'], 'max' => 2200, 'help' => __('Posted as a comment right after publishing, e.g. for hashtags.')],
            ['key' => 'share_to_feed', 'label' => __('Also show the Reel in the profile grid'), 'type' => 'checkbox', 'types' => ['reel'], 'default' => true],
        ];
    }

    public function validate(string $text, array $media, array $meta = []): array
    {
        $errors = parent::validate($text, $media, $meta);
        if (preg_match_all('/(?<=^|\s)#[\p{L}\p{N}_]+/u', $text) > 30) {
            $errors[] = __('Instagram allows up to 30 hashtags.');
        }
        return $errors;
    }

    /** Instagram fetches media by URL and accepts JPEG only for images. */
    protected function mediaUrl(array $m): string
    {
        if ($m['type'] === 'image' && $m['mime'] !== 'image/jpeg') {
            return ImageTool::publicJpegCopy($m['path'], $m['url']);
        }
        return $m['url'];
    }

    protected function waitUntilReady(string $container, string $token): void
    {
        for ($i = 0; $i < 36; $i++) {
            $s = $this->graph('GET', $container, ['fields' => 'status_code,status', 'access_token' => $token]);
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

    private function container(string $ig, string $token, array $m, array $extra): string
    {
        $p = $extra + ['access_token' => $token];
        if ($m['type'] === 'video') {
            $p['video_url'] = $m['url'];
        } else {
            $p['image_url'] = $this->mediaUrl($m);
        }
        return (string) $this->graph('POST', $ig . '/media', $p)['id'];
    }

    /**
     * Recent posts of the account (for repurposing): newest first.
     * @return list<array{id:string,kind:string,media:list<array{type:string,url:string}>,caption:string,url:?string,at:?string}>
     */
    public function recentMedia(array $credentials, array $meta = []): array
    {
        $j = $this->graph('GET', (string) $credentials['ig_user_id'] . '/media', [
            'fields' => 'id,media_type,media_product_type,media_url,caption,permalink,timestamp,children{media_type,media_url}',
            'limit' => 25, 'access_token' => (string) $credentials['access_token'],
        ]);
        $out = [];
        foreach ((array) ($j['data'] ?? []) as $m) {
            $items = [];
            foreach ((array) ($m['children']['data'] ?? [$m]) as $c) {
                if (!empty($c['media_url'])) {
                    $items[] = ['type' => ($c['media_type'] ?? '') === 'VIDEO' ? 'video' : 'image', 'url' => (string) $c['media_url']];
                }
            }
            $out[] = [
                'id' => (string) $m['id'], 'kind' => ($m['media_product_type'] ?? '') === 'REELS' ? 'reel' : (($m['media_type'] ?? '') === 'VIDEO' ? 'video' : 'post'),
                'media' => $items, 'caption' => (string) ($m['caption'] ?? ''), 'url' => $m['permalink'] ?? null,
                'at' => isset($m['timestamp']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $m['timestamp'])) : null,
            ];
        }
        return $out;
    }

    /** Engagement of one published post: likes, comments, plus views/shares when Instagram reports them. */
    public function metrics(array $credentials, string $externalId, array $meta = []): ?array
    {
        $token = (string) $credentials['access_token'];
        $m = $this->graph('GET', $externalId, ['fields' => 'like_count,comments_count', 'access_token' => $token]);
        $out = ['likes' => isset($m['like_count']) ? (int) $m['like_count'] : null, 'comments' => isset($m['comments_count']) ? (int) $m['comments_count'] : null];
        try {
            foreach ((array) ($this->graph('GET', $externalId . '/insights', ['metric' => 'views,reach,shares', 'access_token' => $token])['data'] ?? []) as $i) {
                $v = (int) ($i['values'][0]['value'] ?? $i['total_value']['value'] ?? 0);
                match ($i['name'] ?? '') {
                    'views' => $out['views'] = $v,
                    'reach' => $out['impressions'] = $v,
                    'shares' => $out['shares'] = $v,
                    default => null,
                };
            }
        } catch (\Throwable) {
            // Insights need extra permissions on some accounts; likes and comments still count.
        }
        return $out;
    }

    protected function publishTo(string $ig, string $token, string $text, array $media, array $context): PublishResult
    {
        $type = $this->type($context);
        if ($type === 'story') {
            $m = $media[0];
            $container = $this->container($ig, $token, $m, ['media_type' => 'STORIES']);
        } elseif ($type === 'reel' || (count($media) === 1 && $media[0]['type'] === 'video')) {
            $share = $this->opt($context, 'share_to_feed', true);
            $container = $this->container($ig, $token, $media[0], ['media_type' => 'REELS', 'caption' => $text, 'share_to_feed' => filter_var($share, FILTER_VALIDATE_BOOL) ? 'true' : 'false']);
        } elseif (count($media) === 1) {
            $container = $this->container($ig, $token, $media[0], ['caption' => $text]);
        } else {
            $children = [];
            foreach ($media as $m) {
                $child = $this->container($ig, $token, $m, $m['type'] === 'video' ? ['media_type' => 'VIDEO', 'is_carousel_item' => 'true'] : ['is_carousel_item' => 'true']);
                if ($m['type'] === 'video') {
                    $this->waitUntilReady($child, $token);
                }
                $children[] = $child;
            }
            $container = (string) $this->graph('POST', $ig . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $text, 'access_token' => $token])['id'];
        }
        $this->waitUntilReady($container, $token);
        $id = (string) $this->graph('POST', $ig . '/media_publish', ['creation_id' => $container, 'access_token' => $token])['id'];
        if ($type !== 'story' && ($comment = trim((string) $this->opt($context, 'first_comment', ''))) !== '') {
            try {
                $this->graph('POST', $id . '/comments', ['message' => $comment, 'access_token' => $token]);
            } catch (\Throwable) {
                // The post is live; a missing first comment shouldn't mark it as failed.
            }
        }
        $url = null;
        try {
            $url = $this->graph('GET', $id, ['fields' => 'permalink', 'access_token' => $token])['permalink'] ?? null;
        } catch (\Throwable) {
        }
        return new PublishResult($id, $url);
    }
}

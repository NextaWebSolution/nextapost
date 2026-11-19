<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;
use App\Services\ImageTool;

final class BlueskyProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Bluesky', 'color' => '#1185FE', 'letter' => 'B', 'kind' => 'token',
            'description' => 'Post to Bluesky with an app password.'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'handle', 'label' => 'Handle or email', 'type' => 'text', 'placeholder' => 'yourname.bsky.social', 'help' => ''],
            ['name' => 'app_password', 'label' => 'App password', 'type' => 'password', 'placeholder' => 'xxxx-xxxx-xxxx-xxxx', 'help' => 'Settings → Privacy and security → App passwords → Add. Don’t use your main password.'],
            ['name' => 'service', 'label' => 'PDS server (optional)', 'type' => 'url', 'placeholder' => 'https://bsky.social', 'help' => 'Leave empty unless you self-host.'],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 300, 'media_max' => 4, 'types' => ['image'], 'max_bytes' => ['image' => 20 * 1024 * 1024]];
    }

    private function session(array $c): array
    {
        $svc = rtrim((string) ($c['service'] ?? '') ?: 'https://bsky.social', '/');
        if (!str_starts_with($svc, 'https://')) {
            throw new ProviderException('Bluesky: the PDS server must use https://');
        }
        $j = $this->ok($this->http('POST', $svc . '/xrpc/com.atproto.server.createSession', ['json' => ['identifier' => $c['handle'], 'password' => $c['app_password']], 'public_only' => true]), null);
        return [$svc, $j];
    }

    public function connect(array $input): array
    {
        $this->require($input, 'handle', 'app_password');
        $c = ['handle' => ltrim(trim((string) $input['handle']), '@'), 'app_password' => trim((string) $input['app_password']), 'service' => trim((string) ($input['service'] ?? ''))];
        if ($c['service'] !== '') {
            Http::assertPublicUrl($c['service']);
        }
        [$svc, $s] = $this->session($c);
        $profile = [];
        try {
            $p = $this->http('GET', $svc . '/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode((string) $s['did']), ['headers' => ['Authorization: Bearer ' . $s['accessJwt']], 'public_only' => true]);
            $profile = is_array($p['json']) ? $p['json'] : [];
        } catch (\Throwable) {
        }
        return [
            'external_id' => (string) $s['did'],
            'name' => (string) (($profile['displayName'] ?? '') ?: $s['handle']),
            'username' => '@' . $s['handle'],
            'avatar_url' => $profile['avatar'] ?? null,
            'credentials' => $c,
            'meta' => ['handle' => $s['handle']],
        ];
    }

    /** Link + hashtag facets. Bluesky uses UTF-8 byte offsets, which is what preg offsets are. */
    public static function facets(string $text): array
    {
        $facets = [];
        if (preg_match_all('#https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]#u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $start]) {
                $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
            }
        }
        if (preg_match_all('/(?<=^|\s)#([\p{L}\p{N}_]{1,64})/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$tag, $start]) {
                $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($tag)], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $m[1][$i][0]]]];
            }
        }
        return $facets;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        [$svc, $s] = $this->session($credentials);
        $auth = 'Authorization: Bearer ' . $s['accessJwt'];
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')];
        $facets = self::facets($text);
        if ($facets) {
            $record['facets'] = $facets;
        }
        if ($media) {
            $images = [];
            foreach ($media as $m) {
                // Bluesky accepts images up to ~1 MB; shrink larger files automatically.
                $file = ImageTool::shrinkToBytes($m['path'], 950_000);
                $blob = $this->ok($this->http('POST', $svc . '/xrpc/com.atproto.repo.uploadBlob', [
                    'headers' => [$auth, 'Content-Type: ' . ($file === $m['path'] ? $m['mime'] : 'image/jpeg')],
                    'body' => (string) file_get_contents($file), 'public_only' => true,
                ]));
                if ($file !== $m['path']) {
                    @unlink($file);
                }
                $img = ['alt' => (string) ($m['alt'] ?? ''), 'image' => $blob['blob']];
                if (!empty($m['width']) && !empty($m['height'])) {
                    $img['aspectRatio'] = ['width' => (int) $m['width'], 'height' => (int) $m['height']];
                }
                $images[] = $img;
            }
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
        }
        $j = $this->ok($this->http('POST', $svc . '/xrpc/com.atproto.repo.createRecord', [
            'headers' => [$auth],
            'json' => ['repo' => $s['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record], 'public_only' => true,
        ]));
        $uri = (string) ($j['uri'] ?? '');
        $rkey = substr($uri, (int) strrpos($uri, '/') + 1);
        return new PublishResult($uri, 'https://bsky.app/profile/' . $s['handle'] . '/post/' . $rkey);
    }
}

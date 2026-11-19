<?php

declare(strict_types=1);

namespace App\Providers;

final class DiscordProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Discord', 'color' => '#5865F2', 'letter' => 'D', 'kind' => 'token',
            'description' => 'Post to a Discord channel with a webhook.'];
    }

    public function fields(): array
    {
        return [['name' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'password', 'placeholder' => 'https://discord.com/api/webhooks/…',
            'help' => 'Channel settings → Integrations → Webhooks → New Webhook → Copy Webhook URL.']];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 2000, 'media_max' => 10, 'types' => ['image', 'video', 'document'],
            'max_bytes' => ['image' => 10 * 1024 * 1024, 'video' => 10 * 1024 * 1024, 'document' => 10 * 1024 * 1024]];
    }

    private function checkUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https://(?:ptb\.|canary\.)?(?:discord|discordapp)\.com/api/webhooks/(\d+)/[\w-]+$#', $url, $m)) {
            throw new ProviderException('Paste a Discord webhook URL (it starts with https://discord.com/api/webhooks/).');
        }
        return $m[1];
    }

    public function connect(array $input): array
    {
        $this->require($input, 'webhook_url');
        $url = trim((string) $input['webhook_url']);
        $id = $this->checkUrl($url);
        $j = $this->ok($this->http('GET', $url), 'Discord didn’t accept that webhook. Check that it still exists.');
        return [
            'external_id' => $id,
            'name' => (string) ($j['name'] ?? 'Discord webhook'),
            'username' => isset($j['channel_id']) ? '#' . $j['channel_id'] : null,
            'avatar_url' => !empty($j['avatar']) ? 'https://cdn.discordapp.com/avatars/' . $id . '/' . $j['avatar'] . '.png' : null,
            'credentials' => ['webhook_url' => $url],
            'meta' => ['guild_id' => $j['guild_id'] ?? null, 'channel_id' => $j['channel_id'] ?? null],
        ];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $url = (string) $credentials['webhook_url'];
        $this->checkUrl($url);
        $payload = ['content' => $text, 'allowed_mentions' => ['parse' => []]];
        if ($media) {
            $parts = ['payload_json' => (string) json_encode($payload)];
            foreach (array_values($media) as $i => $m) {
                $parts['files[' . $i . ']'] = new \CURLFile($m['path'], $m['mime'], $m['type'] === 'document' ? TelegramProvider::fileName($m) : basename($m['path']));
            }
            $resp = $this->http('POST', $url . '?wait=true', ['multipart' => $parts]);
        } else {
            $resp = $this->http('POST', $url . '?wait=true', ['json' => $payload]);
        }
        $j = $this->ok($resp);
        $id = (string) ($j['id'] ?? '');
        $link = (!empty($meta['guild_id']) && !empty($j['channel_id'])) ? "https://discord.com/channels/{$meta['guild_id']}/{$j['channel_id']}/$id" : null;
        return new PublishResult($id, $link);
    }
}

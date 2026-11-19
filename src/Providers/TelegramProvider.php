<?php

declare(strict_types=1);

namespace App\Providers;

final class TelegramProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Telegram', 'color' => '#229ED9', 'letter' => 'T', 'kind' => 'token',
            'description' => 'Post to a Telegram channel or group through your own bot.'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'bot_token', 'label' => 'Bot token', 'type' => 'password', 'placeholder' => '123456789:AA…', 'help' => 'Create a bot with @BotFather and copy its token.'],
            ['name' => 'chat_id', 'label' => 'Channel or group', 'type' => 'text', 'placeholder' => '@yourchannel or -1001234567890', 'help' => 'Add the bot to your channel as an admin with “Post messages” permission.'],
        ];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 4096, 'caption_max' => 1024, 'media_max' => 10, 'types' => ['image', 'video', 'document'], 'document_alone' => true,
            'max_bytes' => ['image' => 10 * 1024 * 1024, 'video' => 50 * 1024 * 1024, 'document' => 50 * 1024 * 1024]];
    }

    private function api(string $token, string $method, array $opts): array
    {
        if (!preg_match('/^\d+:[\w-]+$/', $token)) {
            throw new ProviderException('That bot token doesn’t look right. Copy it again from @BotFather.');
        }
        $resp = $this->http('POST', 'https://api.telegram.org/bot' . $token . '/' . $method, $opts);
        $j = is_array($resp['json']) ? $resp['json'] : [];
        if (empty($j['ok'])) {
            $desc = (string) ($j['description'] ?? ('HTTP ' . $resp['status']));
            $retry = $resp['status'] === 429 || $resp['status'] >= 500 || $resp['status'] === 0;
            throw new ProviderException('Telegram: ' . $desc, $retry, $resp['status'] === 401);
        }
        return $j['result'] ?? [];
    }

    public function connect(array $input): array
    {
        $this->require($input, 'bot_token', 'chat_id');
        $token = trim((string) $input['bot_token']);
        $chatId = trim((string) $input['chat_id']);
        $bot = $this->api($token, 'getMe', ['json' => (object) []]);
        $chat = $this->api($token, 'getChat', ['json' => ['chat_id' => $chatId]]);
        $username = $chat['username'] ?? null;
        return [
            'external_id' => (string) $chat['id'],
            'name' => (string) ($chat['title'] ?? $username ?? $chatId),
            'username' => $username ? '@' . $username : null,
            'avatar_url' => null,
            'credentials' => ['bot_token' => $token, 'chat_id' => (string) $chat['id']],
            'meta' => ['bot' => $bot['username'] ?? null, 'chat_username' => $username],
        ];
    }

    /** Readable, safe file name for recipients (original name, sanitised). */
    public static function fileName(array $m): string
    {
        $ext = (string) pathinfo((string) $m['path'], PATHINFO_EXTENSION);
        $base = (string) preg_replace('/[^\w .()-]+/u', '_', (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', (string) ($m['name'] ?? 'document')));
        return mb_substr(trim($base) !== '' ? trim($base) : 'document', 0, 120) . '.' . $ext;
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        $token = (string) $credentials['bot_token'];
        $chat = (string) $credentials['chat_id'];
        if (!$media) {
            $msg = $this->api($token, 'sendMessage', ['json' => ['chat_id' => $chat, 'text' => $text]]);
        } elseif (count($media) === 1 && $media[0]['type'] === 'document') {
            $m = $media[0];
            $msg = $this->api($token, 'sendDocument', ['multipart' => [
                'chat_id' => $chat, 'caption' => $text,
                'document' => new \CURLFile($m['path'], $m['mime'], self::fileName($m)),
            ], 'timeout' => 300]);
        } elseif (count($media) === 1) {
            $m = $media[0];
            $field = $m['type'] === 'video' ? 'video' : 'photo';
            $msg = $this->api($token, $m['type'] === 'video' ? 'sendVideo' : 'sendPhoto', ['multipart' => [
                'chat_id' => $chat, 'caption' => $text, $field => new \CURLFile($m['path'], $m['mime'], basename($m['path'])),
            ]]);
        } else {
            $group = [];
            $parts = ['chat_id' => $chat];
            foreach (array_values($media) as $i => $m) {
                $item = ['type' => $m['type'] === 'video' ? 'video' : 'photo', 'media' => 'attach://file' . $i];
                if ($i === 0 && $text !== '') {
                    $item['caption'] = $text;
                }
                $group[] = $item;
                $parts['file' . $i] = new \CURLFile($m['path'], $m['mime'], basename($m['path']));
            }
            $parts['media'] = (string) json_encode($group);
            $msgs = $this->api($token, 'sendMediaGroup', ['multipart' => $parts]);
            $msg = $msgs[0] ?? [];
        }
        $id = (string) ($msg['message_id'] ?? '');
        $user = $meta['chat_username'] ?? null;
        return new PublishResult($id, $user ? 'https://t.me/' . $user . '/' . $id : null);
    }
}

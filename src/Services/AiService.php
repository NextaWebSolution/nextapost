<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Env;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Providers\ProviderFactory;

/**
 * AI writing assistant. Each workspace brings its own API key (OpenAI, Anthropic, Gemini or any
 * OpenAI-compatible endpoint such as OpenRouter or a self-hosted model). Keys are stored encrypted.
 */
final class AiService
{
    public const PROVIDERS = [
        'openai' => ['name' => 'OpenAI', 'model' => 'gpt-5-mini', 'key_hint' => 'sk-…'],
        'anthropic' => ['name' => 'Anthropic', 'model' => 'claude-haiku-4-5', 'key_hint' => 'sk-ant-…'],
        'gemini' => ['name' => 'Google Gemini', 'model' => 'gemini-2.5-flash', 'key_hint' => 'AIza…'],
        'openai_compatible' => ['name' => 'OpenAI-compatible (OpenRouter, Groq, self-hosted…)', 'model' => '', 'key_hint' => ''],
    ];

    /** Rewrite actions offered in the composer. Each maps to an instruction for the model. */
    public const ACTIONS = [
        'write' => 'Write a post',
        'rephrase' => 'Rephrase',
        'shorten' => 'Make shorter',
        'expand' => 'Make longer',
        'casual' => 'More casual',
        'formal' => 'More professional',
        'fix' => 'Fix spelling and grammar',
        'hashtags' => 'Add hashtags',
        'emoji' => 'Add emoji',
        'translate' => 'Translate',
        'fit' => 'Fit the character limit',
    ];

    public const MAX_INPUT = 8000;

    public function __construct(private DB $db)
    {
    }

    public static function configured(array $ws): bool
    {
        $p = (string) ($ws['ai_provider'] ?? '');
        return isset(self::PROVIDERS[$p]) && (!empty($ws['ai_key_enc']) || ($p === 'openai_compatible' && !empty($ws['ai_base_url'])));
    }

    /** Settings for the form (never the key itself). */
    public static function settingsFor(array $ws): array
    {
        $key = '';
        if (!empty($ws['ai_key_enc'])) {
            try {
                $plain = Crypto::decrypt((string) $ws['ai_key_enc']);
                $key = strlen($plain) > 8 ? substr($plain, 0, 3) . '…' . substr($plain, -4) : '••••';
            } catch (\Throwable) {
                $key = '••••';
            }
        }
        return [
            'provider' => (string) ($ws['ai_provider'] ?? ''), 'model' => (string) ($ws['ai_model'] ?? ''),
            'base_url' => (string) ($ws['ai_base_url'] ?? ''), 'brand_voice' => (string) ($ws['brand_voice'] ?? ''),
            'key_saved' => $key !== '', 'key_preview' => $key,
        ];
    }

    /** @return array<string,string> errors */
    public function saveSettings(array $ws, array $in): array
    {
        $errors = [];
        $provider = str_val($in['provider'] ?? '');
        if ($provider !== '' && !isset(self::PROVIDERS[$provider])) {
            return ['provider' => __('Choose an AI provider.')];
        }
        $model = trim(str_val($in['model'] ?? ''));
        if ($provider !== '' && $model === '') {
            $model = self::PROVIDERS[$provider]['model'];
        }
        if ($provider !== '' && ($model === '' || mb_strlen($model) > 120 || !preg_match('/^[\w.\-:\/@]+$/u', $model))) {
            $errors['model'] = __('Enter the model name, for example {m}.', ['m' => self::PROVIDERS[$provider]['model'] ?: 'llama-3.3-70b']);
        }
        $base = rtrim(trim(str_val($in['base_url'] ?? '')), '/');
        if ($provider === 'openai_compatible') {
            if (!preg_match('#^https://#i', $base) && !(Env::get('AI_ALLOW_PRIVATE_BASE_URL') && preg_match('#^https?://#i', $base))) {
                $errors['base_url'] = __('Enter the API base URL, starting with https:// (for example https://openrouter.ai/api/v1).');
            } elseif (mb_strlen($base) > 300) {
                $errors['base_url'] = __('That URL is too long.');
            }
        } else {
            $base = '';
        }
        $voice = trim(str_val($in['brand_voice'] ?? ''));
        if (mb_strlen($voice) > 2000) {
            $errors['brand_voice'] = __('Keep the brand voice under 2,000 characters.');
        }
        $key = trim(str_val($in['api_key'] ?? ''));
        if ($key !== '' && (strlen($key) > 500 || preg_match('/\s/', $key))) {
            $errors['api_key'] = __('That doesn’t look like an API key.');
        }
        if ($provider !== '' && $key === '' && empty($ws['ai_key_enc']) && $provider !== 'openai_compatible') {
            $errors['api_key'] = __('Paste your API key.');
        }
        if ($errors) {
            return $errors;
        }
        $data = ['ai_provider' => $provider ?: null, 'ai_model' => $provider ? $model : null, 'ai_base_url' => $base ?: null, 'brand_voice' => $voice ?: null, 'updated_at' => now_utc()];
        if ($provider === '') {
            $data['ai_key_enc'] = null;
        } elseif ($key !== '') {
            $data['ai_key_enc'] = Crypto::encrypt($key);
        } elseif (($ws['ai_provider'] ?? '') !== $provider && $provider !== 'openai_compatible') {
            return ['api_key' => __('Paste the API key for the new provider.')];
        } elseif ($provider === 'openai_compatible' && !empty($ws['ai_key_enc'])
            && (($ws['ai_provider'] ?? '') !== $provider || strtolower((string) parse_url((string) ($ws['ai_base_url'] ?? ''), PHP_URL_HOST)) !== strtolower((string) parse_url($base, PHP_URL_HOST)))) {
            // A key saved for another provider or server is never sent to a new one. Keyless local servers keep working.
            $data['ai_key_enc'] = null;
        }
        $this->db->update('workspaces', $data, 'id = ?', [$ws['id']]);
        log_activity((int) $ws['id'], 'ai.settings', $provider ? __('AI assistant set to {p}', ['p' => self::PROVIDERS[$provider]['name']]) : __('AI assistant turned off'));
        return [];
    }

    // ---------------------------------------------------------------- prompts

    private static function system(array $ws): string
    {
        $s = "You help a social media team write posts. Reply with the post text only: no preface, no explanation, no quotation marks around it, no markdown headings. "
            . "Keep the author's facts, links, @mentions and numbers exactly as given. Never invent prices, dates, statistics or contact details. "
            . "Write in the same language as the input unless told otherwise.";
        $voice = trim((string) ($ws['brand_voice'] ?? ''));
        if ($voice !== '') {
            $s .= "\n\nBrand voice and rules from the team (follow them):\n" . $voice;
        }
        return $s;
    }

    /** @return array{0:string,1:string} [system, user prompt] */
    public static function prompt(array $ws, string $action, string $text, array $opt = []): array
    {
        $network = (string) ($opt['network'] ?? '');
        $limit = (int) ($opt['limit'] ?? 0);
        $target = $network !== '' ? "\nIt will be posted on {$network}" . ($limit ? " (maximum {$limit} characters)." : '.') : ($limit ? "\nMaximum {$limit} characters." : '');
        $extra = trim((string) ($opt['instruction'] ?? ''));
        $task = match ($action) {
            'write' => "Write one ready-to-publish social media post about the following idea." . $target . ($extra !== '' ? "\nAlso: {$extra}" : '') . "\n\nIdea:\n{$text}",
            'rephrase' => "Rewrite this post with different wording but the same meaning and length." . $target . "\n\nPost:\n{$text}",
            'shorten' => "Make this post noticeably shorter while keeping the key message and any call to action." . $target . "\n\nPost:\n{$text}",
            'expand' => "Make this post a little longer and more engaging, adding useful detail without inventing facts." . $target . "\n\nPost:\n{$text}",
            'casual' => "Rewrite this post in a friendlier, more casual tone." . $target . "\n\nPost:\n{$text}",
            'formal' => "Rewrite this post in a polished, professional tone." . $target . "\n\nPost:\n{$text}",
            'fix' => "Correct spelling, grammar and punctuation only. Do not change the wording otherwise.\n\nPost:\n{$text}",
            'hashtags' => "Return the post unchanged, followed by a blank line and 3 to 6 relevant hashtags." . $target . "\n\nPost:\n{$text}",
            'emoji' => "Add a few fitting emoji to this post without changing the words." . $target . "\n\nPost:\n{$text}",
            'translate' => "Translate this post into " . ($opt['language'] ?? 'English') . ". Keep hashtags, @mentions and links unchanged." . $target . "\n\nPost:\n{$text}",
            'fit' => "Shorten this post so it is at most {$limit} characters including spaces, keeping the main message." . "\n\nPost:\n{$text}",
            default => $text,
        };
        return [self::system($ws), $task];
    }

    // ---------------------------------------------------------------- calls

    /**
     * Run an action. @return array{0:?string,1:?string} [text, error]
     */
    public function assist(array $ws, int $userId, string $action, string $text, array $opt = []): array
    {
        if (!self::configured($ws)) {
            return [null, __('The AI assistant isn’t set up for this workspace yet. An admin can add an API key in Workspace settings.')];
        }
        if (!isset(self::ACTIONS[$action])) {
            return [null, __('Unknown AI action.')];
        }
        $text = trim($text);
        if ($text === '') {
            return [null, $action === 'write' ? __('Describe what the post should be about.') : __('Write some text first.')];
        }
        if (mb_strlen($text) > self::MAX_INPUT) {
            return [null, __('The text is too long for the assistant ({n} characters max).', ['n' => number_format(self::MAX_INPUT)])];
        }
        if ($action === 'fit' && (int) ($opt['limit'] ?? 0) < 10) {
            return [null, __('This channel has no character limit to fit.')];
        }
        if (!RateLimiter::hit($this->db, 'ai:' . $userId, max(5, (int) Env::get('AI_RATE_LIMIT', 60)), 3600)) {
            return [null, __('You’ve used the assistant a lot in the last hour. Try again later.')];
        }
        [$system, $user] = self::prompt($ws, $action, $text, $opt);
        [$out, $err] = $this->complete($ws, $system, $user);
        if ($err) {
            return [null, $err];
        }
        return [self::clean((string) $out), null];
    }

    /** Alt text for an image file (vision). @return array{0:?string,1:?string} */
    public function altText(array $ws, int $userId, string $absPath, string $mime): array
    {
        if (!self::configured($ws)) {
            return [null, __('The AI assistant isn’t set up for this workspace yet. An admin can add an API key in Workspace settings.')];
        }
        if (!is_file($absPath) || filesize($absPath) > 8 * 1024 * 1024 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return [null, __('Alt text can be written for JPEG, PNG, WebP or GIF images up to 8 MB.')];
        }
        if (!RateLimiter::hit($this->db, 'ai:' . $userId, max(5, (int) Env::get('AI_RATE_LIMIT', 60)), 3600)) {
            return [null, __('You’ve used the assistant a lot in the last hour. Try again later.')];
        }
        $prompt = 'Write alt text for this image for people using screen readers: one or two plain sentences, under 250 characters, describing what matters. No "Image of". Reply with the alt text only.';
        [$out, $err] = $this->complete($ws, self::system($ws), $prompt, ['mime' => $mime, 'data' => base64_encode((string) file_get_contents($absPath))]);
        return $err ? [null, $err] : [mb_substr(self::clean((string) $out), 0, 1000), null];
    }

    /** Strip wrappers models sometimes add. */
    public static function clean(string $s): string
    {
        $s = trim($s);
        $s = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $s);
        if (preg_match('/^(["“])(.*)(["”])$/su', $s, $m) && !str_contains($m[2], '"')) {
            $s = $m[2];
        }
        return trim($s);
    }

    /**
     * Call the workspace's provider. @param array{mime:string,data:string}|null $image
     * @return array{0:?string,1:?string} [text, error]
     */
    public function complete(array $ws, string $system, string $user, ?array $image = null, int $maxTokens = 1500): array
    {
        $provider = (string) $ws['ai_provider'];
        $model = (string) ($ws['ai_model'] ?: self::PROVIDERS[$provider]['model']);
        $key = '';
        if (!empty($ws['ai_key_enc'])) {
            try {
                $key = Crypto::decrypt((string) $ws['ai_key_enc']);
            } catch (\Throwable) {
                return [null, __('The saved AI key can’t be read (APP_KEY changed?). Save the key again in Workspace settings.')];
            }
        }
        try {
            switch ($provider) {
                case 'anthropic':
                    $content = $image
                        ? [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data']]], ['type' => 'text', 'text' => $user]]
                        : $user;
                    $res = Http::request('POST', 'https://api.anthropic.com/v1/messages', [
                        'headers' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], 'timeout' => 90,
                        'json' => ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]],
                    ]);
                    $text = '';
                    foreach ((array) ($res['json']['content'] ?? []) as $part) {
                        $text .= ($part['type'] ?? '') === 'text' ? (string) $part['text'] : '';
                    }
                    break;
                case 'gemini':
                    $parts = [['text' => $user]];
                    if ($image) {
                        array_unshift($parts, ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]]);
                    }
                    $res = Http::request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
                        'headers' => ['x-goog-api-key: ' . $key], 'timeout' => 90,
                        'json' => ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => [['role' => 'user', 'parts' => $parts]], 'generationConfig' => ['maxOutputTokens' => $maxTokens]],
                    ]);
                    $text = '';
                    foreach ((array) ($res['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
                        $text .= (string) ($part['text'] ?? '');
                    }
                    break;
                default: // openai, openai_compatible
                    $base = $provider === 'openai' ? 'https://api.openai.com/v1' : rtrim((string) $ws['ai_base_url'], '/');
                    $userContent = $image ? [['type' => 'text', 'text' => $user], ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . $image['data']]]] : $user;
                    $body = ['model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userContent]]];
                    // OpenAI's current models take max_completion_tokens (they spend some on reasoning); others take max_tokens.
                    $body[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $provider === 'openai' ? $maxTokens * 3 : $maxTokens;
                    $opts = ['json' => $body, 'timeout' => 90, 'headers' => $key !== '' ? ['Authorization: Bearer ' . $key] : []];
                    if ($provider === 'openai_compatible' && !Env::get('AI_ALLOW_PRIVATE_BASE_URL')) {
                        $opts['public_only'] = true;
                    }
                    $res = Http::request('POST', $base . '/chat/completions', $opts);
                    $text = (string) ($res['json']['choices'][0]['message']['content'] ?? '');
            }
        } catch (\Throwable $e) {
            return [null, __('Couldn’t reach the AI provider: {reason}', ['reason' => mb_substr($e->getMessage(), 0, 200)])];
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return [null, __('The AI provider rejected the API key. Check it in Workspace settings.')];
        }
        if ($res['status'] === 429) {
            return [null, __('The AI provider says you’ve hit your rate limit or run out of credit.')];
        }
        if ($res['status'] === 404) {
            return [null, __('The AI provider doesn’t know the model “{m}”. Check the model name in Workspace settings.', ['m' => $model])];
        }
        if ($res['status'] >= 400 || trim($text) === '') {
            $msg = $res['json']['error']['message'] ?? $res['json']['error']['type'] ?? ($res['json'][0]['error']['message'] ?? null);
            return [null, __('The AI provider returned an error{detail}.', ['detail' => $msg ? ': ' . mb_substr((string) $msg, 0, 200) : ' (HTTP ' . $res['status'] . ')'])];
        }
        return [$text, null];
    }

    /** Character limit and display name for a channel, so rewrites fit. */
    public function channelHint(int $wsId, string $channelUuid): array
    {
        $c = $this->db->one('SELECT network, name, meta_json FROM channels WHERE workspace_id = ? AND uuid = ?', [$wsId, $channelUuid]);
        if (!$c) {
            return [];
        }
        $p = ProviderFactory::make((string) $c['network']);
        $limits = $p->limits(json_decode((string) ($c['meta_json'] ?? ''), true) ?: []);
        return ['network' => (string) ($p->meta()['name'] ?? $c['network']), 'limit' => (int) ($limits['text_max'] ?? 0)];
    }
}

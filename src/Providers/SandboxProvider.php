<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * A test channel that never leaves your server. Posts are written to storage/logs/sandbox.log.
 * Include "#fail" in the text to simulate a permanent failure, "#retry" for a temporary one.
 */
final class SandboxProvider extends AbstractProvider
{
    public function meta(): array
    {
        return ['name' => 'Sandbox', 'color' => '#71717A', 'letter' => 'S', 'kind' => 'token',
            'description' => 'A private test channel. Posts are written to a log file on your server — perfect for trying the workflow.'];
    }

    public function fields(): array
    {
        return [['name' => 'label', 'label' => 'Channel name', 'type' => 'text', 'placeholder' => 'e.g. Test channel', 'help' => 'Any name you like.']];
    }

    public function connect(array $input): array
    {
        $this->require($input, 'label');
        $label = mb_substr(trim(str_val($input['label'])), 0, 80);
        return ['external_id' => 'sandbox-' . bin2hex(random_bytes(6)), 'name' => $label, 'username' => 'sandbox', 'avatar_url' => null, 'credentials' => ['label' => $label], 'meta' => []];
    }

    public function limits(array $meta = []): array
    {
        return ['text_max' => 5000, 'media_max' => 10, 'types' => ['image', 'video', 'document']];
    }

    public function publish(array $credentials, string $text, array $media, array $meta = [], array $context = []): PublishResult
    {
        if (str_contains($text, '#fail')) {
            throw new ProviderException('Sandbox: simulated failure (#fail in text).');
        }
        if (str_contains($text, '#retry')) {
            throw new ProviderException('Sandbox: simulated temporary outage (#retry in text).', true);
        }
        $id = 'sbx_' . bin2hex(random_bytes(8));
        $line = sprintf("[%s] %s → %s | %d media | %s\n", gmdate('c'), $credentials['label'] ?? 'sandbox', $id, count($media), str_replace("\n", ' ⏎ ', $text));
        @file_put_contents(APP_ROOT . '/storage/logs/sandbox.log', $line, FILE_APPEND | LOCK_EX);
        return new PublishResult($id, null);
    }
}

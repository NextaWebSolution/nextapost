<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Http;

abstract class AbstractProvider implements ProviderInterface
{
    public function fields(): array
    {
        return [];
    }

    public function connect(array $input): array
    {
        throw new ProviderException('This network connects with a sign-in button, not a form.');
    }

    public function authorizeUrl(array $app, string $redirectUri, string $state, string $codeChallenge): string
    {
        throw new ProviderException('This network does not use OAuth.');
    }

    public function handleCallback(array $app, array $query, string $redirectUri, string $codeVerifier): array
    {
        throw new ProviderException('This network does not use OAuth.');
    }

    public function refresh(array $credentials, array $app): ?array
    {
        return null;
    }

    public function validate(string $text, array $media, array $meta = []): array
    {
        $l = $this->limits($meta);
        $name = $this->meta()['name'];
        $errors = [];
        $len = $this->countText($text);
        $max = ($media && isset($l['caption_max'])) ? $l['caption_max'] : $l['text_max'];
        if ($len > $max) {
            $errors[] = "$name allows up to " . number_format($max) . " characters — this text has " . number_format($len) . '.';
        }
        if (trim($text) === '' && !$media) {
            $errors[] = "Add some text or media for $name.";
        }
        if (!empty($l['media_required']) && !$media) {
            $errors[] = "$name needs at least one image or video.";
        }
        if (count($media) > $l['media_max']) {
            $errors[] = $l['media_max'] === 0
                ? "$name posts can’t include media yet — remove the attachments or customise this channel."
                : "$name allows up to {$l['media_max']} media files per post.";
        }
        $videos = 0;
        $docs = count(array_filter($media, static fn ($m) => $m['type'] === 'document'));
        if ($docs > 0 && !in_array('document', $l['types'], true)) {
            $errors[] = "$name can’t post documents (PDF, PPTX, DOCX) — only LinkedIn, Telegram and Discord can. Open the $name tab and choose “Use different media for this channel” (e.g. image slides), or deselect it.";
            return $errors;
        }
        if ($docs > 0 && !empty($l['document_alone']) && count($media) > 1) {
            $errors[] = "$name posts a document on its own — remove the other files, or give $name its own media.";
            return $errors;
        }
        foreach ($media as $m) {
            if (!in_array($m['type'], $l['types'], true)) {
                $errors[] = $m['type'] === 'video' ? "$name doesn’t support video posts in this version." : "$name doesn’t support this file type.";
                break;
            }
            if ($m['type'] === 'document' && isset($l['max_pages'], $m['pages']) && $m['pages'] > $l['max_pages']) {
                $errors[] = "$name documents can have up to {$l['max_pages']} pages — this one has {$m['pages']}.";
                break;
            }
            $cap = $l['max_bytes'][$m['type']] ?? null;
            if ($cap !== null && (int) $m['size'] > $cap) {
                $errors[] = "A file is too large for $name (max " . human_bytes($cap) . ').';
                break;
            }
            if ($m['type'] === 'video') {
                $videos++;
            }
        }
        if ($videos > 0 && count($media) > 1 && !empty($l['single_video'])) {
            $errors[] = "$name can post one video on its own — remove the other files.";
        }
        return $errors;
    }

    protected function countText(string $text): int
    {
        return text_length($text);
    }

    protected function http(string $method, string $url, array $opts = []): array
    {
        return Http::request($method, $url, $opts);
    }

    /** Throws a ProviderException unless the response is 2xx. */
    protected function ok(array $resp, ?string $message = null): array
    {
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw ProviderException::fromResponse($this->meta()['name'], $resp, $message);
        }
        return is_array($resp['json']) ? $resp['json'] : [];
    }

    protected function require(array $input, string ...$keys): void
    {
        foreach ($keys as $k) {
            if (trim((string) ($input[$k] ?? '')) === '') {
                throw new ProviderException('Please fill in all required fields.');
            }
        }
    }
}

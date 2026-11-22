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

    public function options(array $meta = []): array
    {
        return [];
    }

    /** The content type to use when none was chosen (first declared). */
    public function defaultType(): ?string
    {
        $types = $this->meta()['content_types'] ?? [];
        return $types ? (string) array_key_first($types) : null;
    }

    /**
     * Checks per-network options (poll, first comment, board, privacy...) and the media rules of a content type,
     * driven by meta()['content_rules'] and options(). @return list<string> errors
     */
    public function validateOptions(?string $contentType, array $options, string $text, array $media = [], array $meta = [], bool $strict = true): array
    {
        $name = $this->meta()['name'];
        $type = $contentType ?? $this->defaultType();
        $errors = [];
        $rule = $this->meta()['content_rules'][$type] ?? [];
        $label = $type !== null ? (string) ($this->meta()['content_types'][$type] ?? $type) : '';
        $kinds = array_column($media, 'type');
        if (!$strict) {
            $rule = [];
        }
        if (($rule['media'] ?? null) === 'none' && $media) {
            $errors[] = __('A {name} {type} can’t include media.', ['name' => $name, 'type' => $label]);
        }
        if (in_array($rule['media'] ?? null, ['required', 'video', 'image'], true) && !$media) {
            $errors[] = __('A {name} {type} needs {what}.', ['name' => $name, 'type' => $label, 'what' => ($rule['media'] === 'video' ? __('a video') : ($rule['media'] === 'image' ? __('an image') : __('an image or video')))]);
        }
        if (($rule['media'] ?? null) === 'video' && $media && array_diff($kinds, ['video'])) {
            $errors[] = __('A {name} {type} must be a video.', ['name' => $name, 'type' => $label]);
        }
        if (($rule['media'] ?? null) === 'image' && $media && array_diff($kinds, ['image'])) {
            $errors[] = __('A {name} {type} takes images only.', ['name' => $name, 'type' => $label]);
        }
        if (isset($rule['max_media']) && count($media) > $rule['max_media']) {
            $errors[] = __('A {name} {type} can have up to {n} file(s).', ['name' => $name, 'type' => $label, 'n' => $rule['max_media']]);
        }
        if (isset($rule['min_media']) && $media && count($media) < $rule['min_media']) {
            $errors[] = __('A {name} {type} needs at least {n} files.', ['name' => $name, 'type' => $label, 'n' => $rule['min_media']]);
        }
        foreach ($this->options($meta) as $f) {
            if (!empty($f['types']) && !in_array($type, $f['types'], true)) {
                continue;
            }
            $v = $options[$f['key']] ?? null;
            $empty = $v === null || $v === '' || $v === [] || (is_array($v) && !array_filter($v, fn ($x) => trim((string) $x) !== ''));
            if ($empty) {
                if (!empty($f['required']) && $strict) {
                    $errors[] = __('{name}: {field} is required.', ['name' => $name, 'field' => $f['label']]);
                }
                continue;
            }
            $bad = match ($f['type']) {
                'text', 'textarea' => !is_string($v) || mb_strlen($v) > ($f['max'] ?? 5000),
                'url' => !is_string($v) || !preg_match('#^https?://\S+$#i', $v) || mb_strlen($v) > 2000,
                'select' => !is_string($v) || (empty($f['dynamic']) && !isset($f['choices'][$v])),
                'checkbox' => !is_bool($v) && !in_array($v, [0, 1, '0', '1'], true),
                'number' => !is_numeric($v) || (isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max']),
                'datetime' => !is_string($v) || to_utc($v, 'UTC') === null,
                'list' => !is_array($v) || count(array_filter($v, fn ($x) => trim((string) $x) !== '')) < ($f['min'] ?? 0)
                    || count($v) > ($f['max'] ?? 10) || (bool) array_filter($v, fn ($x) => !is_scalar($x) || mb_strlen((string) $x) > ($f['item_max'] ?? 200)),
                default => false,
            };
            if ($bad) {
                $errors[] = match ($f['type']) {
                    'list' => __('{name}: {field} needs {min} to {max} entries of up to {len} characters.', ['name' => $name, 'field' => $f['label'], 'min' => $f['min'] ?? 0, 'max' => $f['max'] ?? 10, 'len' => $f['item_max'] ?? 200]),
                    'url' => __('{name}: {field} must be a full link starting with https://.', ['name' => $name, 'field' => $f['label']]),
                    'text', 'textarea' => __('{name}: {field} can be up to {n} characters.', ['name' => $name, 'field' => $f['label'], 'n' => $f['max'] ?? 5000]),
                    default => __('{name}: choose a valid {field}.', ['name' => $name, 'field' => mb_strtolower($f['label'])]),
                };
            }
        }
        return $errors;
    }

    /** Option value with the field's default. */
    protected function opt(array $context, string $key, mixed $default = null): mixed
    {
        $v = $context['options'][$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    protected function type(array $context): ?string
    {
        return $context['content_type'] ?? $this->defaultType();
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
            if (trim(str_val($input[$k] ?? '')) === '') {
                throw new ProviderException('Please fill in all required fields.');
            }
        }
    }
}

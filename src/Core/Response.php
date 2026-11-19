<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Alpine.js (standard build) evaluates expressions with Function(), so 'unsafe-eval' is required;
     * inline <script> blocks must carry the per-request nonce. Inline style attributes are allowed.
     */
    public static function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . csp_nonce() . "' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
                'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            ];
            if (is_https()) {
                $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
            }
            $defaults['X-Request-Id'] = request_id();
            if (str_starts_with($this->headers['Content-Type'] ?? '', 'text/html')) {
                $defaults['Content-Security-Policy'] = self::csp();
                $defaults['Cache-Control'] = 'no-store, private';
            }
            foreach ($this->headers + $defaults as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo $this->body;
    }
}

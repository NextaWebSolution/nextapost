<?php

declare(strict_types=1);

namespace App\Providers;

final class ProviderException extends \RuntimeException
{
    /**
     * @param bool $retryable  temporary problem (network, 5xx, rate limit) — the job will be retried
     * @param bool $authError  the channel's token is invalid/expired — the channel is flagged for reconnect
     */
    public function __construct(string $message, public bool $retryable = false, public bool $authError = false)
    {
        parent::__construct($message);
    }

    /** Build an exception from an HTTP response of a social API. */
    public static function fromResponse(string $network, array $resp, ?string $message = null): self
    {
        $status = (int) ($resp['status'] ?? 0);
        $j = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $msg = $message
            ?? ($j['error']['message'] ?? null)
            ?? ($j['error_description'] ?? null)
            ?? ($j['detail'] ?? null)
            ?? ($j['description'] ?? null)
            ?? ($j['message'] ?? null)
            ?? (is_string($j['error'] ?? null) ? $j['error'] : null)
            ?? ($j['errors'][0]['message'] ?? null)
            ?? ($j['title'] ?? null)
            ?? ('HTTP ' . $status);
        $retry = $status === 0 || $status === 429 || $status >= 500;
        $auth = $status === 401 || in_array((int) ($j['error']['code'] ?? 0), [190, 102], true);
        return new self($network . ': ' . mb_substr((string) $msg, 0, 500), $retry, $auth);
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers extra response headers (e.g. WWW-Authenticate) */
    public function __construct(public int $status, string $message = '', public ?string $errorCode = null, public array $headers = [])
    {
        parent::__construct($message, $status);
    }
}

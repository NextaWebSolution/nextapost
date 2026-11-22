<?php

declare(strict_types=1);

namespace App\Core;

/** An API error with a stable code and optional field details (REST and MCP). */
final class ApiError extends HttpException
{
    /** @param array<string, string|string[]> $details */
    public function __construct(int $status, string $message, string $code, public array $details = [], array $headers = [])
    {
        parent::__construct($status, $message, $code, $headers);
    }

    public static function notFound(string $what): self
    {
        return new self(404, __('{what} not found.', ['what' => $what]), 'not_found');
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message !== '' ? $message : __('Your role in this workspace doesn’t allow that.'), 'forbidden');
    }

    public static function invalid(array $details, string $message = ''): self
    {
        return new self(422, $message !== '' ? $message : __('Some of the input is not valid.'), 'validation_failed', $details);
    }
}

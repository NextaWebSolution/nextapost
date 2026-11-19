<?php

declare(strict_types=1);

namespace App\Providers;

final class PublishResult
{
    public function __construct(public string $externalId, public ?string $url = null)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

final class RedirectException extends \RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct('Redirect to ' . $url);
    }
}

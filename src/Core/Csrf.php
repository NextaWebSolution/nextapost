<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function check(Request $req): bool
    {
        $token = $req->body['_token'] ?? $req->header('x-csrf-token');
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }
}

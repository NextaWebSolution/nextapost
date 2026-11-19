<?php

declare(strict_types=1);

namespace App\Core;

/** Authenticated encryption for secrets at rest (channel tokens, SMTP password) using libsodium. */
final class Crypto
{
    public static function newKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (str_starts_with($k, 'base64:')) {
            $k = (string) base64_decode(substr($k, 7), true);
        }
        if (strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY is missing or invalid in .env (expected base64:… 32 bytes).');
        }
        return $k;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Encrypted value is corrupted.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt value — was APP_KEY changed?');
        }
        return $plain;
    }

    public static function encryptJson(array $data): string
    {
        return self::encrypt((string) json_encode($data));
    }

    public static function decryptJson(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        $d = json_decode(self::decrypt($encoded), true);
        return is_array($d) ? $d : [];
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

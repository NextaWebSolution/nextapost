<?php

declare(strict_types=1);

/**
 * Generates the Ed25519 key pair used to sign update packages.
 *   php tools/keygen.php storage/keys
 * Keep the .key file secret (never commit it). Paste the public key into
 * System → Updates → "Signing public key" on every installation (or set UPDATE_PUBLIC_KEY).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$dir = rtrim($argv[1] ?? (dirname(__DIR__) . '/storage/keys'), '/');
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
if (is_file("$dir/update-signing.key")) {
    fwrite(STDERR, "$dir/update-signing.key already exists — refusing to overwrite.\n");
    exit(1);
}
$pair = sodium_crypto_sign_keypair();
file_put_contents("$dir/update-signing.key", base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
chmod("$dir/update-signing.key", 0600);
$public = base64_encode(sodium_crypto_sign_publickey($pair));
file_put_contents("$dir/update-signing.pub", $public . "\n");
echo "Secret key: $dir/update-signing.key (keep private)\nPublic key: $public\n";

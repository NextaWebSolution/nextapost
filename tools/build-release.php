<?php

declare(strict_types=1);

/**
 * Builds a release package for cPanel upload and for the update server.
 *
 *   php tools/build-release.php [--key=storage/keys/update-signing.key] [--notes="…"] [--url-base=https://updates.nextawebsolution.com/dl]
 *
 * Output in dist/:
 *   nextapost-X.Y.Z.zip           upload to cPanel or serve from the update server
 *   nextapost-X.Y.Z.json          manifest to publish on the update server (sha256, signature, notes)
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
$opts = getopt('', ['key:', 'notes:', 'url-base:', 'with-tests']);
$dist = $root . '/dist';
if (!is_dir($dist)) {
    mkdir($dist, 0755, true);
}
$zipFile = "$dist/nextapost-$version.zip";
@unlink($zipFile);

$exclude = ['.git/', 'dist/', 'node_modules/', '.env', 'storage/installed.lock', 'storage/maintenance.flag', 'storage/cron.lock', 'storage/schema.state', 'storage/migrate.lock'];
if (!isset($opts['with-tests'])) {
    $exclude[] = 'tests/';
    $exclude[] = 'docs/'; // README screenshots: only needed on GitHub
}
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create $zipFile\n");
    exit(1);
}
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    foreach ($exclude as $ex) {
        if ($rel === $ex || (str_ends_with($ex, '/') && str_starts_with($rel, $ex))) {
            continue 2;
        }
    }
    // Ship storage/ and public/media/ as empty folders (keep only their protection files).
    if ((str_starts_with($rel, 'storage/') || str_starts_with($rel, 'public/media/')) && basename($rel) !== '.htaccess') {
        continue;
    }
    $zip->addFile($f->getPathname(), 'nextapost/' . $rel);
    $count++;
}
$zip->close();

$sha = hash_file('sha256', $zipFile);
$signature = '';
if (!empty($opts['key'])) {
    $sk = base64_decode(trim((string) file_get_contents($opts['key'])), true);
    if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Invalid signing key file.\n");
        exit(1);
    }
    $signature = base64_encode(sodium_crypto_sign_detached($sha, $sk));
}
$manifest = [
    'product' => 'nextapost',
    'version' => $version,
    'released_at' => gmdate('Y-m-d'),
    'min_php' => '8.1',
    'notes' => (string) ($opts['notes'] ?? ''),
    'download_url' => rtrim((string) ($opts['url-base'] ?? 'https://updates.nextawebsolution.com/dl'), '/') . "/nextapost-$version.zip",
    'sha256' => $sha,
    'signature' => $signature,
    'size' => filesize($zipFile),
];
file_put_contents("$dist/nextapost-$version.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("Built %s (%d files, %.1f MB)\nsha256 %s\n%s\n", basename($zipFile), $count, filesize($zipFile) / 1048576, $sha, $signature ? 'Signed ✓' : 'Not signed (pass --key=… to sign)');

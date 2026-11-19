<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Env;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Settings;

/**
 * One-click / automatic updates from the Nexta update server.
 *
 * Update server contract (GET {server}/api/v1/check?product=nextapost&version=1.0.0&channel=stable&php=8.3.6):
 *   { "update_available": true, "version": "1.0.1", "released_at": "2026-12-01", "notes": "…",
 *     "download_url": "https://…/nextapost-1.0.1.zip", "sha256": "<hex>", "signature": "<base64 Ed25519 of sha256 hex>",
 *     "min_php": "8.1" }
 *
 * Safety: SHA-256 is always verified; when an update public key is configured the Ed25519 signature
 * must also verify. Before files are replaced a full backup (code + database) is written to
 * storage/backups and restored automatically if anything fails.
 */
final class Updater
{
    /** Never overwritten by an update. .htaccess files are kept because cPanel writes PHP handler rules into them. */
    private const PROTECTED = ['.env', '.htaccess', 'public/.htaccess', 'storage/', 'public/media/'];

    public function __construct(private DB $db)
    {
    }

    public static function config(): array
    {
        $c = (array) Settings::get('updates', []);
        return $c + [
            'server' => (string) Env::get('UPDATE_SERVER', 'https://updates.nextawebsolution.com'),
            'channel' => 'stable',
            'auto_install' => false,
            'public_key' => (string) Env::get('UPDATE_PUBLIC_KEY', ''),
            'license_key' => '',
        ];
    }

    /** Ask the update server for a newer version. Stores the result for the admin banner. */
    public function check(bool $throw = true): ?array
    {
        $c = self::config();
        $server = rtrim((string) $c['server'], '/');
        if (!preg_match('#^https://#', $server)) {
            if ($throw) {
                throw new \RuntimeException('The update server must use https://');
            }
            return null;
        }
        $url = $server . '/api/v1/check?' . http_build_query([
            'product' => APP_PRODUCT, 'version' => APP_VERSION, 'channel' => $c['channel'], 'php' => PHP_VERSION,
            'site' => substr(hash('sha256', (string) Env::get('APP_URL', '')), 0, 16), 'license' => $c['license_key'],
        ]);
        try {
            $resp = Http::request('GET', $url, ['timeout' => 20, 'follow' => true]);
        } catch (\Throwable $e) {
            if ($throw) {
                throw new \RuntimeException('Could not reach the update server: ' . $e->getMessage());
            }
            return null;
        }
        if ($resp['status'] !== 200 || !is_array($resp['json'])) {
            if ($throw) {
                throw new \RuntimeException('The update server answered with HTTP ' . $resp['status'] . '.');
            }
            return null;
        }
        $j = $resp['json'];
        $validVersion = is_string($j['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.]{1,20})?$/', $j['version']);
        $available = !empty($j['update_available']) && $validVersion && version_compare((string) $j['version'], APP_VERSION, '>');
        if ($available && (!preg_match('/^[a-f0-9]{64}$/i', (string) ($j['sha256'] ?? '')) || !preg_match('#^https://#', (string) ($j['download_url'] ?? '')))) {
            $available = false;
        }
        $info = $available ? [
            'version' => (string) $j['version'], 'released_at' => (string) ($j['released_at'] ?? ''), 'notes' => mb_substr((string) ($j['notes'] ?? ''), 0, 20000),
            'download_url' => (string) ($j['download_url'] ?? ''), 'sha256' => strtolower((string) ($j['sha256'] ?? '')),
            'signature' => (string) ($j['signature'] ?? ''), 'min_php' => preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($j['min_php'] ?? '')) ? (string) $j['min_php'] : '8.1',
        ] : null;
        Settings::set('update_available', $info);
        Settings::set('update_checked_at', now_utc());
        // Unattended installs are only allowed for signed packages.
        if ($info && !empty($c['auto_install']) && PHP_SAPI === 'cli' && trim((string) $c['public_key']) !== '') {
            try {
                $this->installAvailable();
            } catch (\Throwable $e) {
                Logger::error('Automatic update failed: ' . $e->getMessage());
                Settings::set('update_last_error', ['at' => now_utc(), 'message' => $e->getMessage()]);
            }
        }
        return $info;
    }

    public function installAvailable(): string
    {
        $info = Settings::get('update_available');
        if (!is_array($info) || empty($info['download_url'])) {
            throw new \RuntimeException('No update is available. Check for updates first.');
        }
        if (version_compare(PHP_VERSION, (string) $info['min_php'], '<')) {
            throw new \RuntimeException('This update needs PHP ' . $info['min_php'] . ' or newer. Change it in cPanel → Select PHP Version.');
        }
        if (!preg_match('#^https://#', (string) $info['download_url'])) {
            throw new \RuntimeException('Refusing to download an update over an insecure connection.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $info['sha256'])) {
            throw new \RuntimeException('The update server did not provide a valid checksum.');
        }
        @set_time_limit(600);
        $zip = APP_ROOT . '/storage/updates/nextapost-' . preg_replace('/[^0-9a-z.-]/i', '', (string) $info['version']) . '.zip';
        $resp = Http::request('GET', (string) $info['download_url'], ['sink' => $zip, 'timeout' => 300, 'follow' => true]);
        if ($resp['status'] !== 200 || !is_file($zip)) {
            @unlink($zip);
            throw new \RuntimeException('Download failed (HTTP ' . $resp['status'] . ').');
        }
        $this->verify($zip, (string) $info['sha256'], (string) $info['signature']);
        return $this->install($zip);
    }

    public function verify(string $zip, string $sha256, string $signature): void
    {
        $actual = hash_file('sha256', $zip);
        if (!hash_equals(strtolower($sha256), (string) $actual)) {
            @unlink($zip);
            throw new \RuntimeException('The downloaded file failed its checksum. The update was not installed.');
        }
        $key = trim((string) self::config()['public_key']);
        if ($key !== '') {
            $pk = base64_decode($key, true);
            $sig = base64_decode($signature, true);
            if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
                || !sodium_crypto_sign_verify_detached($sig, (string) $actual, $pk)) {
                @unlink($zip);
                throw new \RuntimeException('The update signature is invalid. The update was not installed.');
            }
        }
    }

    /**
     * Install an update package (zip). Used by one-click, automatic and manual-upload updates.
     * @return string the new version
     */
    public function install(string $zipFile, bool $allowSameVersion = false): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required. Enable it in cPanel → Select PHP Version → Extensions.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('The update file is not a valid zip archive.');
        }
        // Find the package root (archives may contain a top-level folder).
        $versionIndex = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\') || preg_match('#^[a-z]:#i', $n)) {
                $zip->close();
                throw new \RuntimeException('The update package contains unsafe paths.');
            }
            if (preg_match('#^([^/]+/)?VERSION$#', $n) && ($versionIndex === null || strlen($n) < strlen((string) $zip->getNameIndex($versionIndex)))) {
                $versionIndex = $i;
            }
        }
        if ($versionIndex === null) {
            $zip->close();
            throw new \RuntimeException('This is not a NextaPost update package (VERSION file missing).');
        }
        $prefix = (string) preg_replace('#VERSION$#', '', (string) $zip->getNameIndex($versionIndex));
        $newVersion = trim((string) $zip->getFromIndex($versionIndex));
        if ($zip->locateName($prefix . 'src/Core/App.php') === false || !preg_match('/^\d+\.\d+\.\d+([.-][\w.]+)?$/', $newVersion)) {
            $zip->close();
            throw new \RuntimeException('This is not a NextaPost update package.');
        }
        if (!$allowSameVersion && version_compare($newVersion, APP_VERSION, '<=')) {
            $zip->close();
            throw new \RuntimeException("Version $newVersion is not newer than the installed " . APP_VERSION . '.');
        }

        // Pre-flight: every target must be writable before we touch anything.
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (!str_starts_with($n, $prefix) || str_ends_with($n, '/')) {
                continue;
            }
            $rel = substr($n, strlen($prefix));
            if ($rel === '' || self::isProtected($rel)) {
                continue;
            }
            $dest = APP_ROOT . '/' . $rel;
            $check = is_file($dest) ? $dest : self::existingParent(dirname($dest));
            if (!is_writable($check)) {
                $zip->close();
                throw new \RuntimeException('Not writable: ' . $rel . '. Fix file permissions (folders 755, files 644, owned by your cPanel user) and try again.');
            }
            $entries[$i] = $rel;
        }

        $backup = (new Backup($this->db))->create('pre-update');
        $flag = APP_ROOT . '/storage/maintenance.flag';
        file_put_contents($flag, now_utc());
        $created = [];
        $zipOpen = true;
        try {
            foreach ($entries as $i => $rel) {
                $dest = APP_ROOT . '/' . $rel;
                if (!is_file($dest)) {
                    $created[] = $dest;
                }
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) {
                    throw new \RuntimeException('Could not create folder for ' . $rel);
                }
                $data = $zip->getFromIndex($i);
                if ($data === false || file_put_contents($dest . '.np-new', $data) === false || !rename($dest . '.np-new', $dest)) {
                    @unlink($dest . '.np-new');
                    throw new \RuntimeException('Could not write ' . $rel);
                }
            }
            $zip->close();
            $zipOpen = false;
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            (new Migrator($this->db))->migrate();
        } catch (\Throwable $e) {
            if ($zipOpen) {
                $zip->close();
            }
            Logger::error('Update failed, rolling back: ' . $e->getMessage());
            try {
                Backup::restoreFiles($backup);
                foreach ($created as $f) {
                    @unlink($f); // files that only exist in the failed version
                }
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
            } catch (\Throwable $re) {
                Logger::error('Rollback failed: ' . $re->getMessage());
                @unlink($flag);
                throw new \RuntimeException('Update failed and automatic rollback also failed. Restore manually from ' . basename($backup) . ' (storage/backups). Error: ' . $e->getMessage());
            }
            @unlink($flag);
            throw new \RuntimeException('Update failed and your previous version was restored. Error: ' . $e->getMessage());
        }
        @unlink($flag);
        // Only clean up packages we downloaded/received ourselves, never a file the admin pointed us at.
        $real = realpath($zipFile);
        if ($real !== false && str_starts_with($real, (string) realpath(APP_ROOT . '/storage/updates') . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
        Settings::set('update_available', null);
        Settings::set('update_last', ['from' => APP_VERSION, 'to' => $newVersion, 'at' => now_utc(), 'backup' => basename($backup)]);
        Backup::prune(5);
        Logger::info('Updated from ' . APP_VERSION . ' to ' . $newVersion);
        return $newVersion;
    }

    public static function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $p) {
            if ($rel === $p || (str_ends_with($p, '/') && str_starts_with($rel, $p))) {
                return true;
            }
        }
        return false;
    }

    private static function existingParent(string $dir): string
    {
        while (!is_dir($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        return $dir;
    }
}

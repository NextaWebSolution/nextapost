<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Creates zip backups of the application code + a SQL dump of the database. */
final class Backup
{
    /** Paths (relative to APP_ROOT) never included in code backups. */
    private const EXCLUDE = ['storage/', 'public/media/', '.git/', 'node_modules/', 'tests/'];

    public function __construct(private DB $db)
    {
    }

    public static function dir(): string
    {
        $d = APP_ROOT . '/storage/backups';
        if (!is_dir($d)) {
            mkdir($d, 0755, true);
        }
        return $d;
    }

    public function create(string $label = 'manual'): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for backups. Enable it in cPanel → Select PHP Version → Extensions.');
        }
        @set_time_limit(300);
        $name = 'backup-' . preg_replace('/[^a-z0-9.-]/i', '', APP_VERSION) . '-' . preg_replace('/[^a-z0-9-]/i', '', $label) . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.zip';
        $file = self::dir() . '/' . $name;
        $sql = APP_ROOT . '/storage/tmp/db-' . bin2hex(random_bytes(4)) . '.sql';
        $this->dumpDatabase($sql);
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the backup file in storage/backups.');
        }
        foreach (self::codeFiles() as $rel) {
            $zip->addFile(APP_ROOT . '/' . $rel, 'files/' . $rel);
        }
        if (is_file(APP_ROOT . '/.env')) {
            $zip->addFile(APP_ROOT . '/.env', 'files/.env');
        }
        $zip->addFile($sql, 'database.sql');
        $zip->addFromString('backup.json', (string) json_encode(['version' => APP_VERSION, 'created_at' => now_utc(), 'driver' => $this->db->driver()]));
        $zip->close();
        @unlink($sql);
        @chmod($file, 0600);
        return $file;
    }

    /** @return string[] relative paths of application files */
    public static function codeFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APP_ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(APP_ROOT))), '/');
            if ($rel === '.env') {
                continue;
            }
            foreach (self::EXCLUDE as $ex) {
                if (str_starts_with($rel, $ex)) {
                    continue 2;
                }
            }
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    public function dumpDatabase(string $file): void
    {
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Could not write the database dump.');
        }
        $pdo = $this->db->pdo();
        fwrite($fh, "-- NextaPost database backup " . now_utc() . " UTC, version " . APP_VERSION . "\n");
        if ($this->db->driver() === 'sqlite') {
            $tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
            foreach ($tables as $t) {
                fwrite($fh, "DROP TABLE IF EXISTS \"{$t['name']}\";\n{$t['sql']};\n");
                $this->dumpRows($fh, (string) $t['name'], '"');
            }
        } else {
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
                $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', (string) $table) . '`')->fetch(\PDO::FETCH_NUM);
                fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n");
                $this->dumpRows($fh, (string) $table, '`');
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        }
        fclose($fh);
    }

    private function dumpRows($fh, string $table, string $q): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->query('SELECT * FROM ' . $q . $table . $q);
        $batch = [];
        $cols = null;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $cols ??= implode(', ', array_map(static fn ($c) => $q . $c . $q, array_keys($row)));
            $batch[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            if (count($batch) >= 200) {
                fwrite($fh, "INSERT INTO $q$table$q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($fh, "INSERT INTO $q$table$q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    /** @return array<int, array{name:string,size:int,created:int}> */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.zip') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'created' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['created'] <=> $a['created']);
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^backup-[\w.-]+\.zip$/', $name)) {
            return null;
        }
        $f = self::dir() . '/' . $name;
        return is_file($f) ? $f : null;
    }

    /** Restores code files from a backup zip (used for automatic rollback after a failed update). */
    public static function restoreFiles(string $zipFile): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Could not open backup for rollback.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'files/') || str_ends_with($name, '/') || str_contains($name, '..') || $name === 'files/.env') {
                continue;
            }
            $dest = APP_ROOT . '/' . substr($name, 6);
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            file_put_contents($dest, (string) $zip->getFromIndex($i));
        }
        $zip->close();
    }

    public static function prune(int $keep = 5): void
    {
        foreach (array_slice(self::list(), $keep) as $b) {
            @unlink(self::dir() . '/' . $b['name']);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies database/schema.sql (idempotent CREATE ... IF NOT EXISTS) and then any
 * pending files in database/migrations/*.sql, tracked in the `migrations` table.
 * Written in MySQL dialect; translated on the fly for SQLite (used by the test suite).
 */
final class Migrator
{
    public function __construct(private DB $db)
    {
    }

    /** @return string[] names of migrations applied in this run */
    /**
     * Applies pending database changes once after any code change: an update uploaded by hand
     * (cPanel File Manager, FTP, git pull) never ran the updater, so the code would otherwise
     * expect tables that don't exist yet. Costs one small file read per request when up to date.
     */
    public static function ensureCurrent(DB $db): void
    {
        if (!is_file(APP_ROOT . '/storage/installed.lock')) {
            return; // the installer migrates
        }
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        $sig = APP_VERSION . '|' . md5(implode(',', array_map('basename', $files)));
        $marker = APP_ROOT . '/storage/schema.state';
        if (is_file($marker) && trim((string) file_get_contents($marker)) === $sig) {
            return;
        }
        $lock = fopen(APP_ROOT . '/storage/migrate.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not create storage/migrate.lock. Make the storage folder writable.');
        }
        try {
            flock($lock, LOCK_EX);
            clearstatcache(true, $marker);
            if (is_file($marker) && trim((string) file_get_contents($marker)) === $sig) {
                return; // another request finished it while we waited
            }
            $applied = (new self($db))->migrate();
            file_put_contents($marker, $sig, LOCK_EX);
            if ($applied) {
                Logger::info('Database updated for ' . APP_VERSION . ': ' . implode(', ', $applied));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function migrate(): array
    {
        $this->execFile(APP_ROOT . '/database/schema.sql');
        $applied = [];
        $done = array_column($this->db->all('SELECT name FROM migrations'), 'name');
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $this->execFile($file);
            $this->db->insert('migrations', ['name' => $name, 'applied_at' => now_utc()]);
            $applied[] = $name;
        }
        return $applied;
    }

    private function execFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        if ($this->db->driver() === 'sqlite') {
            $sql = self::toSqlite($sql);
        }
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            try {
                $this->db->pdo()->exec($stmt);
            } catch (\PDOException $e) {
                // Re-running is safe: ignore "already exists" style errors (indexes, columns).
                if (preg_match('/already exists|Duplicate key name|Duplicate column name|duplicate column/i', $e->getMessage())) {
                    continue;
                }
                throw new \RuntimeException('Migration failed in ' . basename($file) . ': ' . $e->getMessage() . "\nSQL: " . substr($stmt, 0, 300), 0, $e);
            }
        }
    }

    public static function toSqlite(string $sql): string
    {
        $sql = (string) preg_replace('/BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = (string) preg_replace('/\s+UNSIGNED/i', '', $sql);
        $sql = (string) preg_replace('/\)\s*ENGINE=[^;]*;/i', ');', $sql);
        $sql = (string) preg_replace('/CREATE (UNIQUE )?INDEX (?!IF NOT EXISTS)/i', 'CREATE $1INDEX IF NOT EXISTS ', $sql);
        // SQLite has no MODIFY and doesn't limit text length, so column-size changes don't apply there.
        $sql = (string) preg_replace('/^\s*ALTER TABLE \w+ MODIFY [^;]*;/mi', '', $sql);
        return $sql;
    }
}

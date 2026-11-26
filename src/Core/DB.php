<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

final class DB
{
    private ?PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public static function fromEnv(): self
    {
        return new self([
            'driver' => (string) Env::get('DB_DRIVER', 'mysql'),
            'host' => (string) Env::get('DB_HOST', 'localhost'),
            'port' => (string) Env::get('DB_PORT', '3306'),
            'name' => (string) Env::get('DB_NAME', ''),
            'user' => (string) Env::get('DB_USER', ''),
            'pass' => (string) Env::get('DB_PASS', ''),
            'path' => (string) Env::get('DB_PATH', APP_ROOT . '/storage/database.sqlite'),
        ]);
    }

    public function driver(): string
    {
        return $this->config['driver'] === 'sqlite' ? 'sqlite' : 'mysql';
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            if ($this->driver() === 'sqlite') {
                $this->pdo = new PDO('sqlite:' . $c['path']);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?: '3306', $c['name']);
                $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10]);
                $this->pdo->exec("SET time_zone = '+00:00'");
                $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return $this->pdo;
    }

    /** Test-only hook: lets the test suites emulate MySQL strict mode on SQLite. Never set in production. */
    public static ?\Closure $writeCheck = null;

    public function run(string $sql, array $params = []): PDOStatement
    {
        if (self::$writeCheck !== null) {
            (self::$writeCheck)($sql, array_values($params));
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        return $this->run("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    /** Build "?, ?, ?" for IN() clauses. */
    public static function in(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    public function tx(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function isDuplicate(PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}

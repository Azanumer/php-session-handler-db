<?php

declare(strict_types=1);

namespace Azanumer;

/**
 * Dependency-free PDO session handler.
 *
 * Stores PHP sessions in a database table instead of files, so sessions
 * survive across multiple web servers behind a load balancer and are not
 * lost when the filesystem is wiped on deploy.
 *
 * Table schema (MySQL / MariaDB):
 *
 *   CREATE TABLE sessions (
 *       id         VARCHAR(128) NOT NULL PRIMARY KEY,
 *       data       MEDIUMBLOB   NOT NULL,
 *       last_used  INT UNSIGNED NOT NULL,
 *       created_at INT UNSIGNED NOT NULL,
 *       INDEX idx_last_used (last_used)
 *   ) ENGINE=InnoDB;
 *
 * SQLite also works — the queries use only standard SQL.
 *
 * Usage:
 *
 *   $pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
 *   $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
 *   $handler = new DbSessionHandler($pdo, 'sessions');
 *   session_set_save_handler($handler, true);
 *   session_start();
 */
class DbSessionHandler implements \SessionHandlerInterface
{
    private \PDO $pdo;
    private string $table;

    /** @var \PDOStatement|null Prepared statements, built lazily. */
    private ?\PDOStatement $readStmt = null;
    private ?\PDOStatement $writeStmt = null;
    private ?\PDOStatement $destroyStmt = null;
    private ?\PDOStatement $gcStmt = null;

    public function __construct(\PDO $pdo, string $table = 'sessions')
    {
        $this->pdo   = $pdo;
        $this->table = $table;
    }

    public function open(string $path, string $name): bool
    {
        // Nothing to open — the PDO connection is already live.
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $this->readStmt ??= $this->pdo->prepare(
            "SELECT data FROM {$this->table} WHERE id = :id"
        );
        $this->readStmt->execute([':id' => $id]);
        $row = $this->readStmt->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            return '';
        }
        // Stored as a binary blob; cast to string for the session engine.
        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $now = time();

        // MySQL/MariaDB support ON DUPLICATE KEY UPDATE; every other driver
        // gets a portable UPDATE-then-INSERT inside a transaction.
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ('mysql' === $driver) {
            $this->writeStmt ??= $this->pdo->prepare(
                "INSERT INTO {$this->table} (id, data, last_used, created_at)
                 VALUES (:id, :data, :now, :now)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), last_used = VALUES(last_used)"
            );
            return $this->writeStmt->execute([
                ':id'   => $id,
                ':data' => $data,
                ':now'  => $now,
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                "UPDATE {$this->table} SET data = :data, last_used = :now WHERE id = :id"
            );
            $update->execute([':data' => $data, ':now' => $now, ':id' => $id]);
            if (0 === $update->rowCount()) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (id, data, last_used, created_at)
                     VALUES (:id, :data, :now, :now)"
                );
                $insert->execute([':id' => $id, ':data' => $data, ':now' => $now]);
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function destroy(string $id): bool
    {
        $this->destroyStmt ??= $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE id = :id"
        );
        return $this->destroyStmt->execute([':id' => $id]);
    }

    /**
     * Garbage collection: delete sessions idle longer than $maxLifetime seconds.
     * Returns the number of rows removed, or false on failure.
     */
    public function gc(int $maxLifetime): int|false
    {
        $this->gcStmt ??= $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE last_used < :cutoff"
        );
        $ok = $this->gcStmt->execute([':cutoff' => time() - $maxLifetime]);
        return $ok ? $this->gcStmt->rowCount() : false;
    }

    /**
     * Count currently stored sessions (handy for a status page / monitor).
     */
    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$this->table}");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Touch a session's last-used timestamp without rewriting its data.
     * Useful for long-polling / heartbeat endpoints that should keep a
     * session alive without the cost of a full write.
     */
    public function touch(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET last_used = :now WHERE id = :id"
        );
        return $stmt->execute([':now' => time(), ':id' => $id]);
    }
}

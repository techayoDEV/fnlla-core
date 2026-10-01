<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA DATABASE SOURCE
File: src\Database\DatabaseManager.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained MySQL data access and migration runtime.
*/

namespace Fnlla\Php\Database;

use PDO;
use PDOException;
use RuntimeException;
use Fnlla\Php\Observability\QueryTelemetry;

final class DatabaseManager
{
    private ?PDO $pdo = null;
    /** @var array<string, self> */
    private array $named = [];
    private int $managedTransactionDepth = 0;
    private bool $transactionBroken = false;
    /** @var list<list<callable>> */
    private array $afterCommitFrames = [];

    public function __construct(private ?string $connectionName = null) {}

    public function forConnection(string $name): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new RuntimeException("Invalid database connection name.");
        }
        if ($name === ($this->connectionName ?? (string) config("database.default", "mysql"))) {
            return $this;
        }
        return $this->named[$name] ??= new self($name);
    }

    public function registerConnection(string $name, PDO $connection): self
    {
        $manager = $this->forConnection($name);
        $manager->purge();
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $manager->pdo = $connection;
        return $manager;
    }

    public function purge(?string $name = null): void
    {
        if ($name !== null) {
            $this->forConnection($name)->purge();
            return;
        }
        if ($this->managedTransactionDepth > 0 || $this->pdo?->inTransaction()) {
            throw new RuntimeException("Cannot purge a connection with an active transaction.");
        }
        $this->pdo = null;
        $this->transactionBroken = false;
    }

    public static function using(PDO $connection): self
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $manager = new self();
        $manager->pdo = $connection;
        return $manager;
    }

    public function connection(?string $name = null): PDO
    {
        if ($name !== null) {
            return $this->forConnection($name)->connection();
        }
        if ($this->transactionBroken) { throw new RuntimeException("Database connection requires purge after transaction failure."); }
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $name = $this->connectionName ?? (string) config("database.default", "mysql");
        $connections = (array) config("database.connections", []);
        $connection = $connections[$name] ?? null;

        if (!is_array($connection)) {
            throw new RuntimeException("Unknown or invalid database connection: " . $name);
        }

        if (($connection["driver"] ?? "mysql") !== "mysql") {
            throw new RuntimeException("Only the mysql connection driver is configured by this runtime.");
        }

        if (!extension_loaded("pdo_mysql")) {
            throw new RuntimeException("Database connection failed: the PDO MySQL driver (pdo_mysql) is not installed.");
        }

        $options = [];

        if (!empty($connection["persistent"])) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        foreach ($this->sslOptions((array) ($connection["ssl"] ?? [])) as $option => $value) {
            $options[$option] = $value;
        }

        try {
            $pdo = new PDO(
                sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                    $connection["host"] ?? "127.0.0.1",
                    $connection["port"] ?? "3306",
                    $connection["database"] ?? "",
                    $connection["charset"] ?? "utf8mb4"
                ),
                (string) ($connection["username"] ?? ""),
                (string) ($connection["password"] ?? ""),
                $options
            );
        } catch (PDOException $exception) {
            throw new RuntimeException("Database connection failed: " . $exception->getMessage(), 0, $exception);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $this->pdo = $pdo;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection(), $table);
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $statement = $this->connection()->prepare($sql);

        return QueryTelemetry::execute($statement, $bindings);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->connection()->prepare($sql);
        QueryTelemetry::execute($statement, $bindings);

        return $statement->fetchAll();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $nested = $pdo->inTransaction();
        $externallyOwned = $nested && $this->managedTransactionDepth === 0;
        // Unique names also isolate calls inside transactions owned by application code.
        $savepoint = "fnlla_" . bin2hex(random_bytes(12));
        if ($nested) {
            if ($pdo->exec("SAVEPOINT " . $savepoint) === false) { throw new RuntimeException("Unable to create transaction savepoint."); }
        } else {
            if (!$pdo->beginTransaction()) { throw new RuntimeException("Unable to begin transaction."); }
        }

        $this->managedTransactionDepth++;
        $this->afterCommitFrames[] = [];
        try {
            $result = $callback($this);
            if ($this->transactionBroken || !$pdo->inTransaction()) {
                throw new RuntimeException("Transaction ended inside its callback. Do not commit, roll back or execute implicit-commit DDL inside transaction().");
            }
            $callbacks = array_pop($this->afterCommitFrames) ?? [];
            if ($externallyOwned && $callbacks !== []) {
                throw new RuntimeException("Cannot defer external effects from a transaction owned outside DatabaseManager.");
            }
            if ($nested) {
                if ($pdo->exec("RELEASE SAVEPOINT " . $savepoint) === false) { throw new RuntimeException("Unable to release transaction savepoint."); }
            } else {
                if (!$pdo->commit()) { throw new RuntimeException("Unable to commit transaction."); }
            }
        } catch (\Throwable $exception) {
            if (count($this->afterCommitFrames) >= $this->managedTransactionDepth) {
                array_pop($this->afterCommitFrames);
            }
            try {
                // Deadlocks and connection failures may already have ended the transaction.
                if ($pdo->inTransaction()) {
                    if ($nested) {
                        if ($pdo->exec("ROLLBACK TO SAVEPOINT " . $savepoint) === false
                            || $pdo->exec("RELEASE SAVEPOINT " . $savepoint) === false) {
                            throw new RuntimeException("Unable to roll back transaction savepoint.");
                        }
                    } else {
                        if (!$pdo->rollBack()) { throw new RuntimeException("Unable to roll back transaction."); }
                    }
                }
            } catch (\Throwable) {
                $this->transactionBroken = true;
                throw new RuntimeException("Transaction rollback failed; connection invalidated.", 0, $exception);
            } finally {
                $this->managedTransactionDepth--;
                if ($this->transactionBroken && $this->managedTransactionDepth === 0) {
                    $this->pdo = null;
                    $this->afterCommitFrames = [];
                }
            }
            throw $exception;
        }

        $this->managedTransactionDepth--;
        if ($nested && !$externallyOwned) {
            $parent = array_key_last($this->afterCommitFrames);
            if ($parent === null) {
                throw new RuntimeException("Managed transaction callback stack is inconsistent.");
            }
            array_push($this->afterCommitFrames[$parent], ...$callbacks);
            return $result;
        }

        foreach ($callbacks as $index => $afterCommit) {
            try {
                $afterCommit();
            } catch (\Throwable $exception) {
                throw new PostCommitCallbackException(
                    "Database commit succeeded, but after-commit callback " . ($index + 1) . " failed: " . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        return $result;
    }

    public function afterCommit(callable $callback): void
    {
        if ($this->transactionBroken) { throw new RuntimeException("Cannot defer effects on an invalidated connection."); }
        if ($this->managedTransactionDepth === 0) {
            if ($this->pdo?->inTransaction()) {
                throw new RuntimeException("Cannot defer external effects from a transaction owned outside DatabaseManager.");
            }
            $callback();
            return;
        }
        $index = array_key_last($this->afterCommitFrames);
        if ($index === null) {
            throw new RuntimeException("Managed transaction callback stack is unavailable.");
        }
        $this->afterCommitFrames[$index][] = $callback;
    }

    /** Does not open a connection merely to check an effect boundary. */
    public function hasActiveTransaction(): bool
    {
        return $this->transactionBroken || $this->managedTransactionDepth > 0 || ($this->pdo?->inTransaction() ?? false);
    }

    public function hasActiveManagedTransaction(): bool
    {
        return $this->managedTransactionDepth > 0;
    }

    public function supportsTransactionalMigrations(): bool
    {
        $configured = config("database.transactional_migrations", null);

        if ($configured !== null) {
            return (bool) $configured;
        }

        return $this->connection()->getAttribute(PDO::ATTR_DRIVER_NAME) !== "mysql"
            || (bool) config("database.mysql_transactional_migrations", false);
    }

    private function sslOptions(array $ssl): array
    {
        if (!($ssl["enabled"] ?? false)) {
            return [];
        }

        $options = [];
        $map = [
            "ca" => "PDO::MYSQL_ATTR_SSL_CA",
            "cert" => "PDO::MYSQL_ATTR_SSL_CERT",
            "key" => "PDO::MYSQL_ATTR_SSL_KEY",
            "verify_server_cert" => "PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT",
        ];

        foreach ($map as $key => $constant) {
            if (!defined($constant)) {
                continue;
            }

            $value = $ssl[$key] ?? null;

            if ($key === "verify_server_cert") {
                $options[constant($constant)] = (bool) $value;
                continue;
            }

            if (is_string($value) && trim($value) !== "") {
                $options[constant($constant)] = trim($value);
            }
        }

        return $options;
    }
}

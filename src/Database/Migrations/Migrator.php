<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA DATABASE SOURCE
File: src\Database\Migrations\Migrator.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained MySQL data access and migration runtime.
*/

namespace Fnlla\Php\Database\Migrations;

use Fnlla\Php\Database\DatabaseManager;
use RuntimeException;

final class Migrator
{
    public function __construct(private DatabaseManager $database, private ?string $directory = null)
    {
    }

    public function migrate(): array
    {
        $this->ensureRepository();
        $ran = $this->ran();
        $executed = [];
        $batch = $this->nextBatchNumber();

        foreach ($this->migrationFiles() as $file) {
            $migrationName = basename($file);

            if (in_array($migrationName, $ran, true)) {
                continue;
            }

            $migration = require $file;

            if (!$migration instanceof Migration) {
                throw new RuntimeException("Migration file must return a Migration instance: " . $file);
            }

            $migration->useDatabase($this->database);

            $this->runMigrationStep($migration, function () use ($migration, $migrationName, $batch): void {
                $migration->up();
                $this->database->table((string) config("database.migrations_table", "migrations"))->insert([
                    "migration" => $migrationName,
                    "batch" => $batch,
                    "ran_at" => gmdate("Y-m-d H:i:s"),
                ]);
            });

            $executed[] = $migrationName;
        }

        return $executed;
    }

    public function status(): array
    {
        $this->ensureRepository();
        $ran = $this->ranWithBatches();
        $status = [];

        foreach ($this->migrationFiles() as $file) {
            $migrationName = basename($file);
            $status[] = [
                "migration" => $migrationName,
                "ran" => isset($ran[$migrationName]),
                "batch" => $ran[$migrationName] ?? null,
            ];
        }

        return $status;
    }

    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $steps = max(1, $steps);
        $rolledBack = [];

        foreach ($this->rollbackBatches($steps) as $batch) {
            foreach ($this->ranInBatch($batch) as $migrationName) {
                if (basename($migrationName) !== $migrationName) {
                    throw new RuntimeException("Invalid stored migration name.");
                }
                $file = ($this->directory ?? base_path("database/migrations")) . "/" . $migrationName;

                if (!is_file($file)) {
                    throw new RuntimeException("Migration file not found for rollback: " . $migrationName);
                }

                $migration = require $file;

                if (!$migration instanceof Migration) {
                    throw new RuntimeException("Migration file must return a Migration instance: " . $file);
                }

                $migration->useDatabase($this->database);

                $this->runMigrationStep($migration, function () use ($migration, $migrationName): void {
                    $migration->down();
                    $this->database->table((string) config("database.migrations_table", "migrations"))
                        ->where("migration", $migrationName)
                        ->delete();
                });

                $rolledBack[] = $migrationName;
            }
        }

        return $rolledBack;
    }

    private function runMigrationStep(Migration $migration, callable $callback): void
    {
        if ($migration->withinTransaction && $this->database->supportsTransactionalMigrations()) {
            $this->database->transaction($callback);
            return;
        }

        $callback();
    }

    private function ensureRepository(): void
    {
        $table = $this->quoteIdentifier((string) config("database.migrations_table", "migrations"));
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, batch INT NOT NULL, ran_at DATETIME NOT NULL)";

        $this->database->statement($sql);
    }

    private function ran(): array
    {
        return array_keys($this->ranWithBatches());
    }

    private function ranWithBatches(): array
    {
        $rows = $this->database->table((string) config("database.migrations_table", "migrations"))
            ->select(["migration", "batch"])
            ->orderBy("id")
            ->get();

        $ran = [];

        foreach ($rows as $row) {
            $ran[(string) $row["migration"]] = (int) $row["batch"];
        }

        return $ran;
    }

    private function migrationFiles(): array
    {
        $files = glob(($this->directory ?? base_path("database/migrations")) . "/*.php");

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    private function nextBatchNumber(): int
    {
        $table = $this->quoteIdentifier((string) config("database.migrations_table", "migrations"));
        $rows = $this->database->select(
            "SELECT MAX(batch) AS batch FROM " . $table
        );

        return (int) (($rows[0]["batch"] ?? 0) + 1);
    }

    private function rollbackBatches(int $steps): array
    {
        $table = $this->quoteIdentifier((string) config("database.migrations_table", "migrations"));
        $rows = $this->database->select(
            "SELECT DISTINCT batch FROM " . $table . " ORDER BY batch DESC"
        );

        $batches = array_map(static fn (array $row): int => (int) $row["batch"], $rows);

        return array_slice($batches, 0, $steps);
    }

    private function ranInBatch(int $batch): array
    {
        $rows = $this->database->table((string) config("database.migrations_table", "migrations"))
            ->select(["migration"])
            ->where("batch", $batch)
            ->orderBy("id", "desc")
            ->get();

        return array_map(static fn (array $row): string => (string) $row["migration"], $rows);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === "" || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException("Invalid migration table identifier: " . $identifier);
        }

        return "`" . $identifier . "`";
    }
}

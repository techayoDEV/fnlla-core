<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA DATABASE SOURCE
File: src\Database\Migrations\Migration.php
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

abstract class Migration
{
    public bool $withinTransaction = true;

    public function __construct(protected DatabaseManager $database)
    {
    }

    abstract public function up(): void;

    /** The migrator owns the connection for both schema changes and its ledger. */
    final public function useDatabase(DatabaseManager $database): void
    {
        $this->database = $database;
    }

    public function down(): void
    {
    }

    protected function statement(string $sql): void
    {
        $this->database->statement($sql);
    }
}

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\MigrateCommand.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained CLI surface and scheduler-oriented console behaviour.
*/

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\MigrationCommand;

final class MigrateCommand extends MigrationCommand
{
    public function name(): string
    {
        return "migrate";
    }

    public function description(): string
    {
        return "Run pending database migrations.";
    }

    public function handle(array $arguments): int
    {
        $prepared = $this->prepare($arguments);
        if ($prepared === null) { return 0; }
        [$migrator] = $prepared;
        $executed = $migrator->migrate();

        if ($executed === []) {
            $this->line("Nothing to migrate.");

            return 0;
        }

        foreach ($executed as $migration) {
            $this->line("Migrated: " . $migration);
        }

        return 0;
    }
}

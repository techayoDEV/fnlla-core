<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\MigrateStatusCommand.php
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

final class MigrateStatusCommand extends MigrationCommand
{
    public function name(): string
    {
        return "migrate:status";
    }

    public function description(): string
    {
        return "Show migration status.";
    }

    public function handle(array $arguments): int
    {
        $prepared = $this->prepare($arguments, false, false);
        if ($prepared === null) { return 0; }
        [$migrator] = $prepared;

        foreach ($migrator->status() as $migration) {
            $this->line(sprintf(
                "[%s] batch=%s %s",
                $migration["ran"] ? "x" : " ",
                $migration["batch"] ?? "-",
                $migration["migration"]
            ));
        }

        return 0;
    }
}

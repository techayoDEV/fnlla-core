<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\QueueWorkCommand.php
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

use Fnlla\Php\Console\Command;
use Fnlla\Php\Queue\QueueManager;

final class QueueWorkCommand extends Command
{
    public function name(): string
    {
        return "queue:work";
    }

    public function description(): string
    {
        return "Process queued jobs from the file queue.";
    }

    public function handle(array $arguments): int
    {
        $maxJobs = isset($arguments[0]) ? max(1, (int) $arguments[0]) : 50;
        $processed = $this->container->make(QueueManager::class)->work($maxJobs);
        $this->line("Processed jobs: " . $processed);

        return 0;
    }
}

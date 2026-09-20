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
        return "Process registered versioned jobs from the configured queue.";
    }

    public function handle(array $arguments): int
    {
        $maxJobs = isset($arguments[0]) ? max(1, (int) $arguments[0]) : 50;
        $maxSeconds = isset($arguments[1]) ? max(1, (int) $arguments[1]) : null;
        $worker = $this->container->make(QueueManager::class);
        if (function_exists("pcntl_async_signals") && function_exists("pcntl_signal")) {
            pcntl_async_signals(true);
            foreach ([defined("SIGTERM") ? SIGTERM : null, defined("SIGINT") ? SIGINT : null] as $signal) {
                if (is_int($signal)) {
                    pcntl_signal($signal, static function () use ($worker): void { $worker->requestStop(); });
                }
            }
        }
        $processed = $worker->work($maxJobs, $maxSeconds);
        $this->line("Processed jobs: " . $processed);

        return 0;
    }
}

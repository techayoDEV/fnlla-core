<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\CacheClearCommand.php
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

use Fnlla\Php\Cache\CacheStoreInterface;
use Fnlla\Php\Console\Command;
use Fnlla\Php\Events\Dispatcher;

final class CacheClearCommand extends Command
{
    public function name(): string
    {
        return "cache:clear";
    }

    public function description(): string
    {
        return "Clear the configured application cache store.";
    }

    public function handle(array $arguments): int
    {
        if (!$this->container->make(CacheStoreInterface::class)->clear()) {
            throw new \RuntimeException("The cache store could not be cleared.");
        }
        $this->container->make(Dispatcher::class)->dispatch("cache.cleared");

        $this->line("Cache cleared.");

        return 0;
    }
}

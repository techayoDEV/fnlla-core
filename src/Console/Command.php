<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Command.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained CLI surface and scheduler-oriented console behaviour.
*/

namespace Fnlla\Php\Console;

use Fnlla\Php\Container\Container;

abstract class Command
{
    public function __construct(protected Container $container)
    {
    }

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function handle(array $arguments): int;

    public function usage(): string
    {
        return $this->name() . " [arguments]";
    }

    public function printHelp(): void
    {
        $this->line($this->description());
        $this->line("Usage: php fnlla " . $this->usage());
    }

    public function hidden(): bool
    {
        return false;
    }

    protected function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    protected function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}

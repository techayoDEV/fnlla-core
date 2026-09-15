<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Application.php
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
use Throwable;

final class Application
{
    private array $commands = [];

    public function __construct(private Container $container)
    {
    }

    public function register(string $commandClass): void
    {
        $command = $this->container->make($commandClass);
        $this->commands[$command->name()] = $command;
        if (method_exists($command, "aliases")) {
            foreach ($command->aliases() as $alias) {
                $this->commands[(string) $alias] = $command;
            }
        }
    }

    public function run(array $argv): int
    {
        $name = $argv[1] ?? "list";
        $arguments = array_slice($argv, 2);

        if (in_array($name, ["--help", "-h"], true) || ($name === "help" && $arguments === [])) {
            $this->listCommands();
            return 0;
        }
        if ($name === "help") {
            $name = (string) array_shift($arguments);
            if ($arguments !== []) {
                fwrite(STDERR, "Usage: php fnlla help <command>" . PHP_EOL);
                return 1;
            }
            $arguments = ["--help"];
        }

        if ($name === "list") {
            $this->listCommands();

            return 0;
        }

        $command = $this->commands[$name] ?? null;

        if (!$command instanceof Command) {
            fwrite(STDERR, "Unknown command: {$name}" . PHP_EOL);
            $this->listCommands();

            return 1;
        }

        try {
            foreach ($arguments as $argument) {
                if ($argument === "--") { break; }
                if (in_array($argument, ["--help", "-h"], true)) {
                    $command->printHelp();
                    return 0;
                }
            }
            return $command->handle($arguments);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Command failed: " . $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    public function runCommand(string $name, array $arguments = []): int
    {
        $command = $this->commands[$name] ?? null;

        if (!$command instanceof Command) {
            throw new \RuntimeException("Unknown command: " . $name);
        }

        return $command->handle($arguments);
    }

    private function listCommands(): void
    {
        fwrite(STDOUT, "Available commands:" . PHP_EOL);

        ksort($this->commands);

        foreach ($this->commands as $name => $command) {
            if ($name !== $command->name()) {
                continue;
            }
            if ($command->hidden()) {
                continue;
            }

            fwrite(STDOUT, "  " . $command->name() . "  " . $command->description() . PHP_EOL);
        }
    }
}

<?php

declare(strict_types=1);

namespace Fnlla\Php\Console;

use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Database\Migrations\Migrator;
use InvalidArgumentException;
use RuntimeException;

abstract class MigrationCommand extends Command
{
    public function usage(): string
    {
        return $this->name()
            . ($this->name() === "migrate:rollback" ? " [batches | --steps=N]" : "")
            . " [--connection=NAME]"
            . ($this->name() !== "migrate:status" ? " [--force]" : "")
            . " [--help]";
    }

    /** @return array{0: Migrator, 1: int}|null */
    protected function prepare(array $arguments, bool $rollback = false, bool $mutating = true): ?array
    {
        $options = ["connection" => true];
        if ($rollback) { $options["steps"] = true; }
        if ($mutating) { $options["force"] = false; }
        $input = Input::parse($arguments, $options, $rollback ? 1 : 0);
        if ($input->option("help", false)) { $this->printHelp(); return null; }
        if ($input->arguments !== [] && $input->option("steps") !== null) {
            throw new InvalidArgumentException("Specify rollback batches once, as a positional argument or --steps.");
        }
        $steps = Input::positiveInteger((string) ($input->arguments[0] ?? $input->option("steps", "1")), "Rollback batches");
        if ($mutating && !in_array(app_environment(), ["local", "development", "testing"], true) && !$input->option("force", false)) {
            throw new RuntimeException("Non-local migration requires --force after reviewing the migration and backup plan.");
        }
        $name = $input->option("connection");
        $migrator = $name === null ? $this->container->make(Migrator::class)
            : new Migrator($this->container->make(DatabaseManager::class)->forConnection($name));
        return [$migrator, $steps];
    }
}

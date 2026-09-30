<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Support\RuntimeDoctor;

final class RuntimeDoctorCommand extends Command
{
    public function name(): string { return "runtime:doctor"; }
    public function description(): string { return "Check configured database, cache and queue readiness with isolated read-only probes."; }
    public function handle(array $arguments): int
    {
        $timeout = 2.0;
        foreach ($arguments as $argument) {
            if (!is_string($argument) || preg_match('/^--timeout=(0\.[1-9]|[1-9](?:\.[0-9])?|10(?:\.0)?)$/D', $argument, $match) !== 1) {
                $this->error("Usage: php fnlla runtime:doctor [--timeout=0.1..10]");
                return 1;
            }
            $timeout = (float) $match[1];
        }
        $report = (new RuntimeDoctor())->report($timeout);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $report["status"] === "ready" ? 0 : 1;
    }
}

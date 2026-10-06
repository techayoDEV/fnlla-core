<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Resilience\HealthChecks;

final class HealthCommand extends Command
{
    public function name(): string { return 'health'; }
    public function description(): string { return 'Report configured readiness using bounded isolated probes and safe diagnostic codes.'; }
    public function handle(array $arguments): int
    {
        if ($arguments !== [] && $arguments !== ['--verbose']) { $this->error('Usage: php fnlla health [--verbose]'); return 1; }
        $report = $this->container->make(HealthChecks::class)->report();
        $this->line(json_encode($arguments === [] ? ['status' => $report['status']] : $report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $report['status'] === 'unavailable' ? 1 : 0;
    }
}

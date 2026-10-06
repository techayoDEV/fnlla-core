<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;

final class UpCommand extends Command
{
    public function name(): string { return 'up'; }
    public function description(): string { return 'Remove the static maintenance marker.'; }
    public function handle(array $arguments): int
    {
        if ($arguments !== []) { $this->error('Usage: php fnlla up'); return 1; }
        $path = storage_path('framework/resilience/down.html');
        if (is_link($path) || (is_file($path) && !unlink($path))) { throw new \RuntimeException('Cannot remove maintenance marker.'); }
        $this->line('Static maintenance disabled.');
        return 0;
    }
}

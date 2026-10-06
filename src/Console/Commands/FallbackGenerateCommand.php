<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Resilience\PublicPageCache;
use Fnlla\Php\Resilience\StaticFallbackExporter;

final class FallbackGenerateCommand extends Command
{
    public function name(): string { return 'fallback:generate'; }
    public function description(): string { return 'Export approved public snapshots and a generic emergency page into private deployment storage.'; }
    public function handle(array $arguments): int
    {
        if ($arguments !== []) { $this->error('Usage: php fnlla fallback:generate'); return 1; }
        $manifest = (new StaticFallbackExporter($this->container->make(PublicPageCache::class)))->export(
            (array) config('resilience.page_cache.paths', []), storage_path('framework/resilience/emergency'));
        $this->line('Generated emergency page and ' . count($manifest) . ' approved public fallback(s).');
        return 0;
    }
}

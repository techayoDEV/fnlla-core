<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Resilience\StaticFallbackExporter;
use Fnlla\Php\Cache\FileCacheStore;

final class DownCommand extends Command
{
    public function name(): string { return 'down'; }
    public function description(): string { return 'Enable static 503 maintenance before application bootstrap.'; }
    public function handle(array $arguments): int
    {
        $message = 'The service is temporarily unavailable. Please try again shortly.';
        if (count($arguments) > 1 || (isset($arguments[0]) && !str_starts_with($arguments[0], '--message='))) {
            $this->error('Usage: php fnlla down [--message=TEXT]'); return 1;
        }
        if (isset($arguments[0])) { $message = substr($arguments[0], 10, 500); }
        $directory = storage_path('framework/resilience');
        new FileCacheStore($directory);
        $path = $directory . '/down.html';
        if (is_link($path)) { throw new \RuntimeException('Unsafe maintenance marker.'); }
        $temporary = tempnam($directory, '.down-');
        if ($temporary === false) { throw new \RuntimeException('Cannot stage maintenance.'); }
        try {
            if (file_put_contents($temporary, StaticFallbackExporter::emergency($message)) === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot enable maintenance.');
            }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
        $this->line('Static maintenance enabled.');
        return 0;
    }
}

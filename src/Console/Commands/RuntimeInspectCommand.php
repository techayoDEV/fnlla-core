<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Support\RuntimeInspector;

final class RuntimeInspectCommand extends Command
{
    public function name(): string
    {
        return "runtime:inspect";
    }

    public function description(): string
    {
        return "Print versioned, redacted local runtime and queue diagnostics.";
    }

    public function handle(array $arguments): int
    {
        if ($arguments !== []) {
            $this->error("Usage: php fnlla runtime:inspect");
            return 1;
        }
        $this->line(json_encode(
            $this->container->make(RuntimeInspector::class)->report(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        return 0;
    }
}

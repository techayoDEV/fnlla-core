<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Product\ProductModuleRegistry;

final class ProductModuleListCommand extends Command
{
    public function name(): string { return "module:list"; }
    public function description(): string { return "List configured Product Modules and lifecycle state."; }

    public function handle(array $arguments): int
    {
        if ($arguments !== []) {
            $this->error("Usage: php fnlla module:list");
            return 1;
        }
        $this->line(json_encode($this->container->make(ProductModuleRegistry::class)->modules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }
}

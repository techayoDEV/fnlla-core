<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Product\ProductModuleRegistry;

final class ProductModuleDisableCommand extends Command
{
    public function name(): string { return "module:disable"; }
    public function description(): string { return "Disable a Product Module without uninstalling data or assets."; }
    public function usage(): string { return "module:disable <module-id>"; }

    public function handle(array $arguments): int
    {
        if (count($arguments) !== 1 || !is_string($arguments[0]) || trim($arguments[0]) === "") {
            $this->error("Usage: php fnlla " . $this->usage());
            return 1;
        }
        $this->line(json_encode($this->container->make(ProductModuleRegistry::class)->disable($arguments[0]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }
}

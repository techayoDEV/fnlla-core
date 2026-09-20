<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Product\ProductModuleRegistry;

final class ProductModuleEnableCommand extends Command
{
    public function name(): string { return "module:enable"; }
    public function description(): string { return "Enable a Product Module and its declared dependencies."; }
    public function usage(): string { return "module:enable <module-id>"; }

    public function handle(array $arguments): int
    {
        if (count($arguments) !== 1 || !is_string($arguments[0]) || trim($arguments[0]) === "") {
            $this->error("Usage: php fnlla " . $this->usage());
            return 1;
        }
        $this->line(json_encode($this->container->make(ProductModuleRegistry::class)->enable($arguments[0]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return 0;
    }
}

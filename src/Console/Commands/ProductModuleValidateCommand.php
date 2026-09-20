<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Product\ProductModuleRegistry;

final class ProductModuleValidateCommand extends Command
{
    public function name(): string { return "module:validate"; }
    public function description(): string { return "Validate the configured Product Module registry."; }

    public function handle(array $arguments): int
    {
        if ($arguments !== []) {
            $this->error("Usage: php fnlla module:validate");
            return 1;
        }
        $report = $this->container->make(ProductModuleRegistry::class)->validationReport();
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ($report["valid"] ?? false) === true ? 0 : 1;
    }
}

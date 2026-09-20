<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Product\ProductValidator;

final class ProductValidateCommand extends Command
{
    public function name(): string
    {
        return "product:validate";
    }

    public function description(): string
    {
        return "Validate fnlla.product.v1 and optional fnlla.module.v1 declarations.";
    }

    public function usage(): string
    {
        return "product:validate <product.json> [--module=<module.json>]...";
    }

    public function handle(array $arguments): int
    {
        $productPath = null;
        $modulePaths = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, "--module=")) {
                $path = trim(substr($argument, strlen("--module=")));
                if ($path === "") {
                    $this->error("--module requires a path.");
                    return 1;
                }
                $modulePaths[] = $path;
                continue;
            }
            if (str_starts_with($argument, "-")) {
                $this->error("Unknown option: " . $argument);
                return 1;
            }
            if ($productPath !== null) {
                $this->error("Only one Product Specification path may be supplied.");
                return 1;
            }
            $productPath = $argument;
        }

        if ($productPath === null || trim($productPath) === "") {
            $this->error("Usage: php fnlla " . $this->usage());
            return 1;
        }

        $report = $this->container->make(ProductValidator::class)->validateFiles($productPath, $modulePaths);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ($report["valid"] ?? false) === true ? 0 : 1;
    }
}

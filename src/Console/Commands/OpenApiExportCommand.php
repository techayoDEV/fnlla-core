<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Routing\OpenApiExporter;
use Fnlla\Php\Support\RuntimeRouteLoader;
use Throwable;

final class OpenApiExportCommand extends Command
{
    public function name(): string { return "openapi:export"; }
    public function description(): string { return "Print OpenAPI 3.1.1 from explicit registered route contracts."; }
    public function handle(array $arguments): int
    {
        if ($arguments !== []) { $this->error("Usage: php fnlla openapi:export"); return 1; }
        try {
            $router = RuntimeRouteLoader::load($this->container);
            if ($router === null) { $this->error("Application router is not configured."); return 1; }
            $document = (new OpenApiExporter())->export($router, (array) config("openapi", []));
            $this->line(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $exception) {
            // These messages describe declarations, never configuration values.
            $this->error($exception instanceof \InvalidArgumentException ? $exception->getMessage() : "OpenAPI export failed.");
            return 1;
        }
    }
}

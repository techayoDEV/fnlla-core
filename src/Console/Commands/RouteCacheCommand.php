<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\RouteCacheCommand.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Compiles route definitions into a bootstrap cache file.
*/

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;

final class RouteCacheCommand extends Command
{
    public function name(): string
    {
        return "route:cache";
    }

    public function description(): string
    {
        return "Compile route definitions into the bootstrap cache.";
    }

    public function handle(array $arguments): int
    {
        $input = \Fnlla\Php\Console\Input::parse($arguments);
        if ($input->option("help", false)) { $this->printHelp(); return 0; }
        $path = framework_route_cache_path();

        $container = $this->container;
        $rebuildRouteCache = true;
        $router = require base_path("bootstrap/router.php");
        $routes = $router->exportCache();
        \Fnlla\Php\Support\PhpArrayCache::write($path, [
            "schema" => "fnlla.routes.v1",
            "profile" => \Fnlla\Php\Support\ProjectProfile::name(),
            "routes" => $routes,
        ]);
        $this->line("Routes cached: " . $path);

        return 0;
    }
}

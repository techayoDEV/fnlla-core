<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\RouteListCommand.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained CLI surface and scheduler-oriented console behaviour.
*/

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use Fnlla\Php\Routing\RouteDefinition;

final class RouteListCommand extends Command
{
    public function name(): string
    {
        return "route:list";
    }

    public function description(): string
    {
        return "List registered HTTP routes.";
    }

    public function handle(array $arguments): int
    {
        $container = $this->container;
        $router = require base_path("bootstrap/router.php");
        $routes = $router->getRoutes();

        foreach ($routes as $method => $items) {
            foreach ($items as $route) {
                /** @var RouteDefinition $definition */
                $definition = $route["definition"];
                $this->line(sprintf(
                    "%-7s %-30s %-24s %s",
                    $method,
                    $definition->path(),
                    $definition->routeName() ?? "-",
                    implode(",", $definition->middlewareStack())
                ));
            }
        }

        return 0;
    }
}

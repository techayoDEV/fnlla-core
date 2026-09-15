<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA ROUTING SOURCE
File: src\Routing\UrlGenerator.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained route registration, matching and URL generation behaviour.
*/

namespace Fnlla\Php\Routing;

use RuntimeException;

final class UrlGenerator
{
    public function __construct(private Router $router)
    {
    }

    public function route(string $name, array $parameters = []): string
    {
        $definition = $this->router->routeByName($name);

        if ($definition === null) {
            throw new RuntimeException("Route not found: " . $name);
        }

        $path = $definition->path();

        foreach ($parameters as $key => $value) {
            $path = str_replace("{" . $key . "}", rawurlencode((string) $value), $path);
        }

        if (preg_match('/\{[^}]+\}/', $path) === 1) {
            throw new RuntimeException("Missing parameters for route: " . $name);
        }

        $normalized = "/" . ltrim($path, "/");

        return $normalized === "/" ? "/" : $normalized;
    }
}

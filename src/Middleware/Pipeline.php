<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MIDDLEWARE SOURCE
File: src\Middleware\Pipeline.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements middleware behaviour for request hardening, policy and response shaping.
*/

namespace Fnlla\Php\Middleware;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Http\Request;
use RuntimeException;

final class Pipeline
{
    public function __construct(private Container $container)
    {
    }

    public function process(Request $request, array $middleware, callable $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (callable $next, mixed $pipe): callable {
                return function (Request $request) use ($pipe, $next): mixed {
                    $middleware = $this->resolveMiddleware($pipe);

                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($request);
    }

    private function resolveMiddleware(mixed $pipe): MiddlewareInterface
    {
        if ($pipe instanceof MiddlewareInterface) {
            return $pipe;
        }

        if (is_string($pipe)) {
            $resolved = $this->container->make($pipe);

            if ($resolved instanceof MiddlewareInterface) {
                return $resolved;
            }
        }

        throw new RuntimeException("Unable to resolve middleware pipeline entry.");
    }
}

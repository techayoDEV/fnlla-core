<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA ROUTING SOURCE
File: src\Routing\RouteGroup.php
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

final class RouteGroup
{
    public function __construct(
        public readonly string $prefix = "",
        public readonly string $namePrefix = "",
        public readonly array $middleware = []
    ) {
    }
}

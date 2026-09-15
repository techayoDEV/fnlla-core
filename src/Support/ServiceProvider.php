<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\ServiceProvider.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements shared helpers, environment loading, metadata and framework support behaviour.
*/

namespace Fnlla\Php\Support;

use Fnlla\Php\Container\Container;

abstract class ServiceProvider
{
    public function __construct(protected Container $container)
    {
    }

    abstract public function register(): void;

    public function boot(): void
    {
    }
}

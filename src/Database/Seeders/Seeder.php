<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA DATABASE SOURCE
File: src\Database\Seeders\Seeder.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained MySQL data access and migration runtime.
*/

namespace Fnlla\Php\Database\Seeders;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use RuntimeException;

abstract class Seeder
{
    public function __construct(
        protected Container $container,
        protected DatabaseManager $database
    ) {
    }

    abstract public function run(): void;

    protected function call(string $seederClass): void
    {
        $seeder = $this->container->make($seederClass);

        if (!$seeder instanceof self) {
            throw new RuntimeException("Seeder class must extend " . self::class . ": " . $seederClass);
        }

        $seeder->run();
    }
}

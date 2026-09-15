<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SERVICE PROVIDER SOURCE
File: src\Providers\AuthServiceProvider.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Registers maintained framework services and application-level boot behaviour.
*/

namespace Fnlla\Php\Providers;

use Fnlla\Php\Auth\Authorization\Gate;
use Fnlla\Php\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $gate = $this->container->make(Gate::class);

        $gate->define("view-dashboard", static fn (?array $user): bool => $user !== null);
        $gate->define("manage-admin-area", static fn (?array $user): bool => $user !== null && (($user["role"] ?? "user") === "admin"));
    }
}

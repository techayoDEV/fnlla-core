<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\Authorization\Gate.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements authentication, authorisation or access-control primitives for the framework.
*/

namespace Fnlla\Php\Auth\Authorization;

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Container\Container;
final class Gate
{
    private array $abilities = [];

    public function __construct(
        private Container $container,
        private AuthManager $auth
    ) {
    }

    public function define(string $ability, callable|array $callback): void
    {
        $this->abilities[$ability] = $callback;
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        $callback = $this->abilities[$ability] ?? null;

        if ($callback === null) {
            return false;
        }

        $user = $this->auth->user();

        return (bool) $this->container->call($callback, array_merge([
            "user" => $user,
        ], array_values($arguments)));
    }

    public function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->allows($ability, ...$arguments)) {
            throw new AuthorizationException("This action is unauthorized.");
        }
    }
}

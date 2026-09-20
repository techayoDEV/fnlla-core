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
    private array $permissionMap = [];

    public function __construct(
        private Container $container,
        private AuthManager $auth,
        private ?AccessControl $access = null
    ) {
    }

    public function define(string $ability, callable|array $callback): void
    {
        $this->abilities[$ability] = $callback;
    }

    public function mapPermission(string $ability, string $permission): void
    {
        $this->permissionMap[$ability] = $permission;
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        $callback = $this->abilities[$ability] ?? null;

        $user = $this->auth->user();

        if ($callback === null) {
            $configured = config("security.authorization.legacy_gate_permissions", []);
            $permission = $this->permissionMap[$ability]
                ?? (is_array($configured) && is_string($configured[$ability] ?? null) ? $configured[$ability] : null);
            return $permission !== null && $this->access !== null
                && $this->access->allows($permission, $arguments[0] ?? null, $user);
        }

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

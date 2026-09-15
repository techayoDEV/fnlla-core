<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\UserProviderInterface.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements authentication, authorisation or access-control primitives for the framework.
*/

namespace Fnlla\Php\Auth;

interface UserProviderInterface
{
    public function findById(string|int $id): ?array;

    public function findByCredentials(array $credentials): ?array;
}

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\DatabaseUserProvider.php
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

use Fnlla\Php\Database\DatabaseManager;

final class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(private DatabaseManager $database)
    {
    }

    public function findById(string|int $id): ?array
    {
        $table = (string) config("auth.providers.users.table", "users");
        $key = (string) config("auth.providers.users.key", "id");

        return $this->database->table($table)->where($key, $id)->first();
    }

    public function findByCredentials(array $credentials): ?array
    {
        $table = (string) config("auth.providers.users.table", "users");
        $identityField = (string) config("auth.providers.users.identity", "email");

        if (!isset($credentials[$identityField])) {
            return null;
        }

        return $this->database->table($table)->where($identityField, $credentials[$identityField])->first();
    }
}

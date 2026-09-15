<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HASHING SOURCE
File: src\Hashing\Hasher.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements password and hashing helpers for the maintained framework stack.
*/

namespace Fnlla\Php\Hashing;

final class Hasher
{
    public function make(string $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    public function check(string $value, string $hashedValue): bool
    {
        return password_verify($value, $hashedValue);
    }

    public function needsRehash(string $hashedValue): bool
    {
        return password_needs_rehash($hashedValue, PASSWORD_DEFAULT);
    }
}

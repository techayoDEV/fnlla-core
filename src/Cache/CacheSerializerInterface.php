<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\CacheSerializerInterface.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines the cache payload serialization boundary used by file-backed stores.
*/

namespace Fnlla\Php\Cache;

interface CacheSerializerInterface
{
    public function serialize(array $payload): string;

    public function unserialize(string $payload): ?array;
}

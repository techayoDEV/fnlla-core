<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\cache.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines maintained application or framework configuration for the official FNLLA stack.
*/

return [
    "default" => (string) env("CACHE_STORE", "file"),
    "serializer" => (string) env("CACHE_SERIALIZER", "json"),
    "stores" => [
        "file" => [
            "path" => storage_path("framework/cache"),
        ],
        "redis" => [
            "host" => (string) env("REDIS_HOST", "127.0.0.1"),
            "port" => (int) env("REDIS_PORT", 6379),
            "password" => (string) env("REDIS_PASSWORD", ""),
            "database" => (int) env("REDIS_CACHE_DB", 1),
            "timeout" => (float) env("REDIS_TIMEOUT", 1.5),
            "prefix" => (string) env("REDIS_CACHE_PREFIX", "fnlla:cache:"),
        ],
    ],
];

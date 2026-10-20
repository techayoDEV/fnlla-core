<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\cache.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA Core is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This file is application-owned configuration or tooling in the
generated project. Core remains an independent dependency; its attribution does
not make the application a framework maintainer workspace.

Purpose:
- Defines maintained application or framework configuration for the official FNLLA stack.
*/

return [
    "file_lock_protocol" => env("FILE_CACHE_LOCK_PROTOCOL", "striped"),
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

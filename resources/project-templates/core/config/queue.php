<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\queue.php
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
    "default" => (string) env("QUEUE_CONNECTION", "file"),
    "max_attempts" => max(1, (int) env("QUEUE_MAX_ATTEMPTS", 1)),
    "retry_backoff_seconds" => max(1, (int) env("QUEUE_RETRY_BACKOFF_SECONDS", 30)),
    "visibility_timeout_seconds" => max(1, (int) env("QUEUE_VISIBILITY_TIMEOUT_SECONDS", 300)),
    "connections" => [
        "file" => [
            "path" => (string) env("QUEUE_PATH", "framework/queue"),
        ],
        "redis" => [
            "host" => (string) env("REDIS_HOST", "127.0.0.1"),
            "port" => (int) env("REDIS_PORT", 6379),
            "password" => (string) env("REDIS_PASSWORD", ""),
            "database" => (int) env("REDIS_QUEUE_DB", 2),
            "timeout" => (float) env("REDIS_TIMEOUT", 1.5),
            "prefix" => (string) env("REDIS_QUEUE_PREFIX", "fnlla:queue:"),
        ],
    ],
];

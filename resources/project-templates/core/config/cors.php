<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\cors.php
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
    "allowed_origins" => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(",", (string) env("CORS_ALLOWED_ORIGINS", ""))
    ), static fn (string $origin): bool => $origin !== "")),
    "allowed_methods" => ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    "allowed_headers" => ["Content-Type", "Authorization", "X-Requested-With", "X-Request-Id", "X-CSRF-TOKEN"],
    "supports_credentials" => (bool) env("CORS_SUPPORTS_CREDENTIALS", false),
    "max_age" => max(0, (int) env("CORS_MAX_AGE", 3600)),
];

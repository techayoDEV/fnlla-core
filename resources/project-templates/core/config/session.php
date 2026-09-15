<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\session.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines maintained application or framework configuration for the official FNLLA stack.
*/

$environment = framework_detect_environment();
$isDevelopment = $environment === "development";
$sessionLifetimeMinutes = max(1, (int) env("SESSION_LIFETIME_MINUTES", 120));

return [
    "driver" => (string) env("SESSION_DRIVER", "file"),
    "name" => (string) env("SESSION_NAME", "fnlla_session"),
    "lifetime_minutes" => $sessionLifetimeMinutes,
    "absolute_lifetime_minutes" => max(1, (int) env("SESSION_ABSOLUTE_LIFETIME_MINUTES", 720)),
    "cookie_lifetime" => $sessionLifetimeMinutes * 60,
    "path" => (string) env("SESSION_PATH_SCOPE", "/"),
    "domain" => env("SESSION_DOMAIN"),
    "secure" => (bool) env("SESSION_SECURE", !$isDevelopment && app_request_is_secure()),
    "http_only" => (bool) env("SESSION_HTTP_ONLY", true),
    "same_site" => (string) env("SESSION_SAME_SITE", "Lax"),
    "strict_mode" => (bool) env("SESSION_STRICT_MODE", true),
    "use_only_cookies" => (bool) env("SESSION_USE_ONLY_COOKIES", true),
    "rotate_after_minutes" => max(1, (int) env("SESSION_ROTATE_AFTER_MINUTES", 30)),
    "redis" => [
        "host" => (string) env("REDIS_HOST", "127.0.0.1"),
        "port" => (int) env("REDIS_PORT", 6379),
        "password" => (string) env("REDIS_PASSWORD", ""),
        "database" => (int) env("REDIS_SESSION_DB", 3),
        "timeout" => (float) env("REDIS_TIMEOUT", 1.5),
        "prefix" => (string) env("REDIS_SESSION_PREFIX", "fnlla:session:"),
        "lock_ttl_seconds" => max(1, (int) env("REDIS_SESSION_LOCK_TTL_SECONDS", 60)),
        "lock_wait_milliseconds" => max(0, min(30000, (int) env("REDIS_SESSION_LOCK_WAIT_MILLISECONDS", 2000))),
    ],
];

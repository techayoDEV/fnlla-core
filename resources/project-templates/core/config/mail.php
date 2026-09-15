<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\mail.php
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
    "default" => (string) env("MAIL_MAILER", "log"),
    "log_path" => (string) env("MAIL_LOG_PATH", "mail"),
    "native_enabled" => (bool) env("MAIL_NATIVE_ENABLED", false),
    "max_recipients" => max(1, (int) env("MAIL_MAX_RECIPIENTS", 10)),
    "from" => [
        "address" => (string) env("MAIL_FROM_ADDRESS", "no-reply@example.com"),
        "name" => (string) env("MAIL_FROM_NAME", "FNLLA"),
    ],
    "reply_to" => [
        "address" => (string) env("MAIL_REPLY_TO_ADDRESS", ""),
    ],
    "contact_recipient" => (string) env("CONTACT_NOTIFICATION_EMAIL", ""),
    "http" => [
        "endpoint" => (string) env("MAIL_HTTP_ENDPOINT", ""),
        "token" => (string) env("MAIL_HTTP_TOKEN", ""),
        "timeout_seconds" => max(1, (int) env("MAIL_HTTP_TIMEOUT_SECONDS", 10)),
        "require_https" => (bool) env("MAIL_HTTP_REQUIRE_HTTPS", true),
        "allowed_hosts" => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(",", (string) env("MAIL_HTTP_ALLOWED_HOSTS", ""))
        ), static fn (string $host): bool => $host !== "")),
    ],
];

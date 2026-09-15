<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\database.php
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
    "default" => (string) env("DB_CONNECTION", "mysql"),
    "connections" => [
        "mysql" => [
            "driver" => "mysql",
            "host" => (string) env("DB_HOST", "127.0.0.1"),
            "port" => (string) env("DB_PORT", "3306"),
            "database" => (string) env("DB_DATABASE", "fnlla"),
            "username" => (string) env("DB_USERNAME", "root"),
            "password" => (string) env("DB_PASSWORD", ""),
            "charset" => (string) env("DB_CHARSET", "utf8mb4"),
            "persistent" => (bool) env("DB_PERSISTENT", false),
            "ssl" => [
                "enabled" => (bool) env("DB_SSL_ENABLED", false),
                "ca" => trim((string) env("DB_SSL_CA", "")),
                "cert" => trim((string) env("DB_SSL_CERT", "")),
                "key" => trim((string) env("DB_SSL_KEY", "")),
                "verify_server_cert" => (bool) env("DB_SSL_VERIFY_SERVER_CERT", true),
            ],
            "read" => [
                "host" => trim((string) env("DB_READ_HOST", "")),
                "port" => trim((string) env("DB_READ_PORT", "")),
            ],
        ],
    ],
    "migrations_table" => (string) env("DB_MIGRATIONS_TABLE", "migrations"),
    "transactional_migrations" => env("DB_TRANSACTIONAL_MIGRATIONS", null),
    "mysql_transactional_migrations" => (bool) env("DB_MYSQL_TRANSACTIONAL_MIGRATIONS", false),
];

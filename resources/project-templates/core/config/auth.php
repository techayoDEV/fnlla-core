<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\auth.php
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
    "session_key" => (string) env("AUTH_SESSION_KEY", "auth.user_id"),
    "providers" => [
        "users" => [
            "table" => (string) env("AUTH_USERS_TABLE", "users"),
            "key" => (string) env("AUTH_USERS_KEY", "id"),
            "identity" => (string) env("AUTH_USERS_IDENTITY", "email"),
            "password" => (string) env("AUTH_USERS_PASSWORD", "password"),
        ],
    ],
];

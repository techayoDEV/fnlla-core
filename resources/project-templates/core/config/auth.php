<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\auth.php
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

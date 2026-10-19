<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\rate_limit.php
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
    "max_attempts" => 60,
    "decay_minutes" => 1,
];

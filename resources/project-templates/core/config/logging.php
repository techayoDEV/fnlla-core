<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\logging.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines structured log redaction and file retention defaults.
*/

return [
    "native_errors_enabled" => (bool) env("LOG_NATIVE_ERRORS_ENABLED", false),
    "native_error_path" => env("LOG_NATIVE_ERROR_PATH", storage_path("logs/native-errors.log")),
    "redact_keys" => array_values(array_filter(array_map(
        static fn (string $key): string => strtolower(trim($key)),
        explode(",", (string) env("LOG_REDACT_KEYS", "password,pass,secret,token,authorization,cookie,set-cookie,csrf,_token,api_key"))
    ), static fn (string $key): bool => $key !== "")),
    "max_file_bytes" => max(0, (int) env("LOG_MAX_FILE_BYTES", 5242880)),
    "max_rotated_files" => max(0, (int) env("LOG_MAX_ROTATED_FILES", 5)),
];

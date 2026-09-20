<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\security.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines maintained application or framework configuration for the official FNLLA stack.
*/

$uploadMaxFileBytes = max(1, (int) env("UPLOAD_MAX_FILE_BYTES", 5242880));
$requestMaxBodyBytes = max(
    1024,
    (int) env("REQUEST_MAX_BODY_BYTES", 6291456),
    $uploadMaxFileBytes + 1048576
);

return [
    "trusted_hosts" => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(",", (string) env("TRUSTED_HOSTS", ""))
    ), static fn (string $host): bool => $host !== "")),
    "request" => [
        "max_body_bytes" => $requestMaxBodyBytes,
    ],
    "uploads" => [
        "max_file_bytes" => $uploadMaxFileBytes,
        "allowed_mime_types" => array_values(array_filter(array_map(
            static fn (string $mimeType): string => trim($mimeType),
            explode(",", (string) env("UPLOAD_ALLOWED_MIME_TYPES", "image/jpeg,image/png,image/webp,application/pdf,text/plain,text/csv,application/zip,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"))
        ), static fn (string $mimeType): bool => $mimeType !== "")),
    ],
    "csrf" => [
        "rotate_after_minutes" => max(1, (int) env("CSRF_ROTATE_AFTER_MINUTES", 120)),
    ],
    "events" => [
        "enabled" => (bool) env("SECURITY_EVENT_LOG_ENABLED", true),
        "path" => (string) env("SECURITY_EVENT_LOG_PATH", "logs/security.log"),
    ],
    "authorization" => [
        "role_field" => "role",
        "roles" => [
            "developer" => ["permissions" => ["project.view", "project.manage", "tenancy.bypass"]],
            "client" => ["permissions" => ["project.view"]],
            "sales-administrator" => ["permissions" => ["project.view", "sales.manage", "roles.assign"]],
            "admin" => ["permissions" => ["project.view", "project.manage"]],
        ],
        "assignable_roles" => ["developer", "client", "sales-administrator"],
        "legacy_gate_permissions" => [
            "view-dashboard" => "project.view",
            "manage-admin-area" => "project.manage",
        ],
    ],
    "tenancy" => [
        "mode" => (string) env("TENANCY_MODE", "none"),
        "organization_field" => "organization_id",
        "custom_field" => "tenant_id",
        "additional_field" => "tenant_ids",
    ],
    "audit" => [
        "path" => (string) env("AUDIT_LOG_PATH", "logs/audit.jsonl"),
        "allowed_fields" => ["status", "role", "owner_id", "tenant_id", "reason", "outcome"],
        "retention_days" => max(1, (int) env("AUDIT_RETENTION_DAYS", 90)),
        "maximum_entries" => max(1, (int) env("AUDIT_MAXIMUM_ENTRIES", 10000)),
    ],
];

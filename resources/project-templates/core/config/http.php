<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONFIGURATION FILE
File: config\http.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Defines maintained application or framework configuration for the official FNLLA stack.
*/

$securityPreset = (string) env("SECURITY_HEADERS_PRESET", "balanced");
$hstsDefault = $securityPreset === "strict" ? "max-age=31536000; includeSubDomains; preload" : "";
$frameAncestors = $securityPreset === "strict" ? "'none'" : "'self'";
$scriptSrc = (bool) env("CSP_NONCE_ENABLED", false) ? "'self' 'nonce-{nonce}'" : "'self' 'unsafe-inline'";

return [
    "trusted_proxies" => framework_trusted_proxies(),
    "security_headers_preset" => $securityPreset,
    "security_headers" => [
        "Content-Security-Policy" => (string) env(
            "CONTENT_SECURITY_POLICY",
            "default-src 'self'; script-src {$scriptSrc}; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors {$frameAncestors}; base-uri 'self'; form-action 'self'"
        ),
        "Strict-Transport-Security" => (string) env("STRICT_TRANSPORT_SECURITY", $hstsDefault),
        "Cross-Origin-Opener-Policy" => "same-origin",
        "Cross-Origin-Resource-Policy" => "same-origin",
        "Permissions-Policy" => "camera=(), geolocation=(), microphone=()",
        "Referrer-Policy" => "strict-origin-when-cross-origin",
        "X-Content-Type-Options" => "nosniff",
        "X-Frame-Options" => "SAMEORIGIN",
        "X-Permitted-Cross-Domain-Policies" => "none",
    ],
];

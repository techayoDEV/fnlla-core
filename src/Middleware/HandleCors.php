<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MIDDLEWARE SOURCE
File: src\Middleware\HandleCors.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements middleware behaviour for request hardening, policy and response shaping.
*/

namespace Fnlla\Php\Middleware;

use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;

final class HandleCors implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $origin = trim((string) $request->header("Origin", ""));
        $headers = $this->headersForRequest($request, $origin);
        $isPreflight = $origin !== ""
            && $request->method() === "OPTIONS"
            && trim((string) $request->header("Access-Control-Request-Method", "")) !== "";

        if ($isPreflight) {
            if ($headers === []) {
                return Response::empty(403);
            }

            return Response::empty(204, $headers);
        }

        $result = $next($request);

        if ($headers === []) {
            return $result;
        }

        if ($result instanceof Response) {
            return $result->withHeaders($headers);
        }

        if (is_array($result)) {
            return Response::json($result, 200, $headers);
        }

        if (is_string($result)) {
            return Response::html($result, 200, $headers);
        }

        if ($result === null) {
            return Response::empty(204, $headers);
        }

        return Response::json($result, 200, $headers);
    }

    private function headersForRequest(Request $request, string $origin): array
    {
        if ($origin === "") {
            return [];
        }

        $allowedOrigins = (array) config("cors.allowed_origins", ["*"]);
        $supportsCredentials = (bool) config("cors.supports_credentials", false);
        $allowsWildcard = in_array("*", $allowedOrigins, true);

        /*
        Credentialed wildcard CORS is convenient during experiments but too
        permissive for business deployments. Require explicit origins before
        reflecting Origin with Access-Control-Allow-Credentials.
        */
        if ($supportsCredentials && $allowsWildcard) {
            return [];
        }

        $isAllowedOrigin = $allowsWildcard || in_array($origin, $allowedOrigins, true);

        if (!$isAllowedOrigin) {
            return [];
        }

        $allowOrigin = $supportsCredentials || !$allowsWildcard ? $origin : "*";

        $headers = [
            "Access-Control-Allow-Origin" => $allowOrigin,
            "Access-Control-Allow-Methods" => implode(", ", (array) config("cors.allowed_methods", ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"])),
            "Access-Control-Allow-Headers" => implode(", ", (array) config("cors.allowed_headers", ["Content-Type", "Authorization", "X-Requested-With", "X-Request-Id", "X-CSRF-TOKEN"])),
            "Access-Control-Max-Age" => (string) config("cors.max_age", 3600),
        ];

        if ($allowOrigin !== "*") {
            $headers["Vary"] = "Origin";
        }

        if ($supportsCredentials) {
            $headers["Access-Control-Allow-Credentials"] = "true";
        }

        return $headers;
    }
}

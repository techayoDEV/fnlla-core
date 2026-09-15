<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MIDDLEWARE SOURCE
File: src\Middleware\VerifyCsrfToken.php
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
use Fnlla\Php\Support\SecurityEventLogger;

final class VerifyCsrfToken implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        if (in_array($request->method(), ["GET", "HEAD", "OPTIONS"], true)) {
            return $next($request);
        }

        if (verify_csrf_token((string) $request->input("_token", ""))) {
            return $next($request);
        }

        SecurityEventLogger::write("csrf_failed", [
            "method" => $request->method(),
            "path" => $request->path(),
            "ip" => $request->ip(),
        ]);

        flash_set("status", [
            "variant" => "danger",
            "title" => "Session verification failed",
            "text" => "Refresh the page and submit the form again.",
            "toast" => false,
        ]);
        regenerate_csrf_token();

        if ($request->expectsJson()) {
            return Response::json([
                "error" => "CSRF token mismatch.",
                "request_id" => $request->requestId(),
            ], 419);
        }

        $referer = (string) $request->header("Referer", "");
        $fallbackPath = $referer !== "" ? (parse_url($referer, PHP_URL_PATH) ?: "/") : "/";

        return Response::redirect(url(ltrim((string) $fallbackPath, "/")));
    }
}

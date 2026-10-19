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

        $token = $request->input("_token", "");
        if (is_string($token) && verify_csrf_token($token)) {
            return $next($request);
        }

        SecurityEventLogger::write("csrf_failed", [
            "method" => $request->method(),
            "path" => $request->path(),
            "ip" => $request->ip(),
        ]);

        // A rejected request must not rotate the valid session token. In particular,
        // stale polling tabs must not invalidate forms in other tabs.
        if ($request->expectsJson()) {
            return Response::json([
                "error" => "Your session changed. Refresh the page before submitting again.",
                "request_id" => $request->requestId(),
            ], 419, ["Cache-Control" => "private, no-store"]);
        }
        flash_set("status", [
            "variant" => "danger", "title" => "Session verification failed",
            "text" => "Refresh the page and submit the form again.", "toast" => false,
        ]);

        // Referer is untrusted navigation input, not an authorization signal.
        $referer = $request->header("Referer", "");
        $fallbackPath = "/";
        if (is_string($referer) && strlen($referer) <= 2048
            && preg_match('/[\x00-\x20\x7f\\\\]/', $referer) !== 1) {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, "/") && !str_starts_with($path, "//")) {
                $fallbackPath = $path;
            }
        }

        return Response::redirect(url(ltrim($fallbackPath, "/")))
            ->withHeader("Cache-Control", "private, no-store");
    }
}

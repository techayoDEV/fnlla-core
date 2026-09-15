<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MIDDLEWARE SOURCE
File: src\Middleware\ThrottleRequests.php
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

use Fnlla\Php\Cache\RateLimiter;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Support\SecurityEventLogger;

final class ThrottleRequests implements MiddlewareInterface
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next): mixed
    {
        $route = $request->attribute("route");
        $maxAttempts = (int) ($route?->metadata("throttle.max_attempts", config("rate_limit.max_attempts", 60)) ?? 60);
        $decayMinutes = (int) ($route?->metadata("throttle.decay_minutes", config("rate_limit.decay_minutes", 1)) ?? 1);
        $decaySeconds = max(1, $decayMinutes) * 60;
        $key = sha1($request->ip() . "|" . $request->path() . "|" . $request->method());

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);
            SecurityEventLogger::write("throttle_blocked", [
                "method" => $request->method(),
                "path" => $request->path(),
                "ip" => $request->ip(),
                "retry_after" => $retryAfter,
            ]);

            return Response::json([
                "error" => "Too Many Requests",
                "retry_after" => $retryAfter,
                "request_id" => $request->requestId(),
            ], 429, [
                "Retry-After" => (string) $retryAfter,
                "X-RateLimit-Limit" => (string) $maxAttempts,
                "X-RateLimit-Remaining" => "0",
            ]);
        }

        $attempts = $this->limiter->hit($key, $decaySeconds);
        $remaining = max(0, $maxAttempts - $attempts);
        $result = $next($request);

        if ($result instanceof Response) {
            return $result->withHeaders([
                "X-RateLimit-Limit" => (string) $maxAttempts,
                "X-RateLimit-Remaining" => (string) $remaining,
            ]);
        }

        return $result;
    }
}

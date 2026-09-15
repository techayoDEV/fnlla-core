<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MIDDLEWARE SOURCE
File: src\Middleware\EnforceTrustedHosts.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

Purpose:
- Rejects requests whose Host header is not part of the configured deployment
  boundary.
*/

namespace Fnlla\Php\Middleware;

use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Support\SecurityEventLogger;

final class EnforceTrustedHosts implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $trustedHosts = (array) config("security.trusted_hosts", []);

        if ($trustedHosts === []) {
            return $next($request);
        }

        $host = $this->normaliseHost((string) $request->header("Host", $request->server("HTTP_HOST", "")));

        if ($host === "" || !$this->isTrusted($host, $trustedHosts)) {
            SecurityEventLogger::write("trusted_host_rejected", [
                "host" => $host,
                "ip" => $request->ip(),
            ]);

            return Response::text("Untrusted host.", 400);
        }

        return $next($request);
    }

    private function isTrusted(string $host, array $trustedHosts): bool
    {
        foreach ($trustedHosts as $trustedHost) {
            if (!is_string($trustedHost) || trim($trustedHost) === "") {
                continue;
            }

            $candidate = strtolower(trim($trustedHost));

            if ($candidate === "*") {
                return true;
            }

            if (str_starts_with($candidate, "*.")) {
                $suffix = substr($candidate, 1);

                if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, ".")) {
                    return true;
                }

                continue;
            }

            if ($host === $candidate) {
                return true;
            }
        }

        return false;
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === "" || str_contains($host, "\r") || str_contains($host, "\n") || str_contains($host, "\0")) {
            return "";
        }

        if (str_starts_with($host, "[")) {
            $end = strpos($host, "]");

            return $end === false ? "" : substr($host, 0, $end + 1);
        }

        return explode(":", $host, 2)[0];
    }
}

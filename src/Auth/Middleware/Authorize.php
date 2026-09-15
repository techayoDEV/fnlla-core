<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\Middleware\Authorize.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements authentication, authorisation or access-control primitives for the framework.
*/

namespace Fnlla\Php\Auth\Middleware;

use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Auth\Authorization\Gate;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;

final class Authorize implements MiddlewareInterface
{
    public function __construct(private Gate $gate)
    {
    }

    public function handle(Request $request, callable $next): mixed
    {
        $ability = (string) $request->attribute("authorize_ability", "");

        if ($ability === "") {
            return $next($request);
        }

        try {
            $this->gate->authorize($ability, $request);
        } catch (AuthorizationException) {
            if ($request->expectsJson()) {
                return Response::json([
                    "error" => "Forbidden.",
                    "request_id" => $request->requestId(),
                ], 403);
            }

            flash_set("status", [
                "variant" => "danger",
                "title" => "Access denied",
                "text" => "You do not have permission to access this area.",
                "toast" => false,
            ]);

            return Response::redirect(route("home"));
        }

        return $next($request);
    }
}

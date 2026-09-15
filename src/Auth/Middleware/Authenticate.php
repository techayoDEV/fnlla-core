<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\Middleware\Authenticate.php
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

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;
use Fnlla\Php\Routing\Router;

final class Authenticate implements MiddlewareInterface
{
    public function __construct(private AuthManager $auth)
    {
    }

    public function handle(Request $request, callable $next): mixed
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return Response::json([
                "error" => "Unauthenticated.",
                "request_id" => $request->requestId(),
            ], 401);
        }

        flash_set("status", [
            "variant" => "warning",
            "title" => "Please sign in",
            "text" => "This area requires authentication.",
            "toast" => false,
        ]);

        $router = app(Router::class);
        $redirectRoute = $router->routeByName("login") !== null ? "login" : "home";

        return Response::redirect(route($redirectRoute));
    }
}

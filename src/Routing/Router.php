<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA ROUTING SOURCE
File: src\Routing\Router.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained route registration, matching and URL generation behaviour.
*/

namespace Fnlla\Php\Routing;

use Closure;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;
use Fnlla\Php\Middleware\Pipeline;
use Fnlla\Php\View\View;
use RuntimeException;

final class Router
{
    private array $routes = [];
    private array $staticRouteIndex = [];
    private array $middlewareAliases = [];
    private array $namedRoutes = [];
    private array $groupStack = [];

    public function __construct(private Container $container)
    {
    }

    public function get(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("GET", $path, $handler);
    }

    public function post(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("POST", $path, $handler);
    }

    public function put(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("PUT", $path, $handler);
    }

    public function patch(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("PATCH", $path, $handler);
    }

    public function delete(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("DELETE", $path, $handler);
    }

    public function options(string $path, callable|array $handler): RouteDefinition
    {
        return $this->add("OPTIONS", $path, $handler);
    }

    public function group(array $attributes, Closure $callback): void
    {
        /*
        Groups are a registration-time convenience, not a dispatch-time feature.
        Prefixes, name prefixes and middleware are flattened into each route as
        it is added. That means matching stays a simple scan over compiled route
        patterns with no nested context to rebuild during every request.
        */
        $group = new RouteGroup(
            prefix: $this->normalizeGroupPrefix((string) ($attributes["prefix"] ?? "")),
            namePrefix: (string) ($attributes["as"] ?? ""),
            middleware: $this->normalizeMiddleware($attributes["middleware"] ?? [])
        );

        $this->groupStack[] = $group;

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    public function middleware(string $alias, string $className): void
    {
        $this->middlewareAliases[$alias] = $className;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function exportCache(): array
    {
        $cached = [];

        foreach ($this->routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $route) {
                /** @var RouteDefinition $definition */
                $definition = $route["definition"];
                $handler = $definition->handler();

                if (!$this->handlerCanBeCached($handler)) {
                    throw new RuntimeException("Route cache cannot store closure or object handlers for: " . $definition->path());
                }

                $metadata = $definition->metadataAll();
                unset($metadata["router"], $metadata["name_prefix"]);

                $cached[] = [
                    "method" => $method,
                    "path" => $definition->path(),
                    "handler" => $handler,
                    "name" => $definition->routeName(),
                    "middleware" => $definition->middlewareStack(),
                    "metadata" => $metadata,
                ];
            }
        }

        return $cached;
    }

    public function loadCachedRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $handler = $route["handler"] ?? null;

            if (!$this->handlerCanBeCached($handler)) {
                throw new RuntimeException("Cached route handler is invalid.");
            }

            $definition = $this->add((string) ($route["method"] ?? "GET"), (string) ($route["path"] ?? "/"), $handler);

            foreach ((array) ($route["metadata"] ?? []) as $key => $value) {
                if (is_string($key)) {
                    $definition->setMetadata($key, $value);
                }
            }

            $middleware = (array) ($route["middleware"] ?? []);

            if ($middleware !== []) {
                $definition->middleware($middleware);
            }

            if (is_string($route["name"] ?? null) && $route["name"] !== "") {
                $definition->name((string) $route["name"]);
            }
        }
    }

    public function routeByName(string $name): ?RouteDefinition
    {
        return $this->namedRoutes[$name] ?? null;
    }

    public function add(string $method, string $path, callable|array $handler): RouteDefinition
    {
        /*
        Store two views of the same route:
        - RouteDefinition keeps the user-facing metadata used by commands,
          middleware and URL generation.
        - The compiled regex and parameter names are optimized for dispatch.
        */
        [$normalizedPath, $groupMiddleware, $groupNamePrefix] = $this->applyGroupContext($path);
        $compiled = $this->compileRoutePattern($normalizedPath);
        $definition = new RouteDefinition(strtoupper($method), $normalizedPath, $handler);
        $definition->middleware($groupMiddleware);
        $definition->setMetadata("name_prefix", $groupNamePrefix);
        $definition->setMetadata("router", $this);

        $this->routes[strtoupper($method)][] = [
            "definition" => $definition,
            "regex" => $compiled["regex"],
            "parameters" => $compiled["parameters"],
        ];

        if ($compiled["parameters"] === [] && !isset($this->staticRouteIndex[strtoupper($method)][$normalizedPath])) {
            $this->staticRouteIndex[strtoupper($method)][$normalizedPath] = array_key_last($this->routes[strtoupper($method)]);
        }

        return $definition;
    }

    public function dispatch(Request $request): mixed
    {
        unset($_SERVER["FNLLA_ROUTE_NAME"]);

        /*
        HEAD and OPTIONS are normalized in the router. Controllers can stay
        focused on application behaviour, while the framework supplies HTTP
        protocol conveniences such as GET fallback for HEAD and Allow headers
        for preflight/unsupported method discovery.
        */
        $method = $request->method();
        $path = $request->path();
        $route = $this->findRoute($method, $path);

        if ($route === null && $method === "HEAD") {
            $route = $this->findRoute("GET", $path);
        }

        if ($route === null && $method === "OPTIONS" && $this->pathExists($path)) {
            return Response::empty(204, [
                "Allow" => $this->allowedMethodsForPath($path),
            ]);
        }

        if ($route === null) {
            if ($this->pathExists($path)) {
                return Response::html(View::render("pages/error", [
                    "pageTitle" => "Method Not Allowed",
                    "headline" => "That route exists, but not for this method",
                    "message" => "Try a supported request method for this endpoint.",
                ]), 405, [
                    "Allow" => $this->allowedMethodsForPath($path),
                ]);
            }

            return Response::html(View::render("pages/not-found", [
                "pageTitle" => "Not Found",
            ]), 404);
        }

        $definition = $route["definition"];
        $_SERVER["FNLLA_ROUTE_NAME"] = (string) ($definition->routeName() ?? "");
        $resolvedRequest = $request
            ->withRouteParams($route["route_params"])
            ->withAttribute("route", $definition)
            ->withAttribute("route_name", $definition->routeName())
            ->withAttribute("authorize_ability", $definition->metadata("authorize_ability"));

        $destination = function (Request $request) use ($definition): mixed {
            return $this->container->call($definition->handler(), [
                "request" => $request,
                Request::class => $request,
                ...$request->routeParams(),
            ]);
        };

        $pipeline = new Pipeline($this->container);

        return $pipeline->process($resolvedRequest, $this->resolveMiddlewareStack($definition->middlewareStack()), $destination);
    }

    public function resolveMiddlewareStack(array $stack): array
    {
        return $this->resolveMiddleware($stack);
    }

    private function applyGroupContext(string $path): array
    {
        /* Group context is flattened into concrete path, middleware and name prefix values during registration. */
        $normalizedPath = $this->normalizePath($path);
        $middleware = [];
        $namePrefix = "";

        foreach ($this->groupStack as $group) {
            $normalizedPath = $this->mergePaths($group->prefix, $normalizedPath);
            $middleware = array_merge($middleware, $group->middleware);
            $namePrefix .= $group->namePrefix;
        }

        return [$normalizedPath, array_values(array_unique($middleware)), $namePrefix];
    }

    private function resolveMiddleware(array $stack): array
    {
        $resolved = [];

        foreach ($stack as $middleware) {
            if ($middleware instanceof MiddlewareInterface) {
                $resolved[] = $middleware;
                continue;
            }

            if (is_string($middleware) && isset($this->middlewareAliases[$middleware])) {
                $resolved[] = $this->middlewareAliases[$middleware];
                continue;
            }

            if (is_string($middleware)) {
                $resolved[] = $middleware;
                continue;
            }

            throw new RuntimeException("Invalid middleware entry on route.");
        }

        return $resolved;
    }

    private function pathExists(string $path): bool
    {
        foreach ($this->routes as $routesByMethod) {
            foreach ($routesByMethod as $route) {
                if ($this->matchesRoute($route, $path) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function allowedMethodsForPath(string $path): string
    {
        $methods = [];

        foreach ($this->routes as $method => $routesByMethod) {
            foreach ($routesByMethod as $route) {
                if ($this->matchesRoute($route, $path) !== null) {
                    $methods[] = $method;
                    if ($method === "GET") {
                        $methods[] = "HEAD";
                    }
                    break;
                }
            }
        }

        $methods[] = "OPTIONS";
        $methods = array_values(array_unique($methods));
        sort($methods);

        return implode(", ", $methods);
    }

    private function findRoute(string $method, string $path): ?array
    {
        $staticIndex = $this->staticRouteIndex[$method][$path] ?? null;

        if (is_int($staticIndex) && isset($this->routes[$method][$staticIndex])) {
            return array_merge($this->routes[$method][$staticIndex], [
                "route_params" => [],
            ]);
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            $routeParams = $this->matchesRoute($route, $path);

            if ($routeParams !== null) {
                return array_merge($route, [
                    "route_params" => $routeParams,
                ]);
            }
        }

        return null;
    }

    private function matchesRoute(array $route, string $path): ?array
    {
        if (!preg_match($route["regex"], $path, $matches)) {
            return null;
        }

        $routeParams = [];

        foreach ($route["parameters"] as $parameter) {
            if (isset($matches[$parameter])) {
                $routeParams[$parameter] = $matches[$parameter];
            }
        }

        return $routeParams;
    }

    private function compileRoutePattern(string $path): array
    {
        /*
        Route parameters are deliberately segment-scoped. A token such as
        `{page}` can match `guides`, but it cannot swallow slashes. This keeps
        route behaviour predictable and avoids ambiguous greedy patterns.
        */
        if ($path === "/") {
            return [
                "regex" => "/^\/$/",
                "parameters" => [],
            ];
        }

        $parameterNames = [];
        $segments = explode("/", trim($path, "/"));
        $compiledSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameterNames[] = $matches[1];
                $compiledSegments[] = '(?P<' . $matches[1] . '>[^\/]+)';
                continue;
            }

            $compiledSegments[] = preg_quote($segment, "/");
        }

        return [
            "regex" => "/^\/" . implode('\/', $compiledSegments) . "$/",
            "parameters" => $parameterNames,
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = "/" . trim($path, "/");

        return $normalized === "/" ? "/" : rtrim($normalized, "/");
    }

    private function normalizeGroupPrefix(string $prefix): string
    {
        if ($prefix === "") {
            return "";
        }

        return "/" . trim($prefix, "/");
    }

    private function mergePaths(string $prefix, string $path): string
    {
        if ($prefix === "") {
            return $path;
        }

        return $this->normalizePath($prefix . "/" . ltrim($path, "/"));
    }

    private function normalizeMiddleware(array|string $middleware): array
    {
        return is_array($middleware) ? $middleware : [$middleware];
    }

    private function handlerCanBeCached(mixed $handler): bool
    {
        return is_array($handler)
            && count($handler) === 2
            && is_string($handler[0] ?? null)
            && is_string($handler[1] ?? null);
    }

    public function registerNamedRoute(RouteDefinition $definition): void
    {
        $name = $definition->routeName();

        if ($name === null) {
            return;
        }

        if (isset($this->namedRoutes[$name])) {
            throw new RuntimeException("Duplicate route name: " . $name);
        }

        $this->namedRoutes[$name] = $definition;
    }
}

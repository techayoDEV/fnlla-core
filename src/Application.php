<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA APPLICATION KERNEL
File: src\Application.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Coordinates the maintained request lifecycle for the FNLLA runtime.
*/

namespace Fnlla\Php;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Exceptions\ExceptionHandler;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Http\Resources\JsonResource;
use Fnlla\Php\Http\Resources\ResourceCollection;
use Fnlla\Php\Middleware\Pipeline;
use Fnlla\Php\Http\RequestLifecycleObserver;
use Fnlla\Php\Routing\Router;
use Throwable;

final class Application
{
    /*
    Global middleware is the outer ring of the HTTP lifecycle.

    Route middleware is attached by the router after a route is matched, but
    these global entries run before the router sees the request. That is why
    CORS and maintenance mode live here: they shape the whole application edge,
    not only individual controllers.
    */
    private array $middleware = [];

    public function __construct(
        private Router $router,
        private Container $container,
        private ExceptionHandler $exceptionHandler
    )
    {
    }

    public function middleware(array|string $middleware): self
    {
        $items = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_values(array_merge($this->middleware, $items));

        return $this;
    }

    public function run(): void
    {
        try {
            $request = Request::capture();
            $response = $this->handle($request);
        } catch (Throwable $exception) {
            $request = Request::fromTrustedFallback($_SERVER);
            $this->exceptionHandler->report($exception, $request);
            $response = $this->finalizeResponse($request, $this->exceptionHandler->render($exception, $request));
        }

        $response->send();
    }

    public function handle(Request $request): Response
    {
        $startedAt = microtime(true);
        unset($_SERVER["FNLLA_ROUTE_NAME"]);
        $observer = null;
        try {
            $observer = $this->container->has(RequestLifecycleObserver::class)
                ? $this->container->make(RequestLifecycleObserver::class) : null;
            $observer?->begin($request);
        } catch (Throwable $exception) {
            $this->exceptionHandler->report($exception, $request);
        }

        try {
            try {
                // Normalize inside the boundary: resource serialization can fail too.
                $pipeline = new Pipeline($this->container);
                $result = $pipeline->process(
                    $request,
                    $this->router->resolveMiddlewareStack($this->middleware),
                    fn (Request $request): mixed => $this->router->dispatch($request)
                );
                $response = $this->normalizeResponse($result);
            } catch (Throwable $exception) {
                $this->exceptionHandler->report($exception, $request);
                $response = $this->exceptionHandler->render($exception, $request);
            }
            return $this->finalizeResponse($request, $response, $startedAt, $observer);
        } finally {
            unset($_SERVER["FNLLA_ROUTE_NAME"]);
            try {
                $observer?->reset();
            } catch (Throwable $exception) {
                $this->exceptionHandler->report($exception, $request);
            }
        }
    }

    private function normalizeResponse(mixed $result): Response
    {
        /*
        Controllers may return framework Response objects, strings, arrays,
        JSON resources or scalar values. Normalizing in one place keeps route
        handlers pleasant to write while preventing every controller from
        reimplementing content-type decisions.
        */
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if ($result instanceof JsonResource || $result instanceof ResourceCollection) {
            return Response::json($result->resolve());
        }

        if ($result === null) {
            return Response::empty();
        }

        if (is_scalar($result) || (is_object($result) && method_exists($result, "__toString"))) {
            return Response::html((string) $result);
        }

        return Response::json($result);
    }

    private function finalizeResponse(Request $request, Response $response, ?float $startedAt = null, ?RequestLifecycleObserver $observer = null): Response
    {
        /*
        Request IDs are attached at the edge, after exception rendering and
        response normalization. This guarantees successful responses and error
        responses expose the same correlation handle that was used in logs.
        */
        $final = $response->withHeader("X-Request-Id", $request->requestId());

        if ($startedAt !== null) {
            $durationMs = (microtime(true) - $startedAt) * 1000;
            try {
                $final = $observer?->finish($request, $final, $durationMs) ?? $final;
            } catch (Throwable $exception) {
                // A metrics backend failure must not invalidate a committed application response.
                $this->exceptionHandler->report($exception, $request);
            }
        }

        if ($request->method() === "HEAD") {
            return $final->withoutBody();
        }

        return $final;
    }
}

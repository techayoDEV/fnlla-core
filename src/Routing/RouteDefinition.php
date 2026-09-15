<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA ROUTING SOURCE
File: src\Routing\RouteDefinition.php
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

final class RouteDefinition
{
    private array $middleware = [];
    private ?string $name = null;
    private array $metadata = [];
    private mixed $handler;

    public function __construct(private readonly string $method, private readonly string $path, callable|array $handler)
    {
        $this->handler = $handler;
    }

    public function middleware(array|string $middleware): self
    {
        $items = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_values(array_unique(array_merge($this->middleware, $items)));

        return $this;
    }

    public function name(string $name): self
    {
        $prefix = (string) $this->metadata("name_prefix", "");
        $this->name = $prefix . $name;

        if ($router = $this->metadata("router")) {
            $router->registerNamedRoute($this);
        }

        return $this;
    }

    public function authorize(string $ability): self
    {
        $this->setMetadata("authorize_ability", $ability);
        $this->middleware("authorize");

        return $this;
    }

    public function throttle(int $maxAttempts, int $decayMinutes = 1): self
    {
        $this->setMetadata("throttle.max_attempts", max(1, $maxAttempts));
        $this->setMetadata("throttle.decay_minutes", max(1, $decayMinutes));
        $this->middleware("throttle");

        return $this;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): callable|array
    {
        return $this->handler;
    }

    public function middlewareStack(): array
    {
        return $this->middleware;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function metadataAll(): array
    {
        return $this->metadata;
    }
}

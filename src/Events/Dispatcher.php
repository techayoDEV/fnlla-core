<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA EVENT SOURCE
File: src\Events\Dispatcher.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained event dispatching layer for the framework runtime.
*/

namespace Fnlla\Php\Events;

use Fnlla\Php\Container\Container;

final class Dispatcher
{
    private array $listeners = [];

    public function __construct(private Container $container)
    {
    }

    public function listen(string $event, callable|array $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object|string $event, array $payload = []): array
    {
        $eventName = is_object($event) ? $event::class : $event;
        $results = [];

        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            $results[] = $this->container->call($listener, array_merge([
                "event" => $event,
            ], $payload));
        }

        return $results;
    }
}

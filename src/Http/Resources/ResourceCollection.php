<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HTTP SOURCE
File: src\Http\Resources\ResourceCollection.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements request, response and HTTP-facing runtime primitives.
*/

namespace Fnlla\Php\Http\Resources;

final class ResourceCollection
{
    public function __construct(
        private iterable $items,
        private string $resourceClass
    ) {
    }

    public function resolve(): array
    {
        $resolved = [];

        foreach ($this->items as $item) {
            /** @var JsonResource $resource */
            $resource = new $this->resourceClass($item);
            $resolved[] = $resource->resolve();
        }

        return $resolved;
    }
}

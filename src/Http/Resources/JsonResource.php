<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HTTP SOURCE
File: src\Http\Resources\JsonResource.php
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

abstract class JsonResource
{
    public function __construct(protected mixed $resource)
    {
    }

    public static function collection(iterable $resource): ResourceCollection
    {
        return new ResourceCollection($resource, static::class);
    }

    abstract public function toArray(): array;

    public function resolve(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\JsonCacheSerializer.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Stores file cache payloads as auditable JSON for simple scalar and array data.
*/

namespace Fnlla\Php\Cache;

use JsonException;
use RuntimeException;

final class JsonCacheSerializer implements CacheSerializerInterface
{
    public function serialize(array $payload): string
    {
        $this->assertJsonSafe($payload);

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException("Unable to encode cache payload as JSON: " . $exception->getMessage(), 0, $exception);
        }
    }

    public function unserialize(string $payload): ?array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function assertJsonSafe(mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (!is_array($value)) {
            throw new RuntimeException("JSON cache values must be arrays, scalars or null.");
        }

        foreach ($value as $item) {
            $this->assertJsonSafe($item);
        }
    }
}

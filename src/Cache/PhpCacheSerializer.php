<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CACHE SOURCE
File: src\Cache\PhpCacheSerializer.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Provides an explicit legacy PHP serializer for deployments that need the old
  file cache format during migration.
*/

namespace Fnlla\Php\Cache;

final class PhpCacheSerializer implements CacheSerializerInterface
{
    public function serialize(array $payload): string
    {
        return serialize($payload);
    }

    public function unserialize(string $payload): ?array
    {
        $decoded = @unserialize($payload, [
            "allowed_classes" => false,
        ]);

        return is_array($decoded) && !$this->containsObject($decoded) ? $decoded : null;
    }

    private function containsObject(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->containsObject($item)) {
                return true;
            }
        }

        return false;
    }
}

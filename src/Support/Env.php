<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\Env.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements shared helpers, environment loading, metadata and framework support behaviour.
*/

namespace Fnlla\Php\Support;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === "" || str_starts_with($trimmed, "#")) {
                continue;
            }

            if (str_starts_with($trimmed, "export ")) {
                $trimmed = trim(substr($trimmed, 7));
            }

            [$name, $value] = array_pad(explode("=", $trimmed, 2), 2, "");
            $name = trim($name);

            if ($name === "" || getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            $resolvedValue = self::parseValue(trim($value));
            putenv($name . "=" . $resolvedValue);
            $_ENV[$name] = $resolvedValue;
            $_SERVER[$name] = $resolvedValue;
        }
    }

    public static function parseValue(string $value): string
    {
        $firstCharacter = $value[0] ?? "";
        $lastCharacter = $value[strlen($value) - 1] ?? "";

        if (($firstCharacter === '"' && $lastCharacter === '"') || ($firstCharacter === "'" && $lastCharacter === "'")) {
            $value = substr($value, 1, -1);
        }

        return str_replace(["\n", "\r"], ["\\n", "\\r"], $value);
    }
}

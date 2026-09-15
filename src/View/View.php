<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA VIEW SOURCE
File: src\View\View.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained server-rendered view composition for the framework.
*/

namespace Fnlla\Php\View;

use RuntimeException;
use Throwable;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = "layouts/app"): string
    {
        $content = self::capture(self::resolvePath($template), $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture(self::resolvePath($layout), array_merge($data, [
            "content" => $content,
        ]));
    }

    private static function resolvePath(string $template): string
    {
        if (preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $template) !== 1) {
            throw new RuntimeException("Invalid view name.");
        }
        $path = VIEW_ROOT . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $template) . ".php";
        $resolved = realpath($path);
        $root = realpath(VIEW_ROOT);
        $prefix = (string) $root . DIRECTORY_SEPARATOR;
        if ($resolved === false || $root === false || !is_file($resolved)
            || (PHP_OS_FAMILY === "Windows"
                ? !str_starts_with(strtolower($resolved), strtolower($prefix))
                : !str_starts_with($resolved, $prefix))) {
            throw new RuntimeException("View not found: " . $template);
        }

        return $resolved;
    }

    private static function capture(string $path, array $data): string
    {
        extract($data, EXTR_SKIP);

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            require $path;

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw $exception;
        }
    }
}

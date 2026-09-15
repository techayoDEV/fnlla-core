<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA LOCALIZATION SOURCE
File: src\Localization\Translator.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements translation lookup for maintained framework views and flows.
*/

namespace Fnlla\Php\Localization;

final class Translator
{
    private array $loaded = [];

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= (string) config("app.locale", "en");
        [$file, $item] = array_pad(explode(".", $key, 2), 2, null);

        if ($file === null || $item === null) {
            return $key;
        }

        $lines = $this->loadLocaleFile($locale, $file);
        $value = $lines[$item] ?? null;

        if (!is_string($value)) {
            $fallback = (string) config("app.fallback_locale", "en");

            if ($fallback !== $locale) {
                return $this->get($key, $replace, $fallback);
            }

            return $key;
        }

        foreach ($replace as $name => $replacement) {
            $value = str_replace(":" . $name, (string) $replacement, $value);
        }

        return $value;
    }

    private function loadLocaleFile(string $locale, string $file): array
    {
        $cacheKey = $locale . "." . $file;

        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $path = base_path("lang/" . $locale . "/" . $file . ".php");

        if (!is_file($path)) {
            return $this->loaded[$cacheKey] = [];
        }

        $loaded = require $path;

        return $this->loaded[$cacheKey] = is_array($loaded) ? $loaded : [];
    }
}

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONSOLE SOURCE
File: src\Console\Commands\ConfigCacheCommand.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Compiles framework configuration into a single bootstrap cache file.
*/

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\Command;
use RuntimeException;

final class ConfigCacheCommand extends Command
{
    public function name(): string
    {
        return "config:cache";
    }

    public function description(): string
    {
        return "Compile configuration into the bootstrap cache.";
    }

    public function handle(array $arguments): int
    {
        $input = \Fnlla\Php\Console\Input::parse($arguments);
        if ($input->option("help", false)) { $this->printHelp(); return 0; }
        $path = framework_config_cache_path();

        $config = $this->loadFreshConfig(base_path("config"));
        \Fnlla\Php\Support\PhpArrayCache::write($path, $config);
        $this->line("Configuration cached: " . $path);

        return 0;
    }

    private function loadFreshConfig(string $directory): array
    {
        $config = [];
        $files = glob(rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . "*.php");

        if ($files === false) {
            return $config;
        }

        sort($files);

        foreach ($files as $file) {
            $loaded = require $file;

            if (!is_array($loaded)) {
                throw new RuntimeException("Config file must return an array: " . $file);
            }

            $config[pathinfo($file, PATHINFO_FILENAME)] = $loaded;
        }

        return $config;
    }

}

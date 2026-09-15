<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA FILESYSTEM SOURCE
File: src\Filesystem\StorageManager.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained file storage behaviour for uploads and local runtime assets.
*/

namespace Fnlla\Php\Filesystem;

use RuntimeException;

final class StorageManager
{
    private array $disks = [];

    public function disk(?string $name = null): FilesystemAdapter
    {
        $name ??= (string) config("filesystems.default", "local");

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $config = config("filesystems.disks." . $name, []);

        if (!is_array($config)) {
            throw new RuntimeException("Filesystem disk configuration is invalid: " . $name);
        }

        return $this->disks[$name] = new FilesystemAdapter(
            (string) ($config["root"] ?? storage_path("app")),
            (string) ($config["url"] ?? "")
        );
    }
}

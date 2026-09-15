<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MAINTAINER SCRIPT
File: scripts\lint.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Supports framework maintenance, validation, release hygiene or repository hardening.
*/

$root = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$rootFiles = [
    "fnlla",
];
$paths = [
    "app",
    "packages",
    "bootstrap",
    "config",
    "database",
    "lang",
    "public",
    "routes",
    "scripts",
    "src",
    "tests",
    "views",
];

$errors = [];

foreach ($rootFiles as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;

    if (!is_file($absolutePath)) {
        continue;
    }

    passthru(escapeshellarg($phpBinary) . " -l " . escapeshellarg($absolutePath), $exitCode);

    if ($exitCode !== 0) {
        $errors[] = $absolutePath;
    }
}

foreach ($paths as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;

    if (!is_dir($absolutePath)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolutePath));

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== "php") {
            continue;
        }

        $filePath = $fileInfo->getPathname();

        if (str_contains($filePath, DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR)
            || str_contains($filePath, DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR)) {
            continue;
        }

        passthru(escapeshellarg($phpBinary) . " -l " . escapeshellarg($filePath), $exitCode);

        if ($exitCode !== 0) {
            $errors[] = $filePath;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, PHP_EOL . "Lint failed for " . count($errors) . " file(s)." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, PHP_EOL . "Lint passed." . PHP_EOL);

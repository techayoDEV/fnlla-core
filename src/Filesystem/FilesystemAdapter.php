<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA FILESYSTEM SOURCE
File: src\Filesystem\FilesystemAdapter.php
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

use Fnlla\Php\Http\UploadedFile;
use RuntimeException;

final class FilesystemAdapter
{
    public function __construct(
        private string $root,
        private string $baseUrl = ""
    ) {
        $this->root = rtrim($this->normalizeAbsolutePath($this->root), "\\/");

        if (!is_dir($this->root) && !mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new RuntimeException("Unable to create filesystem disk root.");
        }
        $canonicalRoot = realpath($this->root);
        if ($canonicalRoot === false) {
            throw new RuntimeException("Unable to resolve filesystem disk root.");
        }
        $this->root = rtrim($canonicalRoot, "\\/");
    }

    public function put(string $path, string $contents): bool
    {
        $resolved = $this->path($path);
        $directory = dirname($resolved);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $resolved = $this->path($path);
        return file_put_contents($resolved, $contents, LOCK_EX) !== false;
    }

    public function putFile(string $directory, UploadedFile $file, ?string $name = null): string
    {
        $file->validate();
        $generatedName = $file->hashName();
        $filename = $name ?? $generatedName;
        if ($name !== null && (preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9]+\z/D', $name) !== 1
            || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== pathinfo($generatedName, PATHINFO_EXTENSION))) {
            throw new RuntimeException("Uploaded file name must use its validated storage extension.");
        }
        $relativePath = trim($directory, "\\/") . "/" . $filename;
        $resolved = $this->path($relativePath);
        $targetDirectory = dirname($resolved);

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0777, true);
        }

        $resolved = $this->path($relativePath);
        if (!$file->move($resolved)) {
            throw new RuntimeException("Unable to move uploaded file.");
        }

        return str_replace("\\", "/", trim($relativePath, "\\/"));
    }

    public function delete(string $path): bool
    {
        $resolved = $this->path($path);

        return !is_file($resolved) || unlink($resolved);
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function url(string $path): string
    {
        $normalized = str_replace("\\", "/", ltrim($path, "\\/"));

        if ($this->baseUrl !== "") {
            return rtrim($this->baseUrl, "/") . "/" . $normalized;
        }

        return url($normalized);
    }

    public function path(string $path): string
    {
        $relativePath = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, ltrim($path, "\\/"));
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }

            if ($segment === "..") {
                throw new RuntimeException("Filesystem path cannot traverse outside the disk root.");
            }

            $segments[] = $segment;
        }

        $resolved = $this->root . ($segments !== [] ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments) : "");
        $normalized = $this->normalizeAbsolutePath($resolved);
        $rootPrefix = $this->root . DIRECTORY_SEPARATOR;

        if ($normalized !== $this->root && !str_starts_with($normalized, $rootPrefix)) {
            throw new RuntimeException("Filesystem path resolves outside the disk root.");
        }

        $cursor = $this->root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException("Filesystem path cannot traverse a symbolic link.");
            }
            if (file_exists($cursor)) {
                $canonical = realpath($cursor);
                if ($canonical === false || ($canonical !== $this->root
                    && !str_starts_with($canonical, $rootPrefix))) {
                    throw new RuntimeException("Filesystem path resolves outside the disk root.");
                }
            }
        }

        return $normalized;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $normalized = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path);

        if (preg_match('/^[A-Za-z]:/', $normalized) !== 1 && !str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            $normalized = getcwd() . DIRECTORY_SEPARATOR . $normalized;
        }

        $parts = [];

        foreach (explode(DIRECTORY_SEPARATOR, $normalized) as $part) {
            if ($part === "" || $part === ".") {
                continue;
            }

            if ($part === "..") {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        if (DIRECTORY_SEPARATOR === "\\" && isset($parts[0]) && preg_match('/^[A-Za-z]:$/', $parts[0]) === 1) {
            $drive = array_shift($parts);

            return $drive . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }
}

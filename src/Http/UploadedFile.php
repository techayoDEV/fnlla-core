<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HTTP SOURCE
File: src\Http\UploadedFile.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements request, response and HTTP-facing runtime primitives.
*/

namespace Fnlla\Php\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        private string $tmpName,
        private string $originalName,
        private string $mimeType,
        private int $size,
        private int $error
    ) {
    }

    public static function fromArray(array $file): self
    {
        return new self(
            (string) ($file["tmp_name"] ?? ""),
            (string) ($file["name"] ?? ""),
            (string) ($file["type"] ?? "application/octet-stream"),
            (int) ($file["size"] ?? 0),
            (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE)
        );
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->tmpName);
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function validate(?int $maxBytes = null, ?array $allowedMimeTypes = null): void
    {
        if (!$this->isValid()) {
            throw new RuntimeException("Uploaded file is not valid.");
        }

        $maxBytes ??= max(1, (int) config("security.uploads.max_file_bytes", 5242880));
        $allowedMimeTypes ??= (array) config("security.uploads.allowed_mime_types", []);

        if ($this->size > $maxBytes) {
            throw new RuntimeException("Uploaded file exceeds the configured size limit.");
        }

        if ($allowedMimeTypes !== [] && !in_array($this->detectedMimeType(), $allowedMimeTypes, true)) {
            throw new RuntimeException("Uploaded file type is not allowed.");
        }
    }

    public function hashName(): string
    {
        $extension = $this->extension();
        $suffix = $extension !== "" ? "." . $extension : "";

        return sha1($this->originalName . "|" . $this->tmpName . "|" . microtime(true)) . $suffix;
    }

    public function move(string $targetPath): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (function_exists("move_uploaded_file") && @move_uploaded_file($this->tmpName, $targetPath)) {
            return true;
        }

        return rename($this->tmpName, $targetPath) || copy($this->tmpName, $targetPath);
    }

    public function store(string $directory, string $disk = "public"): string
    {
        $this->validate();

        return app(\Fnlla\Php\Filesystem\StorageManager::class)->disk($disk)->putFile($directory, $this);
    }

    public function detectedMimeType(): string
    {
        if (!class_exists(\finfo::class)) {
            throw new RuntimeException("Upload MIME validation requires the ext-fileinfo PHP extension.");
        }
        if (is_file($this->tmpName)) {
            // Inspect bytes, not the client-supplied MIME header. The object owns its lifetime.
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($this->tmpName);

            if (is_string($detected) && $detected !== "") {
                return $detected;
            }
        }

        // Client metadata is available through mimeType(), never as validation evidence.
        throw new RuntimeException("Cannot determine the uploaded file MIME type.");
    }
}

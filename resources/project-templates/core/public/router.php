<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA PUBLIC ENTRYPOINT
File: public\router.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Handles a public web request or static file routing boundary for the maintained framework.
*/

$requestUri = $_SERVER["REQUEST_URI"] ?? "/";
$requestPath = rawurldecode(parse_url($requestUri, PHP_URL_PATH) ?: "/");
$normalizedPath = str_replace("\\", "/", $requestPath);
$trimmedPath = trim($normalizedPath, "/");
$pathSegments = $trimmedPath === "" ? [] : array_values(array_filter(explode("/", $trimmedPath), static fn (string $segment): bool => $segment !== ""));

foreach ($pathSegments as $segment) {
    if (
        $segment === "."
        || $segment === ".."
        || str_contains($segment, "\0")
        || str_contains($segment, ":")
        || (str_starts_with($segment, ".") && $segment !== ".well-known")
    ) {
        http_response_code(404);
        header("Content-Type: text/plain; charset=UTF-8");
        echo "Not Found";

        return true;
    }
}

$publicFile = __DIR__ . ($pathSegments !== [] ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $pathSegments) : "");

if ($pathSegments !== [] && is_file($publicFile)) {
    /*
    The built-in server delegates static files when this script returns false.
    Resolve the candidate first so encoded traversal, symlink escapes and
    Windows alternate-data-stream syntax cannot cross the public document root.
    */
    $publicRoot = realpath(__DIR__);
    $resolvedFile = realpath($publicFile);

    if (!is_string($publicRoot) || !is_string($resolvedFile) || strncmp($resolvedFile, $publicRoot . DIRECTORY_SEPARATOR, strlen($publicRoot) + 1) !== 0) {
        http_response_code(404);
        header("Content-Type: text/plain; charset=UTF-8");
        echo "Not Found";

        return true;
    }

    return false;
}

require __DIR__ . DIRECTORY_SEPARATOR . "index.php";

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MAINTAINER SCRIPT
File: scripts\static-analysis.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Provides a dependency-light static analysis profile and delegates to PHPStan
  or Psalm when maintainers install them locally.
*/

define("FNLLA_RUNTIME_SKIP_AUTO_GUARD", true);

$root = dirname(__DIR__);
$phpstan = $root . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === "\\" ? "phpstan.bat" : "phpstan");
$psalm = $root . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === "\\" ? "psalm.bat" : "psalm");

if (is_file($phpstan)) {
    passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($root . "/vendor/phpstan/phpstan/phpstan") . " analyse --configuration=" . escapeshellarg($root . "/phpstan.neon") . " --no-progress --memory-limit=1G", $exitCode);
    exit((int) $exitCode);
}

if (is_file($psalm)) {
    passthru(escapeshellarg($psalm), $exitCode);
    exit((int) $exitCode);
}

$errors = [];
$files = [];
foreach (["src", "app", "packages"] as $sourceDirectory) {
    if (is_dir($root . "/" . $sourceDirectory)) {
        $files = array_merge($files, php_source_files($root . "/" . $sourceDirectory));
    }
}

foreach ($files as $file) {
    $contents = file_get_contents($file);

    if (!is_string($contents)) {
        continue;
    }

    if (!str_contains($contents, "declare(strict_types=1);")) {
        $errors[] = $file . " is missing strict_types.";
    }

    if (preg_match('/\b(var_dump|print_r|dd)\s*\(/', $contents) === 1) {
        $errors[] = $file . " contains debug output helpers.";
    }

    foreach (missing_declared_returns($file, $contents) as $error) {
        $errors[] = $error;
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }

    exit(1);
}

fwrite(STDOUT, "Static analysis baseline passed. Install PHPStan or Psalm locally for deeper checks." . PHP_EOL);

/**
 * @return list<string>
 */
function php_source_files(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getExtension() === "php") {
            $files[] = $item->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function missing_declared_returns(string $file, string $contents): array
{
    $tokens = token_get_all($contents);
    $errors = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

        $nameIndex = named_function_token_index($tokens, $index + 1);

        if ($nameIndex === null) {
            continue;
        }

        $bodyStart = function_body_start($tokens, $nameIndex + 1);

        if ($bodyStart === null) {
            continue;
        }

        $returnType = declared_return_type($tokens, $nameIndex + 1, $bodyStart);

        if ($returnType === null || in_array(strtolower($returnType), ["void", "never"], true)) {
            continue;
        }

        $bodyEnd = function_body_end($tokens, $bodyStart);

        if ($bodyEnd === null) {
            continue;
        }

        if (!function_body_has_return($tokens, $bodyStart, $bodyEnd) && !function_body_has_throw($tokens, $bodyStart, $bodyEnd)) {
            $line = is_array($tokens[$nameIndex]) ? $tokens[$nameIndex][2] : 0;
            $name = is_array($tokens[$nameIndex]) ? $tokens[$nameIndex][1] : "unknown";
            $errors[] = $file . ":" . $line . " declares return type '" . $returnType . "' but has no return statement in " . $name . "().";
        }
    }

    return $errors;
}

function named_function_token_index(array $tokens, int $index): ?int
{
    $count = count($tokens);

    for ($cursor = $index; $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($token === "&") {
            continue;
        }

        return is_array($token) && $token[0] === T_STRING ? $cursor : null;
    }

    return null;
}

function function_body_start(array $tokens, int $index): ?int
{
    $count = count($tokens);
    $parameterDepth = 0;

    for ($cursor = $index; $cursor < $count; $cursor++) {
        if ($tokens[$cursor] === "(") {
            $parameterDepth++;
            continue;
        }

        if ($tokens[$cursor] === ")") {
            $parameterDepth = max(0, $parameterDepth - 1);
            continue;
        }

        if ($parameterDepth === 0 && $tokens[$cursor] === "{") {
            return $cursor;
        }

        if ($parameterDepth === 0 && $tokens[$cursor] === ";") {
            return null;
        }
    }

    return null;
}

function declared_return_type(array $tokens, int $index, int $bodyStart): ?string
{
    $parametersEnd = null;
    $depth = 0;

    for ($cursor = $index; $cursor < $bodyStart; $cursor++) {
        if ($tokens[$cursor] === "(") {
            $depth++;
            continue;
        }

        if ($tokens[$cursor] === ")") {
            $depth--;

            if ($depth === 0) {
                $parametersEnd = $cursor;
                break;
            }
        }
    }

    if ($parametersEnd === null) {
        return null;
    }

    $colonIndex = null;

    for ($cursor = $parametersEnd + 1; $cursor < $bodyStart; $cursor++) {
        if ($tokens[$cursor] === ":") {
            $colonIndex = $cursor;
            break;
        }
    }

    if ($colonIndex === null) {
        return null;
    }

    $parts = [];

    for ($cursor = $colonIndex + 1; $cursor < $bodyStart; $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $parts[] = $token[1];
            continue;
        }

        $parts[] = $token;
    }

    $returnType = trim(implode("", $parts));

    return $returnType !== "" ? ltrim($returnType, "?") : null;
}

function function_body_end(array $tokens, int $bodyStart): ?int
{
    $depth = 0;
    $count = count($tokens);

    for ($cursor = $bodyStart; $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
            continue;
        }

        if ($token === "{") {
            $depth++;
            continue;
        }

        if ($token === "}") {
            $depth--;

            if ($depth <= 0) {
                return $cursor;
            }

            continue;
        }
    }

    return null;
}

function function_body_has_return(array $tokens, int $bodyStart, int $bodyEnd): bool
{
    for ($cursor = $bodyStart + 1; $cursor < $bodyEnd; $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && $token[0] === T_RETURN) {
            return true;
        }
    }

    return false;
}

function function_body_has_throw(array $tokens, int $bodyStart, int $bodyEnd): bool
{
    for ($cursor = $bodyStart + 1; $cursor < $bodyEnd; $cursor++) {
        $token = $tokens[$cursor];

        if (is_array($token) && $token[0] === T_THROW) {
            return true;
        }
    }

    return false;
}

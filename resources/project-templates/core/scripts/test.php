<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA MAINTAINER SCRIPT
File: scripts\test.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Supports framework maintenance, validation, release hygiene or repository hardening.
*/

define("FNLLA_RUNTIME_SKIP_AUTO_GUARD", true);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . "tests" . DIRECTORY_SEPARATOR . "bootstrap.php";
require dirname(__DIR__) . DIRECTORY_SEPARATOR . "tests" . DIRECTORY_SEPARATOR . "PHPUnit" . DIRECTORY_SEPARATOR . "Framework" . DIRECTORY_SEPARATOR . "TestCase.php";

use PHPUnit\Framework\TestCase;

/*
Local harness note:
- FNLLA keeps a repository-local test runner so routine framework work does
  not depend on Packagist or an external PHPUnit install
- the namespace-compatible TestCase shim under `tests/PHPUnit/Framework/`
  preserves a familiar test authoring surface for maintainers
*/
$suite = "all";
$filter = "";

foreach (array_slice($_SERVER["argv"] ?? [], 1) as $index => $argument) {
    if ($argument === "--help" || $argument === "-h") {
        fwrite(STDOUT, "Usage: php scripts/test.php [--suite fast|slow|all] [--filter <pattern>]" . PHP_EOL);
        fwrite(STDOUT, "Suites:" . PHP_EOL);
        fwrite(STDOUT, "  fast  Framework tests that avoid full project export copies." . PHP_EOL);
        fwrite(STDOUT, "  slow  Export and framework-update regression tests." . PHP_EOL);
        fwrite(STDOUT, "  all   Every test file. This is the default for backwards compatibility." . PHP_EOL);
        exit(0);
    }

    if ($argument === "--suite") {
        $suite = (string) (($_SERVER["argv"] ?? [])[$index + 2] ?? "all");
        continue;
    }

    if (str_starts_with($argument, "--suite=")) {
        $suite = substr($argument, 8);
    }

    if ($argument === "--filter") {
        $filter = (string) (($_SERVER["argv"] ?? [])[$index + 2] ?? "");
        continue;
    }

    if (str_starts_with($argument, "--filter=")) {
        $filter = substr($argument, 9);
    }
}

$suite = strtolower(trim($suite));
$slowTestFiles = [
    "FnllaRuntimeSyncCommandTest.php",
    "FrameworkUpdateCommandTest.php",
    "MakeProjectCommandTest.php",
];

if (!in_array($suite, ["fast", "slow", "all"], true)) {
    fwrite(STDERR, "Unknown test suite: " . $suite . PHP_EOL);
    fwrite(STDERR, "Use --suite fast, --suite slow or --suite all." . PHP_EOL);
    exit(1);
}

$testFiles = glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . "tests" . DIRECTORY_SEPARATOR . "*Test.php");

if ($testFiles === false) {
    fwrite(STDERR, "Unable to discover tests." . PHP_EOL);
    exit(1);
}

sort($testFiles);

$testFiles = array_values(array_filter(
    $testFiles,
    static function (string $file) use ($suite, $slowTestFiles): bool {
        $isSlow = in_array(basename($file), $slowTestFiles, true);

        return match ($suite) {
            "fast" => !$isSlow,
            "slow" => $isSlow,
            default => true,
        };
    }
));

TestCase::resetAssertionCount();

$results = [];
$totalTests = 0;

foreach ($testFiles as $file) {
    /* Load each test file once, then discover only the classes introduced by that file. */
    $beforeClasses = get_declared_classes();
    require_once $file;
    $afterClasses = get_declared_classes();
    $newClasses = array_values(array_diff($afterClasses, $beforeClasses));

    foreach ($newClasses as $className) {
        if (!is_subclass_of($className, TestCase::class)) {
            continue;
        }

        $reflection = new ReflectionClass($className);

        if ($reflection->isAbstract()) {
            continue;
        }

        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), "test") && $method->getNumberOfRequiredParameters() === 0
        );

        usort($methods, static fn (ReflectionMethod $left, ReflectionMethod $right): int => strcmp($left->getName(), $right->getName()));

        foreach ($methods as $method) {
            $totalTests++;
            $instance = $reflection->newInstance();
            $testName = $reflection->getShortName() . "::" . $method->getName();

            if ($filter !== "" && stripos($testName, $filter) === false && stripos($className, $filter) === false) {
                $totalTests--;
                continue;
            }

            try {
                $instance->runTestMethod($method->getName());
                $results[] = [
                    "name" => $testName,
                    "status" => "passed",
                ];
            } catch (Throwable $exception) {
                $results[] = [
                    "name" => $testName,
                    "status" => "failed",
                    "message" => $exception->getMessage(),
                    "file" => $exception->getFile(),
                    "line" => $exception->getLine(),
                ];
            }
        }
    }
}

$failed = array_values(array_filter($results, static fn (array $result): bool => $result["status"] === "failed"));

foreach ($results as $result) {
    $symbol = $result["status"] === "passed" ? "PASS" : "FAIL";
    fwrite(STDOUT, sprintf("[%s] %s", $symbol, $result["name"]) . PHP_EOL);

    if ($result["status"] === "failed") {
        fwrite(STDOUT, "       " . $result["message"] . PHP_EOL);
        fwrite(STDOUT, "       " . $result["file"] . ":" . $result["line"] . PHP_EOL);
    }
}

fwrite(STDOUT, PHP_EOL);

if ($failed !== []) {
    fwrite(STDOUT, sprintf("FAILED (%d tests, %d assertions, %d failures)", $totalTests, TestCase::assertionCount(), count($failed)) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("OK (%d tests, %d assertions)", $totalTests, TestCase::assertionCount()) . PHP_EOL);

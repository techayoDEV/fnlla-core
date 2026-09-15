<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA TEST HARNESS SOURCE
File: tests\PHPUnit\Framework\TestCase.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the repository-local test harness used by FNLLA without Packagist dependencies.
*/

namespace PHPUnit\Framework;

use ReflectionMethod;
use RuntimeException;
use Throwable;

abstract class TestCase
{
    private static int $assertionCount = 0;
    private ?string $expectedException = null;
    private ?string $expectedExceptionMessage = null;

    public static function assertionCount(): int
    {
        return self::$assertionCount;
    }

    public static function resetAssertionCount(): void
    {
        self::$assertionCount = 0;
    }

    public function expectException(string $exceptionClass): void
    {
        $this->expectedException = $exceptionClass;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    public function runTestMethod(string $method): void
    {
        $this->expectedException = null;
        $this->expectedExceptionMessage = null;

        try {
            $this->setUp();
            $caught = null;
            try {
                (new ReflectionMethod($this, $method))->invoke($this);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            // Evaluate outside the catch: a failed expectation is not the expected exception.
            $expectsException = $this->expectedException !== null || $this->expectedExceptionMessage !== null;
            if ($caught === null && $expectsException) {
                self::fail("Expected exception was not thrown.");
            }
            if ($caught !== null) {
                if (!$expectsException || ($this->expectedException !== null && !is_a($caught, $this->expectedException))) {
                    throw $caught;
                }
                if ($this->expectedExceptionMessage !== null) {
                    self::assertStringContainsString($this->expectedExceptionMessage, $caught->getMessage());
                }
                if ($this->expectedException !== null) {
                    self::incrementAssertions();
                }
            }
        } finally {
            $this->tearDown();
        }
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ""): void
    {
        self::incrementAssertions();

        if ($expected !== $actual) {
            self::fail($message !== "" ? $message : "Failed asserting that two values are the same. Expected " . self::export($expected) . " got " . self::export($actual) . ".");
        }
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ""): void
    {
        self::incrementAssertions();

        if ($expected === $actual) {
            self::fail($message !== "" ? $message : "Failed asserting that two values are not the same.");
        }
    }

    public static function assertNull(mixed $actual, string $message = ""): void
    {
        self::assertSame(null, $actual, $message);
    }

    public static function assertContains(mixed $needle, iterable $haystack, string $message = ""): void
    {
        self::incrementAssertions();
        foreach ($haystack as $value) {
            if ($value === $needle) { return; }
        }
        self::fail($message !== "" ? $message : "Expected item was not found.");
    }

    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ""): void
    {
        self::incrementAssertions();
        foreach ($haystack as $value) {
            if ($value === $needle) {
                self::fail($message !== "" ? $message : "Unexpected item was found.");
            }
        }
    }

    public static function assertTrue(bool $condition, string $message = ""): void
    {
        self::incrementAssertions();

        if ($condition !== true) {
            self::fail($message !== "" ? $message : "Failed asserting that condition is true.");
        }
    }

    public static function assertFalse(bool $condition, string $message = ""): void
    {
        self::incrementAssertions();

        if ($condition !== false) {
            self::fail($message !== "" ? $message : "Failed asserting that condition is false.");
        }
    }

    public static function assertArrayHasKey(string|int $key, array $array, string $message = ""): void
    {
        self::incrementAssertions();

        if (!array_key_exists($key, $array)) {
            self::fail($message !== "" ? $message : "Failed asserting that array has key " . self::export($key) . ".");
        }
    }

    public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ""): void
    {
        self::incrementAssertions();

        if (array_key_exists($key, $array)) {
            self::fail($message !== "" ? $message : "Failed asserting that array does not have key " . self::export($key) . ".");
        }
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ""): void
    {
        self::incrementAssertions();

        if (!str_contains($haystack, $needle)) {
            self::fail($message !== "" ? $message : "Failed asserting that string contains " . self::export($needle) . ".");
        }
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ""): void
    {
        self::incrementAssertions();

        if (str_contains($haystack, $needle)) {
            self::fail($message !== "" ? $message : "Failed asserting that string does not contain " . self::export($needle) . ".");
        }
    }

    public static function assertStringStartsWith(string $prefix, string $string, string $message = ""): void
    {
        self::incrementAssertions();

        if (!str_starts_with($string, $prefix)) {
            self::fail($message !== "" ? $message : "Failed asserting that string starts with " . self::export($prefix) . ".");
        }
    }

    public static function assertFileExists(string $path, string $message = ""): void
    {
        self::incrementAssertions();

        if (!is_file($path)) {
            self::fail($message !== "" ? $message : "Failed asserting that file exists: {$path}");
        }
    }

    public static function assertInstanceOf(string $expectedClass, mixed $actual, string $message = ""): void
    {
        self::incrementAssertions();

        if (!$actual instanceof $expectedClass) {
            self::fail($message !== "" ? $message : "Failed asserting that value is instance of {$expectedClass}.");
        }
    }

    public static function assertFileDoesNotExist(string $path, string $message = ""): void
    {
        self::assertFalse(is_file($path), $message !== "" ? $message : "File unexpectedly exists: {$path}");
    }

    public static function assertIsString(mixed $value, string $message = ""): void
    {
        self::incrementAssertions();

        if (!is_string($value)) {
            self::fail($message !== "" ? $message : "Failed asserting that value is a string.");
        }
    }

    public static function assertIsArray(mixed $value, string $message = ""): void
    {
        self::incrementAssertions();

        if (!is_array($value)) {
            self::fail($message !== "" ? $message : "Failed asserting that value is an array.");
        }
    }

    public static function fail(string $message): never
    {
        throw new RuntimeException($message);
    }

    private static function incrementAssertions(): void
    {
        self::$assertionCount++;
    }

    private static function export(mixed $value): string
    {
        return var_export($value, true);
    }
}

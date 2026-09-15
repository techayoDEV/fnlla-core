<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SESSION SOURCE
File: src\Session\SessionStore.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained session storage behaviour for the framework runtime.
*/

namespace Fnlla\Php\Session;

final class SessionStore
{
    public function has(string $key): bool
    {
        framework_start_session_if_needed();

        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        framework_start_session_if_needed();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        framework_start_session_if_needed();

        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        framework_start_session_if_needed();

        unset($_SESSION[$key]);
    }

    public function invalidate(): void
    {
        framework_start_session_if_needed();
        $_SESSION = [];

        $this->regenerate();
    }

    public function regenerate(): void
    {
        framework_start_session_if_needed();

        if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
            throw new \RuntimeException("Session identifier could not be rotated.");
        }
    }
}

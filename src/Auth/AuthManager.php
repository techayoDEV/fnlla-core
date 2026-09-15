<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA AUTHENTICATION SOURCE
File: src\Auth\AuthManager.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements authentication, authorisation or access-control primitives for the framework.
*/

namespace Fnlla\Php\Auth;

use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Session\SessionStore;

final class AuthManager
{
    public function __construct(
        private SessionStore $session,
        private UserProviderInterface $provider,
        private Hasher $hasher
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): string|int|null
    {
        $key = (string) config("auth.session_key", "auth.user_id");
        $id = $this->session->get($key);
        if ($id !== null && !self::validId($id)) {
            $this->session->forget($key);
            return null;
        }
        return $id;
    }

    public function user(): ?array
    {
        $id = $this->id();

        if ($id === null) { return null; }
        $user = $this->provider->findById($id);
        $key = (string) config("auth.providers.users.key", "id");
        if ($user === null || !self::validId($user[$key] ?? null) || (string) $user[$key] !== (string) $id) {
            $this->session->forget((string) config("auth.session_key", "auth.user_id"));
            return null;
        }
        return $user;
    }

    public function attempt(array $credentials): bool
    {
        $user = $this->provider->findByCredentials($credentials);
        $passwordField = (string) config("auth.providers.users.password", "password");

        if ($user === null || !isset($credentials["password"], $user[$passwordField])) {
            return false;
        }

        if (!$this->hasher->check((string) $credentials["password"], (string) $user[$passwordField])) {
            return false;
        }

        $this->login($user);

        return true;
    }

    public function login(array $user): void
    {
        $key = (string) config("auth.providers.users.key", "id");
        $id = $user[$key] ?? null;
        if (!self::validId($id)) { throw new \InvalidArgumentException("Authenticated user must have a non-empty string or integer identity."); }
        // Do not install a privileged identity if session rotation fails.
        $this->session->regenerate();
        $this->session->put((string) config("auth.session_key", "auth.user_id"), $id);
    }

    public function logout(): void
    {
        $this->session->forget((string) config("auth.session_key", "auth.user_id"));
        $this->session->regenerate();
    }

    private static function validId(mixed $id): bool
    {
        return is_int($id) || (is_string($id) && trim($id) !== "");
    }
}

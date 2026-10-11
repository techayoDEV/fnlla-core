<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HTTP SOURCE
File: src\Http\Cookie.php
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

final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly int $expires = 0,
        public readonly string $path = "/",
        public readonly string $domain = "",
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = "Lax"
    ) {
    }

    public function toHeaderValue(): string
    {
        // Values are encoded; directive attributes must never introduce another directive.
        if ($this->name === '' || preg_match('/[;\x00-\x1f\x7f]/', $this->path . $this->domain) === 1
            || !in_array(strtolower($this->sameSite), ['lax', 'strict', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid cookie name or directive attribute.');
        }
        $parts = [
            rawurlencode($this->name) . "=" . rawurlencode($this->value),
            "Path=" . $this->path,
            "SameSite=" . $this->sameSite,
        ];

        if ($this->expires > 0) {
            $parts[] = "Expires=" . gmdate("D, d M Y H:i:s", $this->expires) . " GMT";
            $parts[] = "Max-Age=" . max(0, $this->expires - time());
        }

        if ($this->domain !== "") {
            $parts[] = "Domain=" . $this->domain;
        }

        if ($this->secure) {
            $parts[] = "Secure";
        }

        if ($this->httpOnly) {
            $parts[] = "HttpOnly";
        }

        return implode("; ", $parts);
    }
}

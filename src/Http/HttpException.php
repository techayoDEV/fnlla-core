<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA HTTP SOURCE
File: src\Http\HttpException.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Carries explicit HTTP status metadata from low-level request hardening into
  the exception renderer.
*/

namespace Fnlla\Php\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

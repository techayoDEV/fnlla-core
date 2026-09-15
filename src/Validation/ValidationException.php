<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA VALIDATION SOURCE
File: src\Validation\ValidationException.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained validation rules and validation error handling.
*/

namespace Fnlla\Php\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct("The given data was invalid.");
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

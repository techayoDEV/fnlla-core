<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA CONTROLLER SOURCE
File: src\Controllers\Controller.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Provides HTTP-facing controller behaviour for maintained framework flows and demos.
*/

namespace Fnlla\Php\Controllers;

use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Validation\Validator;
use Fnlla\Php\View\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200, ?string $layout = "layouts/app"): Response
    {
        return Response::html(View::render($template, $data, $layout), $status);
    }

    protected function redirect(string $location, int $status = 302): Response
    {
        return Response::redirect($location, $status);
    }

    protected function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }

    protected function authorize(string $ability, mixed ...$arguments): void
    {
        try {
            gate()->authorize($ability, ...$arguments);
        } catch (AuthorizationException $exception) {
            throw $exception;
        }
    }
}

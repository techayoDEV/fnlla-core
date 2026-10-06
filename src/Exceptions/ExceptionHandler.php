<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA EXCEPTION SOURCE
File: src\Exceptions\ExceptionHandler.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements framework-level exception reporting and rendering behaviour.
*/

namespace Fnlla\Php\Exceptions;

use Fnlla\Php\Http\HttpException;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Support\Logger;
use Fnlla\Php\View\View;
use Throwable;

final class ExceptionHandler
{
    public function report(Throwable $exception, Request $request): void
    {
        // Full FNLLA may install a first-party issue tracker. Core logging must
        // remain available when that optional extension is absent or fails.
        $trackerClass = "Fnlla\\Php\\Observability\\RuntimeIssueTracker";
        if (class_exists($trackerClass)) {
            try {
                $tracker = new $trackerClass();
                if (is_callable([$tracker, "record"])) {
                    call_user_func([$tracker, "record"], $exception, $request);
                }
            } catch (Throwable) {
                @error_log("FNLLA issue tracking failed; continuing with Core exception logging.");
            }
        }
        try {
            Logger::exception($exception, [
                "request_id" => $request->requestId(),
                "method" => $request->method(),
                "path" => $request->path(),
                "ip" => $request->ip(),
            ]);
        } catch (Throwable) {
            @error_log("FNLLA exception reporting failed; check log storage permissions.");
        }
    }

    public function render(Throwable $exception, Request $request): Response
    {
        if (\Fnlla\Php\Resilience\DependencyFailure::classify($exception) !== null) {
            return $this->renderHttpException(new HttpException(503, 'A required service is temporarily unavailable.'), $request)
                ->withHeader('Retry-After', '30')->withHeader('Cache-Control', 'no-store')->withHeader('X-FNLLA-Status', 'unavailable');
        }
        if ($exception instanceof HttpException) {
            return $this->renderHttpException($exception, $request);
        }

        $debugMessage = app_debug()
            ? $exception->getMessage()
            : "The application hit an unexpected error while processing the request.";

        if ($request->expectsJson() || str_starts_with($request->path(), "/api/")) {
            return Response::json([
                "error" => "Server Error",
                "message" => $debugMessage,
                "request_id" => $request->requestId(),
            ], 500);
        }

        return $this->renderHtml([
            "pageTitle" => "Application Error",
            "headline" => "Something went wrong",
            "message" => $debugMessage,
            "requestReference" => $request->requestId(),
        ], 500, $request);
    }

    private function renderHttpException(HttpException $exception, Request $request): Response
    {
        $status = $exception->statusCode();
        $message = app_debug() ? $exception->getMessage() : match ($status) {
            403 => 'Access to this resource is forbidden.',
            404 => 'The requested resource was not found.',
            413 => 'The request body is too large.',
            429 => 'Too many requests. Please try again later.',
            502, 503, 504 => 'The service is temporarily unavailable. Please try again shortly.',
            default => 'The request could not be completed.',
        };
        $headline = match ($status) {
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            413 => "Request too large",
            429 => "Too many requests",
            default => "Request could not be completed",
        };

        if ($request->expectsJson() || str_starts_with($request->path(), "/api/")) {
            return Response::json([
                "error" => $headline,
                "message" => $message,
                "request_id" => $request->requestId(),
            ], $status);
        }

        return $this->renderHtml([
            "pageTitle" => $headline,
            "headline" => $headline,
            "message" => $message,
            "requestReference" => $request->requestId(),
        ], $status, $request);
    }

    private function renderHtml(array $data, int $status, Request $request): Response
    {
        try {
            return Response::html(View::render("pages/error", $data), $status);
        } catch (Throwable $renderError) {
            $this->report($renderError, $request);
            return Response::text("The application could not render this request.", $status)
                ->withHeader("Cache-Control", "no-store");
        }
    }
}

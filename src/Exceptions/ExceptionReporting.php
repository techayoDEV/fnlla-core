<?php

declare(strict_types=1);

namespace Fnlla\Php\Exceptions;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Http\Request;
use Throwable;

/** Reporting must never replace a successful outcome or its original failure. */
final class ExceptionReporting
{
    public static function report(Throwable $exception, array $context = [], ?Request $request = null, ?Container $container = null): void
    {
        $container ??= $GLOBALS['fnlla_container'] ?? null;
        try {
            $reporter = $container instanceof Container && $container->bound(ExceptionReporterInterface::class)
                ? $container->make(ExceptionReporterInterface::class) : new LogExceptionReporter();
            $reporter->report($exception, $context, $request);
        } catch (Throwable) {
            try { (new LogExceptionReporter())->report($exception, $context, $request); }
            catch (Throwable) { @error_log('FNLLA exception reporting unavailable; check diagnostic storage.'); }
        }
    }
}

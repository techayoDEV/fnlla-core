<?php

declare(strict_types=1);

namespace Fnlla\Php\Exceptions;

use Fnlla\Php\Http\Request;
use Throwable;

/** Private diagnostics only: never serialize exception messages or trace arguments. */
interface ExceptionReporterInterface
{
    public function report(Throwable $exception, array $context = [], ?Request $request = null): void;
}

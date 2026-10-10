<?php

declare(strict_types=1);

namespace Fnlla\Php\Exceptions;

use Fnlla\Php\Http\Request;
use Fnlla\Php\Support\Logger;
use Throwable;

final class LogExceptionReporter implements ExceptionReporterInterface
{
    public function report(Throwable $exception, array $context = [], ?Request $request = null): void
    {
        if ($request !== null) {
            $context += ['request_id' => $request->requestId(), 'method' => $request->method(), 'path' => $request->path(), 'ip' => $request->ip()];
        }
        if ($exception instanceof \Fnlla\Php\Database\PostCommitCallbackException) {
            $context['callback_failures'] = array_map(static fn (Throwable $error): array => [
                'type' => $error::class, 'file' => $error->getFile(), 'line' => $error->getLine(),
            ], $exception->failures());
        }
        Logger::exception($exception, $context);
    }
}

<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use PDOException;
use Throwable;

final class DependencyFailure
{
    public static function classify(Throwable $exception): ?DependencyUnavailable
    {
        if ($exception instanceof DependencyUnavailable) { return $exception; }
        // SQL syntax, constraints and permission errors remain ordinary failures.
        if ($exception instanceof PDOException && (str_starts_with((string) $exception->getCode(), '08')
            || in_array((int) ($exception->errorInfo[1] ?? 0), [2002, 2003, 2006, 2013], true))) {
            return new DependencyUnavailable('database', $exception);
        }
        return null;
    }
}

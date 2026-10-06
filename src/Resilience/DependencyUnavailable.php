<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use RuntimeException;
use Throwable;

/** Only availability failures belong here; validation/programming errors do not. */
final class DependencyUnavailable extends RuntimeException
{
    public function __construct(public readonly string $dependency, ?Throwable $previous = null)
    {
        parent::__construct('A required service is temporarily unavailable.', 0, $previous);
    }
}

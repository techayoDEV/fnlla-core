<?php

declare(strict_types=1);

namespace Fnlla\Php\Testing;

use Fnlla\Php\Resilience\DependencyUnavailable;

/** Explicit test/development injection; no production env switch or HTTP route. */
final class FailureSimulator
{
    public function __construct(private array $failed = []) {}

    public function read(string $dependency, callable $read): mixed
    {
        if (!in_array(app_environment(), ['development', 'testing'], true)) {
            throw new \LogicException('Failure simulation is disabled outside development/testing.');
        }
        if (in_array($dependency, $this->failed, true)) { throw new DependencyUnavailable($dependency); }
        return $read();
    }
}

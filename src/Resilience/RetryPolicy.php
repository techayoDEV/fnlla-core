<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Closure;

/** Explicit read-only opt-in; transport timeouts remain the adapter's responsibility. */
final class RetryPolicy
{
    private Closure $sleep;

    public function __construct(private int $maxAttempts = 1, private int $delayMs = 50,
        private int $budgetMs = 500, private bool $jitter = true, ?Closure $sleep = null)
    {
        if ($maxAttempts < 1 || $maxAttempts > 5 || $delayMs < 0 || $delayMs > 1000 || $budgetMs < 0 || $budgetMs > 5000) {
            throw new \InvalidArgumentException('Retry limits are outside safe bounds.');
        }
        $this->sleep = $sleep ?? static function (int $ms): void { usleep($ms * 1000); };
    }

    public function run(callable $operation, bool $idempotentRead = false): mixed
    {
        $spent = 0;
        for ($attempt = 1; ; $attempt++) {
            try { return $operation(); } catch (DependencyUnavailable $exception) {
                if (!$idempotentRead || $attempt >= $this->maxAttempts) { throw $exception; }
                $delay = min(1000, $this->delayMs * (2 ** ($attempt - 1)));
                if ($this->jitter && $delay > 0) { $delay = random_int((int) floor($delay / 2), $delay); }
                if ($spent + $delay > $this->budgetMs) { throw $exception; }
                ($this->sleep)($delay);
                $spent += $delay;
            }
        }
    }
}

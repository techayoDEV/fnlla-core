<?php

declare(strict_types=1);

namespace Fnlla\Php\Cache;

/** Optional atomic, fixed-window admission contract for cache stores. */
interface RateLimitStoreInterface
{
    /**
     * Rejected attempts must not extend the window. Storage failures must throw.
     * @return array{allowed: bool, attempts: int, retry_after: int}
     */
    public function consume(string $key, int $limit, int $decaySeconds): array;
}

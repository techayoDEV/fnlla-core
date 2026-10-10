<?php

declare(strict_types=1);

namespace Fnlla\Php\Cache;

/** Optional maintenance capability; never a fallback to deleting live cache data. */
interface PrunableCacheStoreInterface
{
    public function pruneExpired(): int;
}

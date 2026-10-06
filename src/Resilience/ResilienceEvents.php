<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Support\Logger;
use Throwable;

/** Vendor-neutral event hook; subscribers must never control business decisions. */
final class ResilienceEvents
{
    public function __construct(private ?Dispatcher $dispatcher = null) {}

    public function emit(string $event, array $labels = [], bool $log = true): void
    {
        // Never accept raw exceptions, request URLs, SQL or user-supplied labels.
        $safe = [];
        foreach (['dependency', 'status', 'fallback', 'cache', 'circuit', 'code'] as $key) {
            if (isset($labels[$key]) && is_scalar($labels[$key])) {
                $safe[$key] = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $labels[$key]) ?? '', 0, 80);
            }
        }
        try { $this->dispatcher?->dispatch('resilience.' . $event, $safe); } catch (Throwable) {}
        if ($log) {
            try { Logger::write('notice', $event, $safe); } catch (Throwable) {}
        }
    }
}

<?php

declare(strict_types=1);

namespace Fnlla\Php\Http;

/** Optional instrumentation; never responsible for authorization or business side effects. */
interface RequestLifecycleObserver
{
    public function begin(Request $request): void;

    public function finish(Request $request, Response $response, float $durationMs): Response;

    public function reset(): void;
}

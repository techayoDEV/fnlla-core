<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;

/** Bind after security/maintenance middleware. No active probes on normal traffic. */
final class ResilienceMiddleware implements MiddlewareInterface
{
    public function __construct(private PublicPageCache $pages, private HealthChecks $checks,
        private DependencyHealth $health, private ResilienceEvents $events) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (config('resilience.health.enabled', false) === true && in_array($request->path(), ['/health', '/health/live', '/health/ready'], true)) {
            if (!in_array($request->method(), ['GET', 'HEAD'], true) || !in_array($request->originalMethod(), ['GET', 'HEAD'], true)) {
                return Response::json(['status' => 'unavailable'], 405, ['Allow' => 'GET, HEAD', 'Cache-Control' => 'no-store']);
            }
            $status = $request->path() === '/health/live' ? 'healthy' : $this->checks->report()['status'];
            return Response::json(['status' => $status], $status === 'unavailable' ? 503 : 200, ['Cache-Control' => 'no-store']);
        }
        try {
            $response = $this->pages->handle($request, $next);
            if ($response instanceof Response) {
                $headers = array_change_key_case($response->headers(), CASE_LOWER);
                $status = $headers['x-fnlla-status'] ?? $this->health->status();
                if ($response->status() >= 500) { $status = 'unavailable'; }
                elseif ($status === 'healthy' && $this->health->status() === 'degraded') { $status = 'degraded'; }
                return $response->withHeader('X-FNLLA-Status', $status);
            }
            return $response;
        } catch (DependencyUnavailable $exception) {
            $this->health->failed($exception->dependency);
            throw $exception;
        }
    }
}

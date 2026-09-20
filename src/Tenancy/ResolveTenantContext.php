<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Middleware\MiddlewareInterface;

final class ResolveTenantContext implements MiddlewareInterface
{
    public function __construct(private TenantContextManager $contexts)
    {
    }

    public function handle(Request $request, callable $next): mixed
    {
        try {
            return $this->contexts->runForAuthenticated(static fn (): mixed => $next($request));
        } catch (AuthorizationException) {
            return $request->expectsJson()
                ? Response::json(["error" => "Forbidden.", "request_id" => $request->requestId()], 403)
                : Response::text("Forbidden.", 403);
        }
    }
}

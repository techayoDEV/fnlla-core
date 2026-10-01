<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Tenancy\TenantContextManager;

/** Default adapter; applications may bind a server-authenticated CLI/service adapter. */
final class SessionApplicationContextProvider implements ApplicationContextProviderInterface
{
    public function __construct(private AuthManager $auth, private TenantContextManager $tenants) {}

    public function current(): ApplicationContext
    {
        $actor = $this->auth->user();
        $tenant = $this->tenants->requireContext();
        $id = $actor[(string) config('auth.providers.users.key', 'id')] ?? null;
        if ($actor === null || (!is_string($id) && !is_int($id)) || (string) $id !== $tenant->actorId()) {
            throw new ActionException('unauthorized');
        }
        return new ApplicationContext($actor, $tenant);
    }
}

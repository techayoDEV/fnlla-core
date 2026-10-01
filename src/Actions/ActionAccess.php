<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Auth\Authorization\AccessControl;
use Throwable;

/** Shared execution and discovery checks. Public visibility never grants permission. */
final class ActionAccess
{
    public function __construct(private ApplicationContextProviderInterface $contexts, private AccessControl $access) {}

    public function assertContext(ApplicationContext $context): void
    {
        try { $current = $this->contexts->current(); }
        catch (Throwable) { throw new ActionException('unauthorized'); }
        if ($current->actor !== $context->actor || $current->identityDomain !== $context->identityDomain
            || $current->source !== $context->source || $current->audience !== $context->audience
            || $current->tenant->mode() !== $context->tenant->mode()
            || $current->tenant->actorId() !== $context->tenant->actorId()
            || $current->tenant->tenantId() !== $context->tenant->tenantId()
            || $current->tenant->correlationId() !== $context->tenant->correlationId()
            || $current->tenant->isBypass() !== $context->tenant->isBypass()) { throw new ActionException('unauthorized'); }
    }

    public function allows(ActionDefinition $definition, ApplicationContext $context, mixed $resource = null): bool
    {
        $metadata = $definition->metadata;
        return $metadata !== null
            && ($context->audience === 'internal' || $metadata->visibility === 'public')
            && in_array($context->identityDomain, $metadata->identityDomains, true)
            && $this->access->allows($definition->permission, $resource, $context->actor, $context->tenant);
    }
}

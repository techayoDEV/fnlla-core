<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use Fnlla\Php\Tenancy\TenantContext;
use InvalidArgumentException;

/** Server-owned identity snapshot. Constructing one never grants authority. */
final readonly class ApplicationContext
{
    public function __construct(
        public array $actor,
        public TenantContext $tenant,
        public string $identityDomain = 'application',
        public string $source = 'human',
        public string $audience = 'public'
    ) {
        ActionMetadata::identifier($identityDomain);
        if ($actor === [] || $tenant->actorId() === null || !in_array($source, ActionContext::SOURCES, true)
            || !in_array($audience, ['public', 'internal'], true)) {
            throw new InvalidArgumentException('Invalid application context.');
        }
        // Snapshot must contain values, never executable or mutable objects.
        self::assertValues($actor);
    }

    private static function assertValues(array $values, int $depth = 0): void
    {
        if ($depth > 12) { throw new InvalidArgumentException('Application actor is too deeply nested.'); }
        foreach ($values as $value) {
            if (is_array($value)) { self::assertValues($value, $depth + 1); }
            elseif (!is_scalar($value) && $value !== null) { throw new InvalidArgumentException('Application actor must contain values.'); }
        }
    }

    public function actionContext(): ActionContext { return ActionContext::fromTenant($this->tenant, $this->source); }
}

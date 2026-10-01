<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

/** A projection of ActionRegistry; no second registry or executable declarations. */
final class ApplicationSchema
{
    public const SCHEMA = 'fnlla.application-schema.v1';

    public function __construct(private ActionRegistry $registry, private ?ActionAccess $access = null) {}

    /** Caller-specific discovery is permission-filtered, never an invocation grant. */
    public function describe(ApplicationContext $context): array
    {
        if ($this->access === null) { throw new ActionException('unauthorized'); }
        $this->access->assertContext($context);
        return $this->build($context);
    }

    /** Local maintainer metadata only. Never publish this as remote discovery. */
    public function inspect(): array { return $this->build(null); }

    private function build(?ApplicationContext $context): array
    {
        $items = [];
        foreach ($this->registry->definitions() as $definition) {
            $metadata = $definition->metadata;
            if ($metadata === null || ($context !== null && !($this->access?->allows($definition, $context) ?? false))) { continue; }
            $items[] = [
                'id' => $definition->id, 'version' => $metadata->version, 'description' => $metadata->description,
                'kind' => $metadata->kind, 'visibility' => $metadata->visibility,
                'input' => ActionShape::describe($metadata->input), 'output' => ActionShape::describe($metadata->output),
                'validation' => ['schema' => ActionShape::SCHEMA, 'unknown_fields' => 'reject', 'coercion' => false, 'max_bytes' => 1048576],
                'permission' => $definition->permission, 'resource_required' => $metadata->resourceRequired,
                'identity_domains' => $metadata->identityDomains, 'events' => $definition->events,
                'execution' => $metadata->kind === 'command' ? 'database_transaction_outbox' : 'query',
                'exposure' => ['automatic' => false],
            ];
        }
        $document = ['schema' => self::SCHEMA, 'audience' => $context === null ? 'local' : $context->audience, 'capabilities' => $items];
        $document['content_sha256'] = hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $document;
    }
}

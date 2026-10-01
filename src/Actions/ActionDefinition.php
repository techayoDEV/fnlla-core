<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use InvalidArgumentException;

final readonly class ActionDefinition
{
    /**
     * @param callable|array $validator Returns the normalized input array.
     * @param callable|array $handler Returns ActionMutation.
     * @param list<string> $events
     */
    public function __construct(
        public string $id,
        public string $permission,
        public string $subjectType,
        public mixed $validator,
        public mixed $handler,
        public array $events = [],
        public ?ActionMetadata $metadata = null,
        public mixed $resourceResolver = null
    ) {
        foreach (["action" => $this->id, "permission" => $this->permission, "subject type" => $this->subjectType] as $label => $value) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException("Invalid {$label} identifier.");
            }
        }
        if (!self::isContainerCallable($this->validator) || !self::isContainerCallable($this->handler)) {
            throw new InvalidArgumentException("Action validator and handler must be callable.");
        }
        foreach ($this->events as $event) {
            if (!is_string($event) || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $event) !== 1) {
                throw new InvalidArgumentException("Invalid declared action event.");
            }
        }
        if ($resourceResolver !== null && !self::isContainerCallable($resourceResolver)) {
            throw new InvalidArgumentException('Action resource resolver must be callable.');
        }
        if ($resourceResolver !== null && $metadata === null) {
            throw new InvalidArgumentException('A capability resource resolver requires ActionMetadata.');
        }
        if (($metadata?->resourceRequired ?? false) && $resourceResolver === null) {
            throw new InvalidArgumentException('Resource-scoped capabilities require a trusted resolver.');
        }
        if ($metadata?->kind === 'query' && $events !== []) {
            throw new InvalidArgumentException('Queries cannot declare mutation events.');
        }
    }

    private static function isContainerCallable(mixed $value): bool
    {
        if (is_callable($value)) {
            return true;
        }

        return is_array($value)
            && count($value) === 2
            && (is_object($value[0] ?? null) || is_string($value[0] ?? null))
            && is_string($value[1] ?? null)
            && trim($value[1]) !== "";
    }
}

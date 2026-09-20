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
        public array $events = []
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

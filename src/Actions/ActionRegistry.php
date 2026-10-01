<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use RuntimeException;

final class ActionRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];

    public function register(ActionDefinition $definition): void
    {
        if (isset($this->definitions[$definition->id])) {
            throw new RuntimeException("Action is already registered: {$definition->id}.");
        }
        $this->definitions[$definition->id] = $definition;
    }

    public function get(string $id): ActionDefinition
    {
        return $this->definitions[$id] ?? throw new RuntimeException("Action is not registered: {$id}.");
    }

    /** Atomic registration batch for trusted providers and Product Module extensions.
     * @param list<ActionDefinition> $definitions
     */
    public function registerMany(array $definitions): void
    {
        $candidate = $this->definitions;
        foreach ($definitions as $definition) {
            if (!$definition instanceof ActionDefinition || isset($candidate[$definition->id])) {
                throw new RuntimeException('Invalid or duplicate action registration.');
            }
            $candidate[$definition->id] = $definition;
        }
        $this->definitions = $candidate;
    }

    /** @return list<ActionDefinition> */
    public function definitions(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);
        return array_values($definitions);
    }

    /** @internal Remove only the exact definition owned by a disabled module. */
    public function forgetDefinition(ActionDefinition $definition): void
    {
        if (($this->definitions[$definition->id] ?? null) === $definition) { unset($this->definitions[$definition->id]); }
    }

    /** @return list<array{id:string,permission:string,subject_type:string,events:list<string>}> */
    public function inspect(): array
    {
        $rows = array_map(static fn (ActionDefinition $definition): array => [
            "id" => $definition->id,
            "permission" => $definition->permission,
            "subject_type" => $definition->subjectType,
            "events" => array_values($definition->events),
        ], array_values($this->definitions));
        usort($rows, static fn (array $left, array $right): int => strcmp($left["id"], $right["id"]));
        return $rows;
    }
}

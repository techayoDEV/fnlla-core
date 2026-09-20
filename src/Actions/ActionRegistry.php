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

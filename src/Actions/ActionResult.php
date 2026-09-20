<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

final readonly class ActionResult
{
    /** @param array<string, mixed> $value @param list<string> $eventIds */
    public function __construct(
        public string $actionId,
        public string $subjectId,
        public array $value,
        public array $eventIds,
        public bool $replayed = false
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            "action_id" => $this->actionId,
            "subject_id" => $this->subjectId,
            "value" => $this->value,
            "event_ids" => $this->eventIds,
        ];
    }

    /** @param array<string, mixed> $stored */
    public static function replay(array $stored): self
    {
        return new self(
            (string) ($stored["action_id"] ?? ""),
            (string) ($stored["subject_id"] ?? ""),
            is_array($stored["value"] ?? null) ? $stored["value"] : [],
            array_values(array_filter((array) ($stored["event_ids"] ?? []), "is_string")),
            true
        );
    }
}

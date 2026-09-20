<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use InvalidArgumentException;

final readonly class ActionMutation
{
    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<array{name:string,payload_version:int,payload:array<string,mixed>}> $events
     */
    public function __construct(
        public string $subjectId,
        public array $result,
        public array $before = [],
        public array $after = [],
        public array $events = []
    ) {
        if ($this->subjectId === "" || strlen($this->subjectId) > 160
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@\\\\\/-]*$/D', $this->subjectId) !== 1) {
            throw new InvalidArgumentException("Invalid action subject ID.");
        }
        self::jsonSafe($this->result);
        self::jsonSafe($this->before);
        self::jsonSafe($this->after);
        foreach ($this->events as $event) {
            if (!is_array($event) || !is_string($event["name"] ?? null)
                || !is_int($event["payload_version"] ?? null) || ($event["payload_version"] ?? 0) < 1
                || !is_array($event["payload"] ?? null)) {
                throw new InvalidArgumentException("Invalid action domain event declaration.");
            }
            self::jsonSafe($event["payload"]);
        }
    }

    private static function jsonSafe(array $value): void
    {
        json_encode($value, JSON_THROW_ON_ERROR);
    }
}

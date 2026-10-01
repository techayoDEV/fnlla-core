<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

use InvalidArgumentException;

/** Closed, bounded value contracts. Not a general JSON Schema implementation. */
final class ActionShape
{
    public const SCHEMA = 'fnlla.action-shape.v1';
    public const MAX_ITEMS = 4096;
    public const MAX_STRING_BYTES = 1048576;

    public static function assertDefinition(array $shape, int $depth = 0): void
    {
        $type = $shape['type'] ?? null;
        $keys = ['type', 'description', 'hidden', 'nullable', 'enum'];
        $keys = array_merge($keys, match ($type) {
            'object' => ['properties', 'required'],
            'array' => ['items', 'minItems', 'maxItems'],
            'string' => ['minLength', 'maxLength'],
            'integer', 'number' => ['minimum', 'maximum'],
            'boolean' => [],
            default => throw new InvalidArgumentException('Unsupported action shape type.'),
        });
        if ($depth > 12 || array_diff(array_keys($shape), $keys) !== []) {
            throw new InvalidArgumentException('Unsupported action shape keyword or depth.');
        }
        foreach ($shape as $value) { if ($value === null) { throw new InvalidArgumentException('Shape keywords cannot be null.'); } }
        foreach (['hidden', 'nullable'] as $flag) {
            if (isset($shape[$flag]) && !is_bool($shape[$flag])) { throw new InvalidArgumentException('Invalid shape flag.'); }
        }
        if (isset($shape['description']) && (!is_string($shape['description']) || strlen($shape['description']) > 2048)) {
            throw new InvalidArgumentException('Invalid shape description.');
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $limit) {
            if (isset($shape[$limit]) && (!is_int($shape[$limit]) || $shape[$limit] < 0 || $shape[$limit] > (str_ends_with($limit, 'Items') ? self::MAX_ITEMS : self::MAX_STRING_BYTES))) {
                throw new InvalidArgumentException('Invalid shape bound.');
            }
        }
        foreach (['minimum', 'maximum'] as $limit) {
            if (isset($shape[$limit]) && ((!is_int($shape[$limit]) && !is_float($shape[$limit])) || !is_finite((float) $shape[$limit]))) {
                throw new InvalidArgumentException('Invalid numeric bound.');
            }
        }
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems'], ['minimum', 'maximum']] as [$min, $max]) {
            if (isset($shape[$min], $shape[$max]) && $shape[$min] > $shape[$max]) { throw new InvalidArgumentException('Inverted shape bounds.'); }
        }
        if (isset($shape['enum'])) {
            if (!is_array($shape['enum']) || !array_is_list($shape['enum']) || $shape['enum'] === [] || count($shape['enum']) > 128
                || in_array($type, ['object', 'array'], true)) { throw new InvalidArgumentException('Invalid shape enum.'); }
            $withoutEnum = $shape;
            unset($withoutEnum['enum']);
            foreach ($shape['enum'] as $value) {
                if (!self::accepts($withoutEnum, $value, false)) { throw new InvalidArgumentException('Enum does not match its shape.'); }
            }
        }
        if ($type === 'object') {
            $properties = $shape['properties'] ?? null;
            $required = $shape['required'] ?? [];
            if (!is_array($properties) || count($properties) > 128 || !is_array($required) || !array_is_list($required)) {
                throw new InvalidArgumentException('Object requires explicit properties and required names.');
            }
            foreach ($properties as $name => $field) {
                if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $name) !== 1 || !is_array($field)) {
                    throw new InvalidArgumentException('Invalid shape field.');
                }
                self::assertDefinition($field, $depth + 1);
            }
            foreach ($required as $name) {
                if (!is_string($name) || !array_key_exists($name, $properties)) { throw new InvalidArgumentException('Unknown required field.'); }
            }
            if (count(array_unique($required)) !== count($required)) { throw new InvalidArgumentException('Duplicate required field.'); }
        }
        if ($type === 'array') {
            if (!is_array($shape['items'] ?? null)) { throw new InvalidArgumentException('Array requires an item shape.'); }
            self::assertDefinition($shape['items'], $depth + 1);
        }
    }

    public static function assertPublicInput(array $shape): void
    {
        foreach ($shape['properties'] ?? [] as $name => $field) {
            if (($field['hidden'] ?? false) && in_array($name, $shape['required'] ?? [], true)) {
                throw new InvalidArgumentException('Public input cannot require hidden fields.');
            }
            self::assertPublicInput($field);
        }
        if (isset($shape['items'])) {
            if ($shape['items']['hidden'] ?? false) { throw new InvalidArgumentException('Public input cannot hide array items.'); }
            self::assertPublicInput($shape['items']);
        }
    }

    public static function validate(array $shape, mixed $value, bool $publicInput = false, string $failure = 'invalid_input'): void
    {
        if (!self::accepts($shape, $value, $publicInput)) { throw new ActionException($failure); }
    }

    private static function accepts(array $shape, mixed $value, bool $publicInput): bool
    {
        if ($value === null) { return ($shape['nullable'] ?? false) && (!isset($shape['enum']) || in_array(null, $shape['enum'], true)); }
        $type = $shape['type'];
        $valid = match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)) && count($value) <= 128,
            'array' => is_array($value) && array_is_list($value) && count($value) <= min(self::MAX_ITEMS, $shape['maxItems'] ?? self::MAX_ITEMS)
                && count($value) >= ($shape['minItems'] ?? 0),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8') && strlen($value) <= 1048576
                && mb_strlen($value, 'UTF-8') >= ($shape['minLength'] ?? 0) && mb_strlen($value, 'UTF-8') <= ($shape['maxLength'] ?? 1048576),
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid || (isset($shape['enum']) && !in_array($value, $shape['enum'], true))) { return false; }
        if (in_array($type, ['integer', 'number'], true)) {
            return (!isset($shape['minimum']) || $value >= $shape['minimum']) && (!isset($shape['maximum']) || $value <= $shape['maximum']);
        }
        if ($type === 'object') {
            foreach ($shape['required'] ?? [] as $name) { if (!array_key_exists($name, $value)) { return false; } }
            foreach ($value as $name => $item) {
                $field = $shape['properties'][$name] ?? null;
                if ($field === null || ($publicInput && ($field['hidden'] ?? false)) || !self::accepts($field, $item, $publicInput)) { return false; }
            }
        } elseif ($type === 'array') {
            foreach ($value as $item) { if (!self::accepts($shape['items'], $item, $publicInput)) { return false; } }
        }
        return true;
    }

    /** Remove hidden values recursively, including fields in array items. */
    public static function project(array $shape, mixed $value): mixed
    {
        if ($value === null) { return null; }
        if ($shape['type'] === 'array') {
            return ($shape['items']['hidden'] ?? false) ? [] : array_map(static fn ($item) => self::project($shape['items'], $item), $value);
        }
        if ($shape['type'] !== 'object') { return $value; }
        $result = [];
        foreach ($shape['properties'] as $name => $field) {
            if (!self::hidden($field) && array_key_exists($name, $value)) { $result[$name] = self::project($field, $value[$name]); }
        }
        return $result;
    }

    public static function describe(array $shape): array
    {
        unset($shape['hidden']);
        if ($shape['type'] === 'array') { $shape['maxItems'] ??= self::MAX_ITEMS; }
        if ($shape['type'] === 'string') { $shape['maxLength'] ??= self::MAX_STRING_BYTES; }
        if ($shape['type'] === 'object') {
            $fields = [];
            foreach ($shape['properties'] as $name => $field) {
                if (self::hidden($field)) { continue; }
                $fields[$name] = self::describe($field);
            }
            ksort($fields, SORT_STRING);
            $shape['properties'] = (object) $fields;
            $shape['required'] = array_values(array_intersect($shape['required'] ?? [], array_keys($fields)));
            sort($shape['required'], SORT_STRING);
        }
        if (isset($shape['items'])) { $shape['items'] = self::describe($shape['items']); }
        ksort($shape, SORT_STRING);
        return $shape;
    }

    private static function hidden(array $shape): bool
    {
        return ($shape['hidden'] ?? false) || ($shape['type'] === 'array' && self::hidden($shape['items']));
    }
}

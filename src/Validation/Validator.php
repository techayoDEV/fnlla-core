<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA VALIDATION SOURCE
File: src\Validation\Validator.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements maintained validation rules and validation error handling.
*/

namespace Fnlla\Php\Validation;

use Fnlla\Php\Http\UploadedFile;

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data, private array $rules)
    {
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function validate(): array
    {
        foreach ($this->rules as $field => $rules) {
            $this->validateField($field, is_array($rules) ? $rules : explode("|", (string) $rules));
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        $validated = [];

        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function validateField(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;
        $nullable = in_array("nullable", $rules, true);

        if ($nullable && ($value === null || $value === "")) {
            return;
        }

        foreach ($rules as $rule) {
            [$name, $parameter] = array_pad(explode(":", (string) $rule, 2), 2, null);

            match ($name) {
                "required" => $this->assert($field, $value !== null && $value !== "", "This field is required."),
                "string" => $this->assert($field, is_string($value), "This field must be a string."),
                "array" => $this->assert($field, is_array($value), "This field must be an array."),
                "email" => $this->assert($field, is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false, "This field must be a valid email address."),
                "integer" => $this->assert($field, filter_var($value, FILTER_VALIDATE_INT) !== false, "This field must be an integer."),
                "numeric" => $this->assert($field, is_numeric($value), "This field must be numeric."),
                "url" => $this->assert($field, is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false, "This field must be a valid URL."),
                "min" => $this->assert($field, $this->size($value) >= (float) $parameter, "This field must be at least " . (string) $parameter . "."),
                "max" => $this->assert($field, $this->size($value) <= (float) $parameter, "This field must not exceed " . (string) $parameter . "."),
                "boolean" => $this->assert($field, in_array($value, [true, false, 0, 1, "0", "1"], true), "This field must be boolean."),
                "confirmed" => $this->assert($field, ($this->data[$field . "_confirmation"] ?? null) === $value, "This field confirmation does not match."),
                "file" => $this->assert($field, $value instanceof UploadedFile && $value->isValid(), "This field must contain a valid uploaded file."),
                default => null,
            };

            if ($parameter !== null && $name === "in") {
                $allowed = explode(",", $parameter);
                $this->assert($field, in_array((string) $value, $allowed, true), "This field contains an invalid value.");
            }

            if (isset($this->errors[$field])) {
                break;
            }
        }
    }

    private function assert(string $field, bool $condition, string $message): void
    {
        if (!$condition && !isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function size(mixed $value): float|int
    {
        if ($value instanceof UploadedFile) {
            return $value->size();
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return mb_strlen((string) $value);
    }
}

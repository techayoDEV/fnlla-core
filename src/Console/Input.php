<?php

declare(strict_types=1);

namespace Fnlla\Php\Console;

use InvalidArgumentException;

final class Input
{
    private function __construct(public readonly array $arguments, private array $options) {}

    /** @param array<string, bool> $options True requires a value; false is a flag. */
    public static function parse(array $arguments, array $options = [], int $maxArguments = 0): self
    {
        $options["help"] = false;
        $values = [];
        $positionals = [];
        $literal = false;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = (string) $arguments[$index];
            if (!$literal && $argument === "--") { $literal = true; continue; }
            if (!$literal && $argument === "-h") { $argument = "--help"; }
            if (!$literal && str_starts_with($argument, "--")) {
                [$name, $value] = array_pad(explode("=", substr($argument, 2), 2), 2, null);
                if (!array_key_exists($name, $options)) { throw new InvalidArgumentException("Unknown option: --" . $name); }
                if (array_key_exists($name, $values)) { throw new InvalidArgumentException("Duplicate option: --" . $name); }
                if (!$options[$name]) {
                    if ($value !== null) { throw new InvalidArgumentException("Flag --" . $name . " does not accept a value."); }
                    $values[$name] = true;
                    continue;
                }
                if ($value === null) {
                    $value = $arguments[++$index] ?? null;
                    if ($value !== null && str_starts_with((string) $value, "-")) { $value = null; }
                }
                if (!is_string($value) || $value === "") { throw new InvalidArgumentException("Option --" . $name . " requires a value."); }
                $values[$name] = $value;
            } else {
                if (!$literal && str_starts_with($argument, "-")) { throw new InvalidArgumentException("Unknown option: " . $argument); }
                $positionals[] = $argument;
            }
        }
        if (count($positionals) > $maxArguments) { throw new InvalidArgumentException("Too many positional arguments."); }
        return new self($positionals, $values);
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public static function positiveInteger(string $value, string $name): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) === false) {
            throw new InvalidArgumentException($name . " must be a positive integer.");
        }
        return (int) $value;
    }
}

<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA DATABASE SOURCE
File: src\Database\Factories\Factory.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements the maintained MySQL data access and migration runtime.
*/

namespace Fnlla\Php\Database\Factories;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;

abstract class Factory
{
    protected int $count = 1;

    public function __construct(
        protected Container $container,
        protected DatabaseManager $database
    ) {
    }

    abstract protected function table(): string;

    abstract protected function definition(): array;

    public function count(int $count): static
    {
        $clone = clone $this;
        $clone->count = max(1, $count);

        return $clone;
    }

    public function make(array $overrides = []): array
    {
        return array_merge($this->definition(), $overrides);
    }

    public function create(array $overrides = []): array
    {
        $created = [];

        for ($index = 0; $index < $this->count; $index++) {
            $attributes = $this->make($overrides);
            $id = $this->database->table($this->table())->insertGetId($attributes);
            $created[] = array_merge($attributes, ["id" => $id]);
        }

        return $this->count === 1 ? $created[0] : $created;
    }
}

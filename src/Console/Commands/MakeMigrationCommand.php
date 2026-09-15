<?php

declare(strict_types=1);

namespace Fnlla\Php\Console\Commands;

use Fnlla\Php\Console\GeneratorCommand;

final class MakeMigrationCommand extends GeneratorCommand
{
    protected const KIND = "migration";
}

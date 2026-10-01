<?php

declare(strict_types=1);

namespace Fnlla\Php\Product;

use Fnlla\Php\Actions\ActionDefinition;

/** Optional capability; existing module extensions remain source-compatible. */
interface ProductModuleActionsInterface
{
    /** @return list<ActionDefinition> Trusted definitions for this module's declared actions. */
    public function actionDefinitions(): array;
}

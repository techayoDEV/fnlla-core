<?php

declare(strict_types=1);

namespace Fnlla\Php\Actions;

interface ApplicationContextProviderInterface
{
    /** Resolve fresh trusted identity and scope; never hydrate authority from action input. */
    public function current(): ApplicationContext;
}

<?php

declare(strict_types=1);

namespace Fnlla\Php\Product;

use Closure;

interface ProductModuleExtensionInterface
{
    /**
     * Return implementations keyed by the service declaration ID in the
     * module manifest. The manifest owns the abstract and lifetime; this
     * trusted application extension only supplies the implementation.
     *
     * @return array<string, Closure|string>
     */
    public function serviceBindings(): array;

    /**
     * Return cache-safe handlers keyed by the route declaration ID in the
     * module manifest.
     *
     * @return array<string, array{0:class-string,1:string}>
     */
    public function routeHandlers(): array;
}

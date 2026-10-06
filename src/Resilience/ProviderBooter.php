<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Fnlla\Php\Container\Container;
use Fnlla\Php\Support\ServiceProvider;
use Throwable;

final class ProviderBooter
{
    public function __construct(private Container $container, private DependencyHealth $health) {}

    public function boot(array $classes, array $policies = []): void
    {
        $providers = [];
        $optional = [];
        foreach ($classes as $class) {
            if (($policies[$class] ?? 'critical') === 'optional') { $optional[] = $class; continue; }
            /** @var ServiceProvider $provider */
            $provider = new $class($this->container);
            $provider->register();
            $providers[] = $provider;
        }
        foreach ($providers as $provider) { $provider->boot(); }
        // Optional providers cannot leave partial container bindings behind.
        foreach ($optional as $class) {
            try {
                $this->container->transactionalBindings(function () use ($class): void {
                    $provider = new $class($this->container);
                    $provider->register();
                    $provider->boot();
                });
            } catch (Throwable) { $this->health->bootFailed('optional_provider'); }
        }
    }
}

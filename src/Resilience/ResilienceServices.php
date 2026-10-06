<?php

declare(strict_types=1);

namespace Fnlla\Php\Resilience;

use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Cache\RedisCacheStore;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Events\Dispatcher;

/** One configuration path and existing container primitives. Stores resolve lazily. */
final class ResilienceServices
{
    public static function register(Container $container): void
    {
        $container->singleton(ResilienceEvents::class, static fn (Container $c): ResilienceEvents => new ResilienceEvents($c->make(Dispatcher::class)));
        if (!$container->bound(DependencyHealth::class)) {
            $container->singleton(DependencyHealth::class, static fn (Container $c): DependencyHealth => new DependencyHealth($c->make(ResilienceEvents::class), (array) config('resilience.dependencies', [])));
        }
        $container->singleton(DisposableCache::class, static function (Container $c): DisposableCache {
            $primary = static fn () => config('resilience.cache.primary', 'file') === 'redis'
                ? new RedisCacheStore((array) config('cache.stores.redis', []))
                : new FileCacheStore(storage_path('framework/resilience/pages'));
            $fallback = config('resilience.cache.fallback', 'file') === 'file'
                ? static fn (): FileCacheStore => new FileCacheStore(storage_path('framework/resilience/backup')) : null;
            return new DisposableCache($primary, $fallback, $c->make(ResilienceEvents::class));
        });
        $container->singleton(PublicPageCache::class, static fn (Container $c): PublicPageCache => new PublicPageCache(
            $c->make(DisposableCache::class), $c->make(ResilienceEvents::class), (array) config('resilience.page_cache', [])));
        $container->singleton(HealthChecks::class, static fn (Container $c): HealthChecks => new HealthChecks(
            $c->make(DisposableCache::class), $c->make(DependencyHealth::class), (array) config('resilience.health', [])));
        $container->singleton(CircuitBreaker::class, static fn (Container $c): CircuitBreaker => new CircuitBreaker(
            storage_path('framework/resilience/circuits'), $c->make(ResilienceEvents::class),
            (int) config('resilience.circuit.failure_threshold', 5), (int) config('resilience.circuit.cooldown_seconds', 30),
            (int) config('resilience.circuit.probe_lease_seconds', 30)));
        $container->singleton(RetryPolicy::class, static fn (): RetryPolicy => new RetryPolicy(
            (int) config('resilience.retry.max_attempts', 1), (int) config('resilience.retry.delay_ms', 50),
            (int) config('resilience.retry.budget_ms', 500)));
        $container->singleton(ComponentBoundary::class);
        $container->singleton(ResilienceMiddleware::class);
    }
}

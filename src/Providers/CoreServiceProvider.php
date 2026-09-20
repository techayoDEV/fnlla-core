<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SERVICE PROVIDER SOURCE
File: src\Providers\FrameworkServiceProvider.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Registers maintained framework services and application-level boot behaviour.
*/

namespace Fnlla\Php\Providers;

use Fnlla\Php\Actions\ActionRegistry;
use Fnlla\Php\Actions\ActionRunner;
use Fnlla\Php\Actions\ActionStoreInterface;
use Fnlla\Php\Actions\DatabaseActionStore;
use Fnlla\Php\Audit\AuditLoggerInterface;
use Fnlla\Php\Audit\JsonAuditLogger;
use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\Gate;
use Fnlla\Php\Auth\Authorization\PolicyRegistry;
use Fnlla\Php\Auth\Authorization\RoleAssignmentGuard;
use Fnlla\Php\Auth\DatabaseUserProvider;
use Fnlla\Php\Auth\UserProviderInterface;
use Fnlla\Php\Cache\CacheStoreInterface;
use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Cache\JsonCacheSerializer;
use Fnlla\Php\Cache\PhpCacheSerializer;
use Fnlla\Php\Cache\RateLimiter;
use Fnlla\Php\Cache\RedisCacheStore;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Console\Application as ConsoleApplication;
use RuntimeException;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Database\Migrations\Migrator;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Events\DomainEventBus;
use Fnlla\Php\Events\OutboxProcessor;
use Fnlla\Php\Exceptions\ExceptionHandler;
use Fnlla\Php\Filesystem\StorageManager;
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Localization\Translator;
use Fnlla\Php\Mail\Mailer;
use Fnlla\Php\Product\ProductModuleRegistry;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Queue\QueueStoreInterface;
use Fnlla\Php\Queue\RedisQueueStore;
use Fnlla\Php\Routing\Router;
use Fnlla\Php\Routing\UrlGenerator;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Support\ServiceProvider;
use Fnlla\Php\Support\RuntimeInspector;
use Fnlla\Php\Tenancy\TenantContextManager;
use Fnlla\Php\Tenancy\TenantIdentityResolverInterface;
use Fnlla\Php\Tenancy\TenantResourceScope;
use Fnlla\Php\Tenancy\UserProviderTenantIdentityResolver;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->instance(Container::class, $this->container);
        $this->container->singleton(ExceptionHandler::class);
        $this->container->singleton(DatabaseManager::class);
        $this->container->singleton(SessionStore::class);
        $this->container->singleton(CacheStoreInterface::class, static function (): CacheStoreInterface {
            $defaultStore = (string) config("cache.default", "file");
            $storeConfig = config("cache.stores." . $defaultStore, []);
            $serializer = (string) config("cache.serializer", "json");

            if (!in_array($serializer, ["json", "php"], true)) {
                throw new RuntimeException("Unsupported cache serializer: " . $serializer);
            }

            if ($defaultStore === "redis") {
                return new RedisCacheStore((array) config("cache.stores.redis", []));
            }

            return new FileCacheStore(
                (string) ($storeConfig["path"] ?? storage_path("framework/cache")),
                $serializer === "php" ? new PhpCacheSerializer() : new JsonCacheSerializer(),
                new PhpCacheSerializer()
            );
        });
        $this->container->singleton(RateLimiter::class);
        $this->container->singleton(StorageManager::class);
        $this->container->singleton(Hasher::class);
        $this->container->singleton(Dispatcher::class);
        $this->container->singleton(ActionRegistry::class);
        $this->container->singleton(ActionStoreInterface::class, DatabaseActionStore::class);
        $this->container->singleton(DomainEventBus::class);
        $this->container->singleton(OutboxProcessor::class);
        $this->container->singleton(ActionRunner::class);
        $this->container->singleton(Translator::class);
        $this->container->singleton(Mailer::class);


        $this->container->singleton(QueueStoreInterface::class, static function (): QueueStoreInterface {
            $default = (string) config("queue.default", "file");

            if ($default === "redis") {
                return new RedisQueueStore((array) config("queue.connections.redis", []));
            }

            return new FileQueueStore(storage_path((string) config("queue.connections.file.path", "framework/queue")));
        });
        $this->container->singleton(QueueManager::class);
        $this->container->singleton(UserProviderInterface::class, static fn (Container $container): DatabaseUserProvider => new DatabaseUserProvider(
            $container->make(DatabaseManager::class)
        ));
        $this->container->singleton(AuthManager::class);
        $this->container->singleton(PolicyRegistry::class);
        $this->container->singleton(AccessControl::class);
        $this->container->singleton(AuditLoggerInterface::class, static fn (): JsonAuditLogger => new JsonAuditLogger(
            storage_path((string) config("security.audit.path", "logs/audit.jsonl")),
            (array) config("security.audit.allowed_fields", []),
            (int) config("security.audit.retention_days", 90),
            (int) config("security.audit.maximum_entries", 10000)
        ));
        $this->container->singleton(TenantIdentityResolverInterface::class, UserProviderTenantIdentityResolver::class);
        $this->container->singleton(TenantContextManager::class);
        $this->container->singleton(TenantResourceScope::class);
        $this->container->singleton(RoleAssignmentGuard::class);
        $this->container->singleton(Gate::class);
        $this->container->singleton(Router::class, static fn (Container $container): Router => new Router($container));
        $this->container->singleton(UrlGenerator::class, static fn (Container $container): UrlGenerator => new UrlGenerator(
            $container->make(Router::class)
        ));
        $this->container->singleton(Migrator::class);
        $this->container->singleton(ConsoleApplication::class);
        $this->container->singleton(RuntimeInspector::class);
        $this->container->singleton(ProductModuleRegistry::class);
    }
}

<?php

declare(strict_types=1);

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Auth\Authorization\PolicyRegistry;
use Fnlla\Php\Auth\UserProviderInterface;
use Fnlla\Php\Cache\CacheStoreInterface;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Providers\CoreServiceProvider;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\JobContext;
use Fnlla\Php\Queue\QueueStoreInterface;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Tenancy\TenantContextManager;
use Fnlla\Php\Tenancy\TenantIdentityResolverInterface;

$auditConfig = config();
try {
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $ssl = new ReflectionMethod(DatabaseManager::class, 'sslOptions');
        $database = new DatabaseManager();
        assert_same(true, $ssl->invoke($database, ['enabled' => true])[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT], 'Partial TLS configuration must verify certificates.');
        assert_same(false, $ssl->invoke($database, ['enabled' => true, 'verify_server_cert' => false])[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT], 'Explicit opt-out must remain explicit.');
    } else {
        echo '[SKIP] MySQL TLS option unavailable in this PHP build.' . PHP_EOL;
    }
    foreach ([['cache.default', CacheStoreInterface::class], ['queue.default', QueueStoreInterface::class]] as [$key, $contract]) {
        config_set($key, 'redsi');
        $container = new Container();
        (new CoreServiceProvider($container))->register();
        try { $container->make($contract); throw new LogicException('Invalid driver silently fell back.'); }
        catch (RuntimeException $error) { assert_true(str_contains($error->getMessage(), 'Unsupported'), 'Invalid driver must fail with a configuration error.'); }
    }
    $users = new class implements UserProviderInterface {
        public function findById(string|int $id): ?array { return null; }
        public function findByCredentials(array $credentials): ?array { return null; }
    };
    $auth = new AuthManager(new SessionStore(), $users, new Hasher());
    $resolver = new class implements TenantIdentityResolverInterface {
        public array $actor = ['id' => 'other', 'active' => false];
        public function findActor(string $actorId): ?array { return $this->actor; }
        public function resolveTenant(array $actor, string $mode, ?string $requestedTenantId = null): ?string { return 'tenant-a'; }
    };
    config_set('auth.providers.users.key', 'id'); config_set('security.tenancy.mode', 'organization');
    $manager = new TenantContextManager($auth, $resolver, new AccessControl($auth, new PolicyRegistry()));
    $directory = sys_get_temp_dir() . '/fnlla-audit-identity-' . bin2hex(random_bytes(6));
    $job = new JobContext(new FileQueueStore($directory), ['context' => ['actor_id' => 'actor-a', 'tenant_id' => 'tenant-a', 'correlation_id' => 'synthetic']]);
    foreach ([['id' => 'other', 'active' => true], ['id' => 'actor-a', 'active' => false]] as $actor) {
        $resolver->actor = $actor;
        try { $manager->runForJob($job, static fn () => throw new LogicException('Invalid actor executed job.')); throw new LogicException('Job was accepted.'); }
        catch (AuthorizationException) { assert_same(null, $manager->current(), 'Rejected job leaked tenant context.'); }
    }
    $resolver->actor = ['id' => 'actor-a', 'active' => true];
    assert_same('tenant-a', $manager->runForJob($job, static fn ($context) => $context->tenantId()), 'Valid custom resolver must still work.');
    assert_same(null, $manager->current(), 'Job scope was not reset.');
    $container = new Container();
    $container->beginScope();
    $service = new class {};
    $container->scopedInstance('audit.optional', $service);
    $container->scopedInstance(\Fnlla\Php\Http\Request::class, new \Fnlla\Php\Http\Request('GET', '/scoped'));
    assert_same('/scoped', $container->call(static fn (?\Fnlla\Php\Http\Request $request = null): ?string => $request?->path()), 'Optional typed injection must resolve the current scoped instance.');
    assert_true($container->bound('audit.optional'), 'Scoped instance must count as a binding.');
    $container->endScope();
    $request = new \Fnlla\Php\Http\Request('POST', '/api/item', query: ['value' => 'query'], request: ['value' => null], routeParams: ['value' => 'route']);
    assert_same(null, $request->input('value', 'default'), 'Explicit JSON/body null must override query and route input.');
    assert_same(null, $request->all()['value'], 'all() must agree with input().');
    try { \Fnlla\Php\Http\Request::capture('{broken', ['CONTENT_TYPE' => 'application/json']); throw new LogicException('Invalid JSON accepted.'); }
    catch (\Fnlla\Php\Http\HttpException $error) { assert_same(400, $error->statusCode(), 'Malformed JSON must be a client error.'); }
    assert_same(null, \Fnlla\Php\Http\Request::capture('null', ['CONTENT_TYPE' => 'application/json'])->json(), 'Valid JSON null is allowed.');
    $response = \Fnlla\Php\Http\Response::html('')->withHeader('x-test', 'old')->withHeaders(['X-Test' => 'new'])
        ->withHeaders(['set-cookie' => ['a=1']])->withCookie(new \Fnlla\Php\Http\Cookie('b', '2'));
    assert_same('new', $response->headers()['X-Test'], 'Header replacement must ignore casing.');
    assert_true(!isset($response->headers()['x-test']), 'Duplicate case-variant header remained.');
    assert_same(2, count($response->headers()['Set-Cookie']), 'Adding a cookie must preserve existing headers.');
    $router = new \Fnlla\Php\Routing\Router(new Container());
    $router->group(['prefix' => '/outer'], function ($router): void {
        $router->group(['prefix' => '/middle'], function ($router): void {
            $router->group(['prefix' => '/inner'], function ($router): void { $router->get('/leaf', static fn () => 'ok'); });
        });
    });
    assert_same('ok', $router->dispatch(new \Fnlla\Php\Http\Request('GET', '/outer/middle/inner/leaf')), 'Nested prefix order must be outer to inner.');
    $missing = $router->dispatch(new \Fnlla\Php\Http\Request('GET', '/missing', headers: ['accept' => 'application/json']));
    assert_same(404, $missing->status(), 'JSON missing route status.');
    assert_true(is_array(json_decode($missing->body(), true)), 'JSON clients must receive a JSON 404.');
    $queue = new FileQueueStore($directory);
    mkdir($directory . '/idempotency', 0700);
    $expired = $directory . '/idempotency/' . hash('sha256', 'expired') . '.json';
    $active = $directory . '/idempotency/' . hash('sha256', 'active') . '.json';
    $processing = $directory . '/idempotency/' . hash('sha256', 'processing') . '.json';
    file_put_contents($expired, json_encode(['state' => 'completed', 'expires_at' => 100]));
    file_put_contents($active, json_encode(['state' => 'completed', 'expires_at' => 102]));
    file_put_contents($processing, json_encode(['state' => 'processing', 'expires_at' => 99]));
    assert_same(1, $queue->pruneCompletedIdempotency(100, 100), 'Only expired completed receipts may be removed at the TTL boundary.');
    assert_true(is_file($active) && is_file($processing), 'Housekeeping removed live/processing state.');
    unlink($active); unlink($processing); rmdir($directory . '/idempotency');
    unlink($directory . '/.idempotency-prune-cursor');
    if (is_file($directory . '/.queue.lock')) { unlink($directory . '/.queue.lock'); }
    rmdir($directory);
    echo "[PASS] Audit remediation: TLS, driver selection and job identity boundaries." . PHP_EOL;
} finally {
    $GLOBALS['fnlla_config'] = $auditConfig; $GLOBALS['fnlla_php_config'] = $auditConfig;
}

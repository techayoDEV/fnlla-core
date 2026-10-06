<?php

declare(strict_types=1);

use Fnlla\Php\Application;
use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\Dispatcher;
use Fnlla\Php\Exceptions\ExceptionHandler;
use Fnlla\Php\Http\HttpException;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Resilience\CircuitBreaker;
use Fnlla\Php\Resilience\ComponentBoundary;
use Fnlla\Php\Resilience\DependencyFailure;
use Fnlla\Php\Resilience\DependencyHealth;
use Fnlla\Php\Resilience\DependencyUnavailable;
use Fnlla\Php\Resilience\DisposableCache;
use Fnlla\Php\Resilience\HealthChecks;
use Fnlla\Php\Resilience\ProviderBooter;
use Fnlla\Php\Resilience\PublicPageCache;
use Fnlla\Php\Resilience\ResilienceEvents;
use Fnlla\Php\Resilience\ResilienceMiddleware;
use Fnlla\Php\Resilience\RetryPolicy;
use Fnlla\Php\Resilience\StaticFallbackExporter;
use Fnlla\Php\Routing\Router;
use Fnlla\Php\Support\ServiceProvider;
use Fnlla\Php\Testing\FailureSimulator;

if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__)); }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Fnlla\\Php\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
require_once dirname(__DIR__) . '/src/Support/helpers.php';
final class ResilienceBrokenProvider extends ServiceProvider
{
    public function register(): void { $this->container->instance('partial-binding', 'partial'); }
    public function boot(): void { throw new RuntimeException('Synthetic optional provider failure.'); }
}

$oldConfig = $GLOBALS['fnlla_config'] ?? [];
$GLOBALS['fnlla_config'] = ['app' => ['environment' => 'testing', 'debug' => false], 'http' => ['security_headers' => []],
    'resilience' => ['enabled' => true, 'health' => ['enabled' => true]],
    'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'synthetic_unreachable', 'username' => '', 'password' => '']]]];
$directory = APP_ROOT . '/storage/resilience-tests-' . bin2hex(random_bytes(6));
$GLOBALS['fnlla_config']['app']['log_path'] = $directory . '/test.log';
$assertions = 0;
$scenarios = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
};
$scenario = static function (string $name, callable $test) use (&$scenarios): void { $test(); $scenarios++; echo 'PASS ' . $name . PHP_EOL; };
$throws = static function (callable $test, string $class) use ($check): void {
    try { $test(); } catch (Throwable $error) { $check($error instanceof $class, 'Unexpected exception ' . $error::class); return; }
    $check(false, 'Expected exception ' . $class);
};
$request = static fn (string $path = '/', string $method = 'GET', array $server = [], array $cookies = []): Request => Request::capture('',
    array_merge(['REQUEST_URI' => $path, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'localhost'], $server), [], [], $cookies, []);
$events = new ResilienceEvents();
$store = new FileCacheStore($directory . '/pages');
$cache = new DisposableCache(static fn () => $store, null, $events);
$now = time();
$settings = ['enabled' => true, 'paths' => ['/', '/loans', '/savings', '/contact', '/help', '/member-login', '/account'], 'fresh_ttl' => 0, 'last_known_good_ttl' => 3600];
$pages = new PublicPageCache($cache, $events, $settings, static function () use (&$now): int { return $now; });
$health = new DependencyHealth($events);
$healthy = new FailureSimulator();
$failed = new FailureSimulator(['database', 'external_api']);
$body = '<html><h1>Synthetic public credit union fixture</h1><p>Contact: fixture@example.test</p></html>';
$render = static fn () => Response::html($body, 200, ['X-FNLLA-Public-Page' => '1']);
$_SESSION = [];
ob_start();
try {
    $scenario('normal database-backed render (synthetic read adapter)', function () use ($pages, $request, $healthy, $render, $check, $body): void {
        $response = $pages->handle($request(), static fn () => $healthy->read('database', $render));
        $check($response->status() === 200 && $response->body() === $body, 'Normal response lost.');
    });
    $scenario('real closed-port MySQL connection is typed and redacted', function () use ($throws, $check): void {
        $throws(static fn () => (new DatabaseManager())->connection(), DependencyUnavailable::class);
        $exception = new PDOException('secret SQL'); $exception->errorInfo = ['HY000', 2006, 'secret'];
        $check(DependencyFailure::classify($exception)?->dependency === 'database', 'Disconnect not classified.');
        $check(DependencyFailure::classify(new PDOException('syntax error')) === null, 'SQL defect hidden.');
    });
    $scenario('all five public acceptance routes survive database outage', function () use ($pages, $request, $render, $failed, $check, $body): void {
        foreach (['/', '/loans', '/savings', '/contact', '/help'] as $path) {
            $pages->handle($request($path), $render);
            $response = $pages->handle($request($path), static fn () => $failed->read('database', $render));
            $check($response->status() === 200 && $response->body() === $body, 'No public fallback for ' . $path);
            $check($response->headers()['X-FNLLA-Status'] === 'degraded', 'Fallback not marked degraded.');
        }
    });
    $scenario('private member/account errors are controlled and never cached', function () use ($pages, $request, $failed, $render, $check): void {
        foreach (['/member-login', '/account'] as $path) {
            $pages->handle($request($path), $render);
            $container = new Container(); $router = new Router($container);
            $router->get($path, static fn () => $failed->read('database', $render));
            $app = (new Application($router, $container, new ExceptionHandler()))->middleware([PublicPageCache::class]);
            $container->instance(PublicPageCache::class, $pages);
            $response = $app->handle($request($path));
            $check($response->status() === 503 && !str_contains($response->body(), 'fixture@example'), 'Private stale data leaked.');
            $check($pages->snapshot($path) === null, 'Private page cached.');
        }
    });
    $scenario('optional external component preserves page and discards partial output', function () use ($health, $events, $failed, $check): void {
        $component = new ComponentBoundary($health, $events);
        $response = 'header' . $component->render('external_api', static function () use ($failed): void { echo 'partial secret'; $failed->read('external_api', static fn () => 'calculator'); }, static fn () => '<p>Temporarily unavailable</p>') . 'footer';
        $check(str_contains($response, 'header') && str_contains($response, 'footer') && !str_contains($response, 'partial secret'), 'Component boundary failed.');
        $check($health->status() === 'degraded', 'No component degradation.');
        $health->reset();
    });
    $scenario('programming errors are not converted to component fallback', function () use ($health, $events, $throws): void {
        $throws(static fn () => (new ComponentBoundary($health, $events))->render('api', static fn () => throw new LogicException('bug'), static fn () => 'hidden'), LogicException::class);
    });
    $scenario('unavailable Redis adapter uses filesystem without touching security cache', function () use ($events, $store, $check): void {
        $disposable = new DisposableCache(static fn () => throw new RuntimeException('Redis unavailable'), static fn () => $store, $events);
        $check($disposable->put('read-fixture', 'safe', 60), 'File backup write failed.');
        $check($disposable->get('read-fixture') === 'safe', 'File backup read failed.');
        $check(!$disposable instanceof \Fnlla\Php\Cache\CacheStoreInterface, 'Failover can replace security cache.');
    });
    $scenario('all disposable cache layers failed means safe uncached read', function () use ($events, $check, $throws): void {
        $dead = static fn () => throw new RuntimeException('unavailable');
        $disposable = new DisposableCache($dead, $dead, $events);
        $check($disposable->remember('key', 10, static fn () => 'actual read') === 'actual read', 'Uncached read failed.');
        $throws(static fn () => $disposable->remember('key', 10, static fn () => throw new DependencyUnavailable('database')), DependencyUnavailable::class);
    });
    $scenario('circuit threshold/open state persists across worker instances', function () use ($directory, $events, &$now, $throws, $check): void {
        $clock = static function () use (&$now): int { return $now; };
        $circuit = new CircuitBreaker($directory . '/circuit', $events, 2, 30, 10, $clock);
        for ($i = 0; $i < 2; $i++) { $throws(static fn () => $circuit->run('api', static fn () => throw new DependencyUnavailable('api')), DependencyUnavailable::class); }
        $other = new CircuitBreaker($directory . '/circuit', $events, 2, 30, 10, $clock);
        $calls = 0;
        $throws(static fn () => $other->run('api', static function () use (&$calls): void { $calls++; }), DependencyUnavailable::class);
        $check($calls === 0, 'Open circuit called remote dependency.');
        $now += 31;
        $check($other->run('api', static fn () => 'recovered') === 'recovered', 'Half-open probe failed.');
        $check($circuit->run('api', static fn () => 'closed') === 'closed', 'Circuit did not close.');
    });
    $scenario('half-open probe lease admits only one caller', function () use ($directory, $events, &$now, $throws, $check): void {
        $clock = static function () use (&$now): int { return $now; };
        $one = new CircuitBreaker($directory . '/lease', $events, 1, 1, 10, $clock);
        $two = new CircuitBreaker($directory . '/lease', $events, 1, 1, 10, $clock);
        $throws(static fn () => $one->run('api', static fn () => throw new DependencyUnavailable('api')), DependencyUnavailable::class);
        $now += 2;
        $one->run('api', function () use ($two, $throws): string {
            $throws(static fn () => $two->run('api', static fn () => 'bad second probe'), DependencyUnavailable::class);
            return 'recovery';
        });
        $check($two->run('api', static fn () => 'closed') === 'closed', 'Probe did not close.');
    });
    $scenario('bounded retries apply only to explicit idempotent reads', function () use ($check, $throws): void {
        $calls = 0; $delays = [];
        $policy = new RetryPolicy(3, 20, 100, false, static function (int $ms) use (&$delays): void { $delays[] = $ms; });
        $operation = static function () use (&$calls): string { if (++$calls < 3) { throw new DependencyUnavailable('api'); } return 'read'; };
        $check($policy->run($operation, true) === 'read' && $calls === 3 && $delays === [20, 40], 'Read retry mismatch.');
        $calls = 0;
        $throws(static fn () => $policy->run($operation), DependencyUnavailable::class);
        $check($calls === 1, 'Unsafe mutation retried.');
    });
    $scenario('separate concurrent PHP processes share one half-open probe lease', function () use ($directory, $events, $throws, $check): void {
        $parallel = $directory . '/parallel';
        $circuit = new CircuitBreaker($parallel . '/state', $events, 1, 1, 10, static fn (): int => 100);
        $throws(static fn () => $circuit->run('parallel_api', static fn () => throw new DependencyUnavailable('parallel_api')), DependencyUnavailable::class);
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $processes[] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/circuit-worker.php', $parallel],
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                    1 => ['file', $directory . '/worker-' . $i . '.out', 'w'], 2 => ['file', $directory . '/worker-' . $i . '.err', 'w']], $pipes);
        }
        foreach ($processes as $process) { $check(is_resource($process) && proc_close($process) === 0, 'Circuit worker failed.'); }
        $coordination = new FileCacheStore($parallel . '/coordination');
        $check($coordination->get('calls') === 1, 'Concurrent half-open calls exceeded one probe.');
    });
    $scenario('health healthy/degraded/unavailable is minimal and readiness-aware', function () use ($cache, $health, $events, $pages, $request, $check): void {
        foreach (['healthy', 'degraded', 'unavailable'] as $expected) {
            $policy = $expected === 'unavailable' ? 'critical' : 'degradable';
            $checks = new HealthChecks($cache, $health, ['snapshot_ttl' => 1, 'dependencies' => ['database' => $policy], 'test_namespace' => $expected],
                static fn () => ['checks' => [['service' => 'database', 'status' => $expected === 'healthy' ? 'ready' : 'error', 'hostname' => 'secret.internal']]]);
            $middleware = new ResilienceMiddleware($pages, $checks, $health, $events);
            $response = $middleware->handle($request('/health/ready'), static fn () => throw new LogicException('route should not execute'));
            $check(json_decode($response->body(), true) === ['status' => $expected], 'Public health leaked diagnostic data.');
            $check($response->status() === ($expected === 'unavailable' ? 503 : 200), 'Readiness status mismatch.');
            $check($middleware->handle($request('/health/live'), static fn () => null)->status() === 200, 'Liveness depends on database.');
        }
    });
    $scenario('production error handling retains status and hides secrets even with missing error view', function () use ($request, $check): void {
        $GLOBALS['fnlla_config']['app']['environment'] = 'production';
        $GLOBALS['fnlla_config']['app']['debug'] = true; // Even accidental production debug must not leak.
        foreach ([403, 404, 429, 500, 502, 503, 504] as $code) {
            $response = (new ExceptionHandler())->render(new HttpException($code, 'password=secret SQL C:/private'), $request('/api/private'));
            $check($response->status() === $code && !str_contains($response->body(), 'secret'), 'Unsafe production HTTP exception.');
            $check((new ExceptionHandler())->render(new HttpException($code, 'private'), $request('/public'))->status() === $code, 'Error view failure changed status.');
        }
        $response = (new ExceptionHandler())->render(new RuntimeException('secret SQL /private'), $request('/api/private'));
        $check($response->status() === 500 && !str_contains($response->body(), 'secret'), 'Unexpected exception leaked.');
        $GLOBALS['fnlla_config']['app']['environment'] = 'testing';
        $GLOBALS['fnlla_config']['app']['debug'] = false;
    });
    $scenario('cookies/auth/query/POST/overridden POST never receive public stale data', function () use ($pages, $request, $failed, $render, $throws): void {
        foreach ([$request('/', 'POST'), $request('/', 'GET', ['HTTP_AUTHORIZATION' => 'Bearer private']),
            $request('/', 'GET', [], ['session' => 'private']), $request('/?variant=private'),
            $request('/', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'HEAD']), $request('/', 'GET', ['HTTP_ACCEPT_LANGUAGE' => 'pl']),
            $request('/', 'GET', ['HTTP_RANGE' => 'bytes=0-10'])] as $private) {
            $throws(static fn () => $pages->handle($private, static fn () => $failed->read('database', $render)), DependencyUnavailable::class);
        }
    });
    $scenario('new session state after dependency failure forbids stale fallback', function () use ($pages, $request, $throws): void {
        $throws(static fn () => $pages->handle($request(), static function (): void { $_SESSION['user'] = 'private'; throw new DependencyUnavailable('database'); }), DependencyUnavailable::class);
        $_SESSION = [];
    });
    $scenario('failed renders never overwrite LKG; unsafe successful renders are excluded', function () use ($pages, $request, $check, $body, $throws): void {
        $pages->handle($request(), static fn () => Response::html('failed', 503));
        $check($pages->snapshot('/')['body'] === $body, '503 overwrote LKG.');
        $throws(static fn () => $pages->handle($request(), static fn () => throw new LogicException('failed render')), LogicException::class);
        $check($pages->snapshot('/')['body'] === $body, 'Failed render overwrote LKG.');
        foreach ([Response::html('private', 200, ['X-FNLLA-Public-Page' => '1', 'Set-Cookie' => 'private=1']),
            Response::html('<form><input name="_token"></form>', 200, ['X-FNLLA-Public-Page' => '1']),
            Response::html('private', 200, ['X-FNLLA-Public-Page' => '1', 'Cache-Control' => 'private']),
            Response::html('variant', 200, ['X-FNLLA-Public-Page' => '1', 'Vary' => 'Accept-Language']),
            Response::html('<script nonce="123">x</script>', 200, ['X-FNLLA-Public-Page' => '1']),
            Response::html('no public marker')] as $response) {
            $pages->handle($request(), static fn () => $response);
            $check($pages->snapshot('/')['body'] === $body, 'Unsafe response replaced LKG.');
        }
    });
    $scenario('fresh cache avoids dependency call and HEAD body is suppressed by kernel', function () use ($cache, $events, $settings, $request, $check, &$now): void {
        $fresh = new PublicPageCache($cache, $events, array_merge($settings, ['fresh_ttl' => 300, 'namespace' => 'fresh-fixture']), static function () use (&$now): int { return $now; });
        $fresh->handle($request(), static fn () => Response::html('fresh public', 200, ['X-FNLLA-Public-Page' => '1']));
        $container = new Container(); $container->instance(PublicPageCache::class, $fresh); $router = new Router($container);
        $router->get('/', static fn () => throw new DependencyUnavailable('database'));
        $app = (new Application($router, $container, new ExceptionHandler()))->middleware(PublicPageCache::class);
        $check($app->handle($request())->body() === 'fresh public', 'Fresh cache did not avoid read.');
        $response = $app->handle($request('/', 'HEAD'));
        $check($response->status() === 200 && $response->body() === '', 'HEAD body leaked.');
    });
    $scenario('optional provider boot rolls back bindings; critical failure propagates', function () use ($events, $check, $throws): void {
        $container = new Container(); $health = new DependencyHealth($events);
        $booter = new ProviderBooter($container, $health);
        $booter->boot([ResilienceBrokenProvider::class], [ResilienceBrokenProvider::class => 'optional']);
        $check(!$container->bound('partial-binding') && $health->status() === 'degraded', 'Optional provider contaminated bootstrap.');
        $throws(static fn () => $booter->boot([ResilienceBrokenProvider::class]), RuntimeException::class);
        $health->reset(); $check($health->status() === 'degraded', 'Bootstrap degradation lost across requests.');
    });
    $scenario('static export contains only approved snapshots and generic emergency content', function () use ($pages, $directory, $check, $throws): void {
        $exporter = new StaticFallbackExporter($pages);
        $manifest = $exporter->export(['/loans', '/contact'], $directory . '/export');
        $check(count($manifest) === 2 && is_file($directory . '/export/503.html'), 'Static export missing.');
        $check(hash_file('sha256', $directory . '/export/' . $manifest['/loans']['file']) === $manifest['/loans']['sha256'], 'Artifact mismatch.');
        $throws(static fn () => $exporter->export(['/account'], $directory . '/export'), RuntimeException::class);
        $check(!str_contains(StaticFallbackExporter::emergency('<script>alert(1)</script>'), '<script>'), 'Emergency message injection.');
    });
    $scenario('maximum stale age expires without a fabricated success', function () use ($pages, $request, &$now, $throws): void {
        $now += 4000;
        $throws(static fn () => $pages->handle($request('/loans'), static fn () => throw new DependencyUnavailable('database')), DependencyUnavailable::class);
    });
    $scenario('failure simulator cannot be enabled in production', function () use ($failed, $throws): void {
        $GLOBALS['fnlla_config']['app']['environment'] = 'production';
        $throws(static fn () => $failed->read('database', static fn () => 'unsafe'), LogicException::class);
    });
    $scenario('normalized string responses carry request state and request metrics count once', function () use ($request, $check, $events): void {
        $GLOBALS['fnlla_config']['app']['environment'] = 'testing';
        $container = new Container(); $dispatcher = new Dispatcher($container); $metrics = new ResilienceEvents($dispatcher);
        $health = new DependencyHealth($metrics); $container->instance(DependencyHealth::class, $health); $container->instance(ResilienceEvents::class, $metrics);
        $counts = 0; $dispatcher->listen('resilience.request_count', static function () use (&$counts): void { $counts++; });
        $router = new Router($container);
        $router->get('/component', static function () use ($health): string { $health->failed('api', 'optional'); return 'page remains available'; });
        $router->get('/broken', static fn () => throw new LogicException('broken request'));
        $app = new Application($router, $container, new ExceptionHandler());
        $response = $app->handle($request('/component'));
        $check($response->status() === 200 && $response->headers()['X-FNLLA-Status'] === 'degraded', 'Normalized response lost degraded state.');
        $check($app->handle($request('/broken'))->headers()['X-FNLLA-Status'] === 'unavailable', 'Failed request marked healthy.');
        $check($counts === 2 && $health->status() === 'healthy', 'Metric duplication or request state leak.');
    });
    echo "Resilience: {$scenarios} scenarios, {$assertions} assertions passed." . PHP_EOL;
} finally {
    $GLOBALS['fnlla_config'] = $oldConfig;
    $_SESSION = [];
    $output = (string) ob_get_clean();
    echo $output;
    if (is_dir($directory)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
        rmdir($directory);
    }
}

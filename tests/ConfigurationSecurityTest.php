<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\{AccessControl, PolicyRegistry};
use Fnlla\Php\Auth\UserProviderInterface;
use Fnlla\Php\Actions\{ActionContext, ReliableOutboxStoreInterface};
use Fnlla\Php\Audit\{AuditEvent, AuditLoggerInterface};
use Fnlla\Php\Console\Commands\ConfigCacheCommand;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Events\{Dispatcher, DomainEventBus, OutboxWorker};
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Queue\{FileQueueStore, QueueManager, QueueStoreInterface};
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Support\{ConfigurationGeneration, PhpArrayCache};

$root = sys_get_temp_dir() . '/fnlla-config-security-' . bin2hex(random_bytes(8));
foreach (['config', 'defaults', 'storage/framework/cache'] as $path) { mkdir($root . '/' . $path, 0700, true); }
define('APP_ROOT', $root);
define('FNLLA_CONFIG_DEFAULTS_ROOT', $root . '/defaults');
$passed = [];
$check = static function (string $case, bool $value) use (&$passed): void {
    if (!$value) { throw new RuntimeException('Configuration security assertion failed: ' . $case); }
    $passed[] = $case;
};
$deny = static function (string $case, callable $operation) use ($check): void {
    try { $operation(); } catch (RuntimeException) { $check($case, true); return; }
    throw new RuntimeException('Configuration security denial missing: ' . $case);
};
$write = static function (string $path, array $data): void { PhpArrayCache::write($path, $data); };
$cache = framework_config_cache_path();
$app = ['live_config_fields' => ['credentials' => ['users'], 'security' => ['authorization']]];
try {
    $write($root . '/config/app.php', $app);
    $write($root . '/defaults/credentials.php', ['users' => 'revoked-package-secret']);
    $write($root . '/defaults/security.php', ['authorization' => ['roles' => ['editor' => ['permissions' => ['article.publish']]]]]);
    $write($root . '/config/credentials.php', ['users' => 'current-secret']);
    $write($root . '/config/security.php', ['authorization' => ['roles' => ['editor' => ['permissions' => []]]]]);
    $command = new ConfigCacheCommand(new Container());
    ob_start(); try { $command->handle([]); } finally { ob_end_clean(); }
    $provider = new class implements UserProviderInterface {
        public function findById(string|int $id): ?array { return null; }
        public function findByCredentials(array $credentials): ?array { return null; }
    };
    $access = new AccessControl(new AuthManager(new SessionStore(), $provider, new Hasher()), new PolicyRegistry());
    foreach ([true, false] as $cached) {
        $mode = $cached ? 'cached' : 'fresh';
        $GLOBALS['fnlla_config'] = load_config_directory($root . '/config', $cached);
        $check($mode . ': current credential', config('credentials.users') === 'current-secret');
        $check($mode . ': revoked permission cannot fall back to defaults', !$access->allows('article.publish', actor: ['id' => 1, 'role' => 'editor']));
    }
    $write($root . '/config/credentials.php', ['users' => 'rotated-secret']);
    foreach ([true, false] as $cached) {
        $check(($cached ? 'cached' : 'fresh') . ': old credential rejected after rotation', load_config_directory($root . '/config', $cached)['credentials']['users'] === 'rotated-secret');
    }
    $write($root . '/config/credentials.php', []);
    $write($root . '/config/security.php', []);
    foreach ([true, false] as $cached) {
        $mode = $cached ? 'cached' : 'fresh';
        $GLOBALS['fnlla_config'] = load_config_directory($root . '/config', $cached);
        $check($mode . ': removal cannot resurrect package credential', !array_key_exists('users', config('credentials', [])));
        $check($mode . ': removed authorization denies', !$access->allows('article.publish', actor: ['id' => 1, 'role' => 'editor']));
    }
    unlink($root . '/config/credentials.php');
    foreach ([true, false] as $cached) {
        $check(($cached ? 'cached' : 'fresh') . ': missing credential file removes field', !array_key_exists('users', load_config_directory($root . '/config', $cached)['credentials']));
    }
    file_put_contents($root . '/config/credentials.php', '<?php return "invalid";');
    foreach ([true, false] as $cached) { $deny(($cached ? 'cached' : 'fresh') . ': invalid live file denies', static fn () => load_config_directory($root . '/config', $cached)); }
    $before = hash_file('sha256', $cache);
    $deny('invalid input leaves previous cache intact', static fn () => $command->handle([]));
    $check('failed compilation does not replace cache', hash_file('sha256', $cache) === $before);
    $write($root . '/config/credentials.php', []);
    file_put_contents($cache, '<?php return "invalid";');
    $deny('malformed cache cannot fall back to files', static fn () => load_config_directory($root . '/config'));
    $write($root . '/config/app.php', ['live_config_fields' => null]);
    $deny('null declaration cannot silently disable live fields', static fn () => load_config_directory($root . '/config', false));
    $write($root . '/config/app.php', $app);
    ob_start(); try { $command->handle([]); } finally { ob_end_clean(); }

    // Real queued bytes stay unchanged: fencing happens BEFORE reservation.
    $store = new FileQueueStore($root . '/queue');
    $store->push('SyntheticNeverRunJob');
    $jobPath = (glob($root . '/queue/*.job') ?: [])[0];
    $jobBefore = hash_file('sha256', $jobPath);
    $queue = new QueueManager(new Container(), $store);
    $legacy = new class implements QueueStoreInterface {
        public int $reservations = 0;
        public function push(string $jobClass, array $payload = []): string { return 'synthetic'; }
        public function pop(): ?array { $this->reservations++; return null; }
        public function complete(array $job): void {}
        public function fail(array $job): string { return 'synthetic'; }
        public function pendingCount(): int { return 0; }
        public function failedCount(): int { return 0; }
    };
    $legacyWorker = new QueueManager(new Container(), $legacy);
    $outboxStore = new class implements ReliableOutboxStoreInterface {
        public int $claims = 0;
        public function claimDelivery(int $leaseSeconds, int $maximumAttempts): ?array { $this->claims++; return null; }
        public function ownsDelivery(string $id, string $token): bool { return false; }
        public function acknowledgeDelivery(string $id, string $token): void { throw new RuntimeException('Unexpected settlement.'); }
        public function failDelivery(string $id, string $token, string $code, bool $retryable, int $maximumAttempts, int $delaySeconds): void { throw new RuntimeException('Unexpected settlement.'); }
        public function deliveryStatus(int $limit = 100): array { return []; }
        public function retryDelivery(string $id): bool { return false; }
        public function claim(string $idempotencyKey, string $actionId, string $requestHash, ActionContext $context): ?array { throw new RuntimeException('Unexpected mutation.'); }
        public function complete(string $idempotencyKey, array $result): void { throw new RuntimeException('Unexpected mutation.'); }
        public function append(string $id, string $kind, string $name, array $payload): void { throw new RuntimeException('Unexpected mutation.'); }
        public function pending(int $limit = 100): array { return []; }
        public function markPublished(string $id): void { throw new RuntimeException('Unexpected mutation.'); }
    };
    $audit = new class implements AuditLoggerInterface { public function record(AuditEvent $event): void { throw new RuntimeException('Unexpected audit effect.'); } };
    $outboxContainer = new Container();
    $outbox = new OutboxWorker($outboxStore, $audit, new DomainEventBus($outboxContainer, new Dispatcher($outboxContainer), $queue), $outboxContainer);
    file_put_contents($root . '/.env', 'SYNTHETIC_SECRET=before');
    ConfigurationGeneration::capture();
    ConfigurationGeneration::assertCurrent();
    file_put_contents($root . '/.env', 'SYNTHETIC_SECRET=after');
    $deny('stale reliable worker stops before reservation', static fn () => $queue->work());
    $check('pending job bytes and reservation untouched', hash_file('sha256', $jobPath) === $jobBefore && $store->pendingCount() === 1);
    $deny('stale legacy worker stops before reservation', static fn () => $legacyWorker->work());
    $check('legacy adapter was not called', $legacy->reservations === 0);
    $deny('stale outbox worker stops before claim', static fn () => $outbox->work());
    $check('outbox adapter was not called', $outboxStore->claims === 0);
    $before = hash_file('sha256', $cache);
    $deny('stale process cannot publish a bootstrap cache', static fn () => $command->handle([]));
    $check('stale compiler preserves existing cache', hash_file('sha256', $cache) === $before);
    $deny('stale worker cannot adopt the new generation', static fn () => ConfigurationGeneration::capture());
    file_put_contents($root . '/.env', 'SYNTHETIC_SECRET=before');
    ConfigurationGeneration::assertCurrent();
    foreach ([$root . '/config/credentials.php', $root . '/defaults/credentials.php', $cache] as $input) {
        $original = file_get_contents($input);
        file_put_contents($input, $original . "\n// changed generation\n");
        $deny('changed ' . basename(dirname($input)) . '/' . basename($input) . ' fences the worker', static fn () => $queue->work());
        file_put_contents($input, $original);
        ConfigurationGeneration::assertCurrent();
    }
    $write($root . '/config/new_namespace.php', []);
    $deny('new configuration file fences the worker', static fn () => $queue->work());
    unlink($root . '/config/new_namespace.php');
    $original = file_get_contents($root . '/config/credentials.php');
    unlink($root . '/config/credentials.php');
    $deny('removed configuration file fences the worker', static fn () => $queue->work());
    file_put_contents($root . '/config/credentials.php', $original);
    ConfigurationGeneration::assertCurrent();
    $child = $root . '/fresh.php';
    file_put_contents($root . '/.env', 'SYNTHETIC_SECRET=after');
    file_put_contents($child, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
        . '; define("APP_ROOT",' . var_export($root, true) . '); define("FNLLA_CONFIG_DEFAULTS_ROOT", APP_ROOT."/defaults");'
        . '\\Fnlla\\Php\\Support\\ConfigurationGeneration::capture(); \\Fnlla\\Php\\Support\\Env::load(env_file_path());'
        . '\\Fnlla\\Php\\Support\\ConfigurationGeneration::assertCurrent();'
        . 'if (env("SYNTHETIC_SECRET") !== "after") { throw new RuntimeException("Stale environment after restart."); }');
    $process = proc_open([PHP_BINARY, $child], [0 => ['pipe', 'r'], 1 => ['file', $root . '/fresh.out', 'w'], 2 => ['file', $root . '/fresh.err', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start fresh generation process.'); }
    fclose($pipes[0]);
    $check('restarted process observes the new environment', proc_close($process) === 0);
    echo json_encode(['suite' => 'configuration-security', 'status' => 'passed', 'assertions' => $passed], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

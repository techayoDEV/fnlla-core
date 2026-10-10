<?php

declare(strict_types=1);

use Fnlla\Php\Actions\{ActionAccess, ActionDefinition, ActionException, ActionExecutor, ActionMetadata, ActionMutation,
    ActionRegistry, ActionTransaction, ApplicationContext, ApplicationContextProviderInterface, DatabaseActionStore};
use Fnlla\Php\Auth\{AuthManager, UserProviderInterface};
use Fnlla\Php\Auth\Authorization\{AccessControl, PolicyRegistry};
use Fnlla\Php\Audit\{AuditEvent, AuditLoggerInterface};
use Fnlla\Php\Container\Container;
use Fnlla\Php\Database\DatabaseManager;
use Fnlla\Php\Events\{Dispatcher, DomainEventBus, OutboxProcessor};
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Queue\{FileQueueStore, QueueManager};
use Fnlla\Php\Tenancy\TenantContext;

// Only run under the isolated service gate; unique tables never reuse application data.
$capServiceConfig = $GLOBALS['fnlla_config'] ?? [];
$capServicePrefix = 'cap_test_' . bin2hex(random_bytes(6));
$capServicePdo = new PDO((string) getenv('FNLLA_CORE_TEST_MYSQL_DSN'), (string) getenv('FNLLA_CORE_TEST_MYSQL_USER'), (string) getenv('FNLLA_CORE_TEST_MYSQL_PASSWORD'));
$capServiceDb = DatabaseManager::using($capServicePdo);
$capServiceQueuePath = sys_get_temp_dir() . '/' . $capServicePrefix;
try {
    $GLOBALS['fnlla_config'] = ['actions' => ['receipts_table' => $capServicePrefix . '_receipts', 'outbox_table' => $capServicePrefix . '_outbox'],
        'app' => ['log_path' => $capServiceQueuePath . '/diagnostics.log'],
        'security' => ['authorization' => ['roles' => ['writer' => ['permissions' => ['fixture.write']]]]]];
    $store = new DatabaseActionStore($capServiceDb);
    $store->installSchema();
    $capServicePdo->exec("CREATE TABLE `{$capServicePrefix}` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
    $container = new Container();
    $events = new Dispatcher($container, $capServiceDb);
    $audit = new class implements AuditLoggerInterface {
        public bool $fail = false;
        public function record(AuditEvent $event): void { if ($this->fail) { throw new RuntimeException('private-driver-error'); } }
    };
    $outbox = new OutboxProcessor($store, $audit, new DomainEventBus($container, $events,
        new QueueManager($container, new FileQueueStore($capServiceQueuePath), $capServiceDb)));
    $container->instance(ActionTransaction::class, new ActionTransaction($container, $capServiceDb, $store, $outbox));
    $contexts = new class implements ApplicationContextProviderInterface {
        public function current(): ApplicationContext {
            return new ApplicationContext(['id' => 'fixture', 'role' => 'writer'], new TenantContext('none', null, 'fixture', 'mysql-capability'));
        }
    };
    $users = new class implements UserProviderInterface {
        public function findById(string|int $id): ?array { return null; }
        public function findByCredentials(array $credentials): ?array { return null; }
    };
    $access = new ActionAccess($contexts, new AccessControl(new AuthManager(new SessionStore(), $users, new Hasher()), new PolicyRegistry()));
    $registry = new ActionRegistry();
    $executor = new ActionExecutor($container, $registry, $access, $events, $capServiceDb);
    $shape = ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']], 'required' => ['value']];
    foreach (['commit', 'rollback', 'postcommit'] as $kind) {
        $registry->register(new ActionDefinition('fixture.' . $kind, 'fixture.write', 'fixture', static fn (array $input): array => $input,
            static function (array $input, DatabaseManager $database) use ($kind, $capServicePrefix): ActionMutation {
                $id = match ($kind) { 'commit' => 1, 'rollback' => 2, default => 3 };
                $statement = $database->connection()->prepare("INSERT INTO `{$capServicePrefix}` (id, value) VALUES (?, ?)");
                $statement->execute([$id, $input['value']]);
                return new ActionMutation((string) $id, ['value' => $kind === 'rollback' ? 'invalid' : $input['value']]);
            }, [], new ActionMetadata('Synthetic real-store command.', $shape, $shape, visibility: 'public')));
    }
    $context = $contexts->current();
    $executor->execute('fixture.commit', ['value' => 1], $context);
    serviceCheck($executor->execute('fixture.commit', ['value' => 1], $context)->replayed, 'Real-store capability replay failed.');
    try { $executor->execute('fixture.rollback', ['value' => 2], $context); throw new RuntimeException('Invalid result committed.'); }
    catch (ActionException $error) { serviceCheck($error->reason === 'invalid_output', 'Wrong output failure.'); }
    serviceCheck((int) $capServicePdo->query("SELECT COUNT(*) FROM `{$capServicePrefix}`")->fetchColumn() === 1, 'Invalid output did not roll back business writes.');
    serviceCheck((int) $capServicePdo->query("SELECT COUNT(*) FROM `{$capServicePrefix}_receipts`")->fetchColumn() === 1, 'Rollback left a receipt.');
    serviceCheck((int) $capServicePdo->query("SELECT COUNT(*) FROM `{$capServicePrefix}_outbox`")->fetchColumn() === 1, 'Rollback left an audit message.');
    $audit->fail = true;
    $committed = $executor->execute('fixture.postcommit', ['value' => 3], $context);
    serviceCheck(!$committed->replayed && $committed->value === ['value' => 3], 'Relay failure replaced the committed result.');
    serviceCheck((int) $capServicePdo->query("SELECT COUNT(*) FROM `{$capServicePrefix}`")->fetchColumn() === 2, 'Post-commit failure lost the mutation.');
    serviceCheck(count($store->pending()) === 1, 'Failed audit was acknowledged or lost.');
    $diagnostics = (string) file_get_contents($capServiceQueuePath . '/diagnostics.log');
    serviceCheck(str_contains($diagnostics, 'RuntimeException') && !str_contains($diagnostics, 'private-driver-error'), 'Relay failure diagnostics were missing or unsafe.');
    $audit->fail = false;
    serviceCheck($executor->execute('fixture.postcommit', ['value' => 3], $context)->replayed, 'Post-commit recovery repeated mutation.');
    serviceCheck(count($store->pending()) === 0, 'Recovered audit remains pending.');
    serviceCheck((int) $capServicePdo->query("SELECT COUNT(*) FROM `{$capServicePrefix}`")->fetchColumn() === 2, 'Relay recovery duplicated the mutation.');
    echo "Capability real-MySQL commit, replay, output rollback and post-commit recovery passed.\n";
} finally {
    foreach ([$capServicePrefix, $capServicePrefix . '_outbox', $capServicePrefix . '_receipts'] as $table) {
        $capServicePdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $GLOBALS['fnlla_config'] = $capServiceConfig;
    if (is_file($capServiceQueuePath . '/diagnostics.log')) { unlink($capServiceQueuePath . '/diagnostics.log'); }
    // No jobs are dispatched by this fixture; remove only its empty directories.
    foreach ([$capServiceQueuePath . '/pending', $capServiceQueuePath . '/failed', $capServiceQueuePath . '/reserved', $capServiceQueuePath] as $directory) {
        if (is_dir($directory) && count(scandir($directory)) === 2) { rmdir($directory); }
    }
}

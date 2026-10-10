<?php

declare(strict_types=1);

use Fnlla\Php\Audit\{AuditEvent, AuditLoggerInterface};
use Fnlla\Php\Events\{DomainEventBus, Dispatcher, OutboxProcessor};
use Fnlla\Php\Container\Container;
use Fnlla\Php\Queue\{QueueManager, FileQueueStore};

$legacyActions = config('actions', []);
$legacyRoot = sys_get_temp_dir() . '/fnlla-legacy-outbox-' . bin2hex(random_bytes(6));
try {
    config_set('actions.reliable_outbox', false);
    $store = new ActionEventMemoryStore();
    $audit = new class implements AuditLoggerInterface {
        public array $delivered = [];
        public function record(AuditEvent $event): void {
            if ($event->action === 'fixture.bad') { throw new RuntimeException('Synthetic destination failure.'); }
            $this->delivered[] = $event->action;
        }
    };
    foreach (['fixture.bad', 'fixture.good'] as $id) {
        $event = new AuditEvent($id, 'human', 'actor', 'record', 'one', null, 'correlation', [], [], gmdate(DATE_ATOM));
        $store->append($id, 'audit', $id, $event->toArray());
    }
    $store->messages['fixture.bad']['payload'] = null;
    $container = new Container();
    $bus = new DomainEventBus($container, new Dispatcher($container), new QueueManager($container, new FileQueueStore($legacyRoot)));
    $processor = new OutboxProcessor($store, $audit, $bus);
    try { $processor->publishPending(); throw new LogicException('Partial delivery was not reported.'); }
    catch (RuntimeException) {}
    assert_same(['fixture.good'], $audit->delivered, 'Poison record hid a later valid event.');
    assert_true($store->messages['fixture.good']['published'] && !$store->messages['fixture.bad']['published'], 'Delivery state incorrectly acknowledged poison data.');
    $processor->publishAfterCommit();
    assert_same(['fixture.good'], $audit->delivered, 'Best-effort publication repeated acknowledged records.');
} finally {
    config_set('actions', $legacyActions);
    foreach (glob($legacyRoot . '/*') ?: [] as $file) { unlink($file); }
    if (is_dir($legacyRoot)) { rmdir($legacyRoot); }
}
echo "Legacy outbox isolates poison records and preserves committed outcomes.\n";

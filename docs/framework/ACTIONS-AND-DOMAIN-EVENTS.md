# Actions And Domain Events

FNLLA Core provides a single mutation path for application commands. It is a
framework contract, not an ORM, event-sourcing system or commercial evidence
store. Read-only queries do not have to use Actions.

## Required Order

`ActionRunner` always applies this order:

1. derive `ActionContext` from the active authenticated actor and server-resolved
   `TenantContext`; `human`, `system`, `api` or `agent` describes origin only;
2. enforce the registered K-06.B permission and resource policy;
3. call the action validator before opening a transaction;
4. inside one managed database transaction, claim the idempotency receipt, run
   the domain mutation and write audit/domain-event messages to the outbox;
5. after commit, relay the audit record and versioned domain events to synchronous
   or queued FNLLA listeners.

Rollback discards database writes and the registered after-commit relay. A
failed post-commit relay does not turn a successful database commit into a
rollback; retry the same correlation ID so the receipt returns the original
result and the pending outbox can be relayed.

## Registration

Register application-owned definitions during provider boot. JSON Product
Specification files never name executable handlers.

```php
$actions = app(\Fnlla\Php\Actions\ActionRegistry::class);
$actions->register(new \Fnlla\Php\Actions\ActionDefinition(
    'work-order.create',
    'work-orders.create',
    'work-order',
    static fn (array $input): array => validate_work_order($input),
    [WorkOrderActions::class, 'create'],
    ['work-order.created']
));

$events = app(\Fnlla\Php\Events\DomainEventBus::class);
$events->listen('work-order.created', 1, 'search-index', [SearchProjector::class, 'handle']);
$events->queue('work-order.created', 1, 'notify-dispatcher', WorkOrderNotificationJob::class);
```

Handlers return `ActionMutation` with a subject ID, JSON-safe result,
before/after audit fields and declared event payloads. `ActionRunner::run()`
accepts a source string but never accepts actor or tenant IDs from the request.
Call it inside the authenticated tenant middleware scope.

## Storage And Migration

Create the two application-owned InnoDB tables from a reviewed migration before
enabling an Action. The reference DDL is
`resources/events/mysql-action-outbox.sql.example`; table names can be changed in
`config/actions.php`. `DatabaseActionStore::installSchema()` exists for isolated
setup/tests, not as an implicit production migration.

The receipt key binds action, actor, tenant and correlation ID. Repeating the
same normalized input returns the stored result; reusing the key with different
input fails. Domain event IDs are deterministic and outbox IDs are unique.
Queued listeners receive the event ID as their FNLLA queue idempotency key and
the worker re-resolves actor/tenant access before execution.

Delivery remains at-least-once. A process can fail after an external provider
accepts a request but before a local acknowledgement is durable. Synchronous
listeners and external-effect jobs must therefore use the domain event ID as a
provider/business idempotency key and recheck permission and lease immediately
before the effect. Core does not claim exactly-once email, payment, API or
provisioning behavior.

## Event Contract

`DomainEvent` emits `fnlla.domain-event.v1` with a stable event ID, payload
version, timestamp, subject and full Action context. The public schema is
`resources/events/fnlla.domain-event.v1.schema.json`. Version changes require a
new listener registration; unknown versions are not guessed.

Audit messages use the existing `fnlla.audit-event.v1` contract. They are
persisted in the same outbox transaction, then passed to the configured audit
adapter after commit. The default JSON audit adapter retains its documented
operational and tamper-resistance limitations.

## App Map Compatibility

Full FNLLA `app:map` remains `fnlla.app_map.v1` by default for existing
consumers. Use `php fnlla app:map --schema=v2 --json` for the explicit v2 map,
which adds validated Product Modules, declared and registered Actions, versioned
Events/listeners, relationships, unresolved gaps and a stable content hash. The
map is local, requires no AI service or paid panel and never treats a declaration
as runtime evidence.

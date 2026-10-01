# Application Capabilities

Status: introduced in Core 2.5.0; not part of the immutable 2.4.0 package. This foundation introduces no REST, MCP or SDK adapter.

## Ownership and execution

`Actions\ActionRegistry` is the single registry for legacy Actions and new
capabilities. An `ActionDefinition` opts into the capability contract by adding
`ActionMetadata`. Legacy constructor arguments and `inspect()` rows retain their
published shape. Applications and trusted providers own handlers and definitions.
Core owns generic execution, validation, permission and schema contracts.

```text
trusted provider/module -> ActionRegistry -> ApplicationSchema
                                  |
application caller -> ActionExecutor -> ActionAccess + ActionShape
                                  |
                  query handler   |   ActionTransaction -> command handler
                                  |          |
                                  |    receipt + audit + outbox
                                  |          |
                         result projection / after-commit delivery
```

Use `ActionExecutor::execute($id, $input, $context)`. Its order is:

1. Re-resolve the configured trusted context and reject a mismatched snapshot.
2. Look up the definition; reject legacy/unavailable/internal-only operations
   for a public audience. Check identity domain and base permission.
3. Validate bounded input against the explicit shape; reject unknown fields,
   wrong types and public writes to hidden fields.
4. Resolve an optional resource through a trusted callback and authorize it.
5. Invoke the application validator, revalidate normalized values, resolve and
   authorize the normalized resource again.
6. Execute the handler. Commands use the existing receipt/audit/outbox
   transaction; queries return arrays without acquiring a mutation transaction.
7. Validate output before completing the command receipt/transaction. Project
   away hidden fields, including nested fields, on every result and replay.

Handlers receive named `input`, legacy `context` (ActionContext), `application`
(ApplicationContext), and `resource`; command handlers also receive `database`.
Use constructor/method injection for domain services. No request/response objects
or protocol status codes belong in these contracts.

## Definition example

Register from a trusted application provider's boot method:

```php
use Fnlla\Php\Actions\ActionDefinition;
use Fnlla\Php\Actions\ActionMetadata;
use Fnlla\Php\Actions\ActionRegistry;

$container->make(ActionRegistry::class)->register(new ActionDefinition(
    id: 'inventory.lookup',
    permission: 'inventory.read',
    subjectType: 'inventory-item',
    validator: static fn (array $input): array => $input,
    handler: [InventoryQueries::class, 'lookup'],
    metadata: new ActionMetadata(
        description: 'Read an inventory item visible to the active principal.',
        input: [
            'type' => 'object',
            'properties' => ['item_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80]],
            'required' => ['item_id'],
        ],
        output: [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'internal_note' => ['type' => 'string', 'hidden' => true],
            ],
            'required' => ['label'],
        ],
        kind: 'query',
        visibility: 'public',
        resourceRequired: true,
    ),
    resourceResolver: [InventoryQueries::class, 'resolveAuthorizedScope'],
));
```

`InventoryQueries` is application-owned, not a supplied Core class. Its resolver
uses the trusted `application` context and returns an array containing a stable
nonempty `id` and the `__type` used by PolicyRegistry. Bind a resource policy
which checks tenant/ownership. Null/missing or unauthorized resources fail closed.
For commands, this resource ID is included in the receipt request hash. Query
handlers return a shape-compatible array. Command handlers return ActionMutation
and declare every emitted domain event in ActionDefinition.

```php
use Fnlla\Php\Actions\ActionExecutor;
use Fnlla\Php\Actions\ApplicationContextProviderInterface;
use Fnlla\Php\Actions\ApplicationSchema;

$context = $container->make(ApplicationContextProviderInterface::class)->current();
$result = $container->make(ActionExecutor::class)
    ->execute('inventory.lookup', ['item_id' => 'item-42'], $context);
$callerSchema = $container->make(ApplicationSchema::class)->describe($context);
```

The active role must hold `inventory.read` in the existing security configuration;
public visibility does not grant it. Neither example creates a route or endpoint.

## Context and security

ApplicationContext contains actor values, immutable TenantContext, identity
domain, source and audience. It is a snapshot, not a credential. The executor
compares every context field, including roles, tenant, correlation and audience,
to a fresh `ApplicationContextProviderInterface::current()` result.

The default SessionApplicationContextProvider uses the existing AuthManager and
TenantContextManager, requires their actor IDs to agree, and supplies the
`application` domain with a `public` audience. It creates no session login or
tenant authority. Bind another provider in trusted server configuration for
authenticated CLI/service execution. Such a provider must independently verify
identity, membership, revocation and scope; never build its authority from action
input. Internal audience selection is likewise a server-side decision.

All operations currently require an authenticated actor. `public` means eligible
for explicitly authorized public discovery/invocation, not anonymous access.
New definitions default to `internal`. No automatic remote exposure exists.
Unknown and internal operations have the same public `unavailable` failure.

Queries can explicitly allow other identity domains. Transactional commands
currently accept only the application domain because existing audit/domain-event
and queued-listener identity restoration do not distinguish developer/customer/
service domains. This restriction prevents cross-domain actor-ID confusion.
Supporting those command identities needs a versioned event-context design.

## Validation, projection and machine-readable metadata

`fnlla.action-shape.v1` is a small closed vocabulary, not a JSON Schema engine.
It supports object/properties/required, array/items/minItems/maxItems,
string/minLength/maxLength, integer/number/minimum/maximum, boolean, nullable,
scalar enum, description and hidden. Unsupported keys/types/bounds are rejected
at registration. Values are not coerced. Objects reject extra fields. Root input
and output are non-null objects represented by PHP associative arrays; an empty
array represents an empty object at this application boundary.

Definitions allow at most 12 nested levels and 128 object properties. Values
are limited to 1 MiB JSON, strings to 1 MiB bytes, arrays to 4096 items, with
stricter declared limits applied. Length constraints count UTF-8 characters.
Custom validators supply business rules through the existing callable extension;
raise ValidationException or ActionException with `invalid_input` for safe
validation failures. Normalize without exceeding the declared shape.

Hidden input fields cannot be supplied by a public caller; a trusted validator
may derive optional hidden fields. Public input definitions cannot require hidden
fields. Hidden output fields never appear in executor results, even for internal
callers. They may exist in trusted handler data and command receipt storage, so
receipt storage still needs application data protection and retention policies.

ApplicationSchema projects the registry to `fnlla.application-schema.v1` with
stable IDs/versions, descriptions, shapes, validation/permission/resource metadata,
visibility, identity domains, events and execution semantics. It excludes
handlers, source paths, context values and hidden fields. Its deterministic
content hash can anchor future generated contracts. `describe($context)` filters
by the caller's base permission; resource-specific permission remains an execution
check. `inspect()` is a local maintainer view, includes internal definitions, and
must never be used as remote discovery. Authored descriptions are public contract
text and must not contain secrets. Legacy definitions are not inferred or exposed.

`runtime:inspect` includes the local projection as `application_schema` while
retaining its v1 envelope. Full can reuse it in `app:map --schema=v2` when this
Core capability is installed. Existing OpenAPI export is unchanged; shape-to-
OpenAPI/MCP/SDK conversion is future work.

## Plugins and lifecycle

Providers register through the same ActionRegistry; `registerMany()` rejects
duplicates atomically. Product Module extensions may additionally implement
`ProductModuleActionsInterface::actionDefinitions()`. Existing extensions need
no new methods. Only enabled modules participate, and each capability must have
metadata and an ID declared as owned by its module manifest. Executable PHP comes
from trusted extension configuration, never from Product JSON.

Shared bootstrap now registers module services and optional actions after provider
boot for both CLI and HTTP. Action registration is idempotent per registry.
Enable/disable invalidates definitions owned by that registry immediately;
re-registration excludes disabled modules. Use a fresh bootstrap after module
changes to refresh service bindings. Long-lived hot reload is not provided.
Do not register a module's action separately in another provider.

## Transactions, errors and hooks

ActionRunner remains the legacy entrypoint. Its transaction implementation is
factored into internal ActionTransaction, shared by ActionExecutor. Legacy
receipt keys/formats are unchanged. Metadata-bearing definitions must use the
new executor. New receipts are namespaced by capability contract, identity domain
and operation version; hashes include normalized input and resolved resource ID.
Correlation ID remains the retry key. A retry needs the same trusted correlation;
changed input/resource is rejected. Reauthorization and output projection run
again on replay. There is no exactly-once external-effect guarantee.

ActionException exposes only fixed codes/messages: unavailable, unauthorized,
invalid_input, invalid_output, execution_failed and post_commit_failed. Raw
provider/handler exceptions, inputs and output are not returned. There are no
protocol status codes. Post-commit failure explicitly means a successful mutation
with an incomplete callback; retry the same correlation or recover the outbox.

Existing Dispatcher hooks `capability.started`, `capability.succeeded` and
`capability.failed` receive only capability ID, correlation ID and safe reason.
They are best-effort observations, not authorization gates or reliable events;
listener failure cannot replace a committed result. Inside an outer transaction
notifications defer until commit and disappear on rollback. Use declared domain
events and the outbox for durable effects. Business handlers must not send effects
before commit. Queries are an application promise of read-only behavior, not a
database sandbox. JSON files/external systems do not become transactional by
calling a command executor.

## Verification and compatibility

See [runtime hardening and upgrade notes](AUDIT-HARDENING.md) for effective shape
bounds, persisted-event identity checks and transaction/security compatibility.

CapabilityArchitectureTest exercises registration, safe discovery/projection,
input/output validation, forged/revoked/cross-domain/cross-tenant contexts,
permission/resource checks, hooks, replay and plugin disable. CapabilityServiceTest
runs on isolated MySQL for actual write/receipt/outbox rollback and post-commit
recovery. The main suites retain legacy Action and generated-app coverage.

The foundation adds no public route or CLI business command. Consumers of 2.4.0
must install the immutable 2.5.0 package to receive these contracts. Follow the
[upgrade notes](../releases/2.5.0.md) and verify application policies and consumers.

### Optional consumer extension projections

ProductModuleRegistry::enabledExtensions() returns the existing trusted extension
instances in dependency order, excluding disabled modules. inspect() includes the
module's declared action IDs. A consumer can discover its own optional interfaces
without reconstructing extensions or introducing a second capability registry.
This is local code access, never a remote schema endpoint. Core does not define
REST, MCP, SDK or provider-specific extension interfaces. The addition is backwards
compatible; applications still declare schemas and events in ActionDefinition.

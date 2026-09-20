# Security Primitives

FNLLA Core provides neutral, deny-by-default authorization, tenancy and audit building blocks.
They are available to a standalone Core application and do not depend on the
FNLLA Developer Panel or any commercial package.

## Roles, Permissions And Policies

`AccessControl` reads authenticated server-side actor data and the explicit
role definitions in `security.authorization.roles`. An unknown role or
permission is denied. `PolicyRegistry` adds resource-specific checks; passing a
resource without a registered policy is also denied. `OwnershipPolicy` is a
reusable policy for resources that expose explicit owner and tenant fields.

Developer, client and sales-administrator roles are separate defaults. The
`RoleAssignmentGuard` requires `roles.assign`, restricts assignments to the
configured allowlist and always rejects self-assignment. Applications remain
responsible for persisting role changes after the guard succeeds.

Existing Gate callbacks keep their behavior. During migration, an application
may map an otherwise undefined Gate ability to a permission with
`Gate::mapPermission()` or `security.authorization.legacy_gate_permissions`.
Undefined abilities continue to fail closed.

## Tenant Context

`TENANCY_MODE` supports:

- `none` for a single-tenant application;
- `organization` using the configured server-side organization field;
- `custom` using the configured server-side tenant field or an application
  implementation of `TenantIdentityResolverInterface`.

In either multi-tenant mode, `ResolveTenantContext` rejects an unauthenticated,
revoked or tenant-less actor. A request body, query parameter or queued payload
does not establish membership. `TenantContextManager` resolves membership from
the authenticated user provider, validates queued actor and tenant IDs again
at execution time and resets context in a `finally` boundary.

Use the `tenant` middleware on tenant-scoped routes after authentication. Use
`TenantResourceScope` at repository, export and tool boundaries. It injects the
authoritative repository criterion and rejects a conflicting record, export or
tool argument. `TenantCacheStore` and `TenantFilesystem` isolate keys and paths.
They are explicit adapters; Core does not add a hidden tenant filter to system
or framework tables.

```php
$router->get('/work-orders', [WorkOrderController::class, 'index'])
    ->middleware(['auth', 'tenant', 'authorize'])
    ->authorize('work-orders.view');

$criteria = app(\Fnlla\Php\Tenancy\TenantResourceScope::class)
    ->repositoryCriteria(['status' => 'open']);
```

An administrative bypass requires the `tenancy.bypass` permission, a target
tenant and a non-empty reason. Every allowed or denied bypass is written to the
audit adapter. Do not use bypass as an ordinary application code path.

## Jobs, Cache, Files, Exports And Tools

When tenancy is enabled, `QueueManager` copies actor and tenant identity from
the active server context and rejects conflicting dispatch metadata. The worker
re-resolves the actor and membership immediately before the handler, so revoked
access fails. Context is cleared after success or failure.

Tenant IDs must be applied at every application-owned data boundary:

- add `TenantResourceScope::repositoryCriteria()` to business repository reads
  and writes, and call `assertRecord()` before returning or changing a record;
- wrap shared cache storage with `TenantCacheStore`;
- wrap shared disks with `TenantFilesystem`;
- pass complete export rows through `exportRecords()`;
- pass tool arguments through `toolArguments()` and never trust a tenant ID
  supplied by a model, request body or queued job payload.

## Audit Contract

`AuditLoggerInterface` accepts `AuditEvent`, whose public wire schema is
`resources/security/fnlla.audit-event.v1.schema.json`. An event identifies its
actor, source, subject, tenant and correlation ID. Before/after values are
limited to the configured allowlist. `JsonAuditLogger` applies a second
sensitive-key redaction pass, bounds retention and entry count, uses an
exclusive lock and stores the file outside the public root by default.

The application owns audit data and must set an appropriate retention policy,
access control and backup policy. The neutral log is not an immutable evidence
store and does not claim compliance, legal hold or tamper resistance. A richer
commercial evidence surface may consume this contract but cannot replace the
Core authorization and isolation controls.

## Upgrade

The default mode remains `none`, so existing single-tenant applications do not
gain an implicit filter. Before selecting `organization` or `custom`, add the
tenant middleware to scoped routes, bind the correct identity resolver, migrate
business repositories/cache/files/exports/tools to the explicit adapters and
run negative cross-tenant tests. Enabling multi-tenancy before those steps is
not a safe partial migration.

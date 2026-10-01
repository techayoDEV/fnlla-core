# Your first application capability

[Documentation index](../README.md) · [Capability reference](CAPABILITIES.md)

This Core 2.5.0 example registers an authenticated pricing preview and executes
it through the shared executor. It needs no database query and creates no HTTP
endpoint. Prices supplied to this preview are illustrative inputs; real checkout
commands must load authoritative prices and authorize the relevant resources.

## Prerequisites

Start with a generated Core application. Application authentication and trusted
tenant context must already be configured as described in
[security primitives](SECURITY-PRIMITIVES.md). The default context provider
requires an authenticated application actor; a Developer Panel session or a
manually constructed context does not grant access.

## 1. Write the application service

Create `app/Services/PricePreview.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class PricePreview
{
    public function calculate(array $input): array
    {
        return ['subtotal_minor' => $input['quantity'] * $input['unit_price_minor']];
    }
}
```

Integer minor units avoid floating-point currency arithmetic. This is only a
subtotal: currency selection, tax, discounts and rounding belong in the domain.

## 2. Register the definition

Create `app/Providers/CapabilityServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\PricePreview;
use Fnlla\Php\Actions\{ActionDefinition, ActionMetadata, ActionRegistry};
use Fnlla\Php\Support\ServiceProvider;

final class CapabilityServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->container->make(ActionRegistry::class)->register(new ActionDefinition(
            id: 'pricing.preview',
            permission: 'pricing.preview',
            subjectType: 'price-preview',
            validator: static fn (array $input): array => $input,
            handler: [PricePreview::class, 'calculate'],
            metadata: new ActionMetadata(
                description: 'Preview a line subtotal from supplied quantities and minor-unit prices.',
                input: [
                    'type' => 'object',
                    'properties' => [
                        'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                        'unit_price_minor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
                    ],
                    'required' => ['quantity', 'unit_price_minor'],
                ],
                output: [
                    'type' => 'object',
                    'properties' => [
                        'subtotal_minor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000000],
                    ],
                    'required' => ['subtotal_minor'],
                ],
                kind: 'query',
                visibility: 'public',
            ),
        ));
    }
}
```

Append `App\Providers\CapabilityServiceProvider::class` to `providers` in
`config/app.php`, retaining existing Core providers. Add `pricing.preview` to
the permissions of the intended application role in
`security.authorization.roles` in `config/security.php`. Preserve other roles
and permissions. Registration and public visibility do not grant permission.

The container constructs `PricePreview`; its method receives the named `input`
argument. The identity validator leaves values unchanged. The executor still
checks types, required fields, unknown fields and numeric bounds before execution.

## 3. Execute inside authenticated application code

The following belongs inside a caller with an established application session
and trusted tenant scope, with the bootstrapped container in `$container`:

```php
use Fnlla\Php\Actions\{ActionExecutor, ApplicationContextProviderInterface, ApplicationSchema};

$context = $container->make(ApplicationContextProviderInterface::class)->current();
$result = $container->make(ActionExecutor::class)->execute(
    'pricing.preview',
    ['quantity' => 3, 'unit_price_minor' => 1250],
    $context,
);

$subtotal = $result->value['subtotal_minor']; // 3750
$schema = $container->make(ApplicationSchema::class)->describe($context);
```

Use `$result->value` for the validated output. `ActionResult` also contains
`actionId`, `subjectId`, `eventIds` and `replayed`; a query has no mutation
subject, events or replay receipt. `toArray()` omits the `replayed` flag, so a
transport that needs it must read the property explicitly.

Running the invocation in an unauthenticated CLI will be denied. An application
that needs service/CLI execution must bind a provider which independently
authenticates its principal; action input must never select actor or tenant.

## 4. Verify the behavior

Add application tests for:

| Case | Expected behavior |
| --- | --- |
| Authorized actor, quantity 3, price 1250 | `subtotal_minor` is 3750 |
| Quantity 0 or 101 | `invalid_input`; handler is not called |
| String `"3"` supplied for quantity | `invalid_input`; Core does not coerce types |
| Extra input field | `invalid_input` |
| Role without `pricing.preview` | `unauthorized` |
| Revoked identity or forged context | `unauthorized` |
| Public caller with internal-only definition | `unavailable` |

Run the application's test and lint scripts. Inspect registration locally with
`php fnlla runtime:inspect`. Inspect output may include internal definitions;
do not publish it as a remote schema.

## Next: resource queries and commands

This arithmetic preview has no persisted resource to authorize. For a customer,
order or tenant-owned resource, add a trusted `resourceResolver`, set
`resourceRequired: true` and register a resource policy. A supplied ID is not
proof of ownership.

For writes, use `kind: 'command'`, return `ActionMutation`, install the reviewed
receipt/outbox schema and declare domain event names. Read
[actions and domain events](ACTIONS-AND-DOMAIN-EVENTS.md) before adding external
effects or retry handling.

Full can bind an eligible capability to a REST route, CLI allowlist or MCP tool.
Each adapter needs explicit configuration and trusted authentication. The Core
definition remains the source for validation, permission and output metadata.

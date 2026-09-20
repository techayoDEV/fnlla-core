# Product Specification Contract

FNLLA Core owns the neutral, public Product Specification contract. The
canonical schema identifier is `fnlla.product.v1`; its JSON Schema and examples
live in `resources/product-specification/`.

The Product Specification records product intent. It does not create database
tables, register routes, enable modules, prove runtime behavior or authorize
code, deployment, publication or commercial decisions. The separate Product
Module registry may apply explicitly configured, validated lifecycle state; a
declaration alone never activates runtime behavior.

## Contract Layers

The four layers deliberately stay separate:

1. `fnlla.product.v1` declares modules, entities, relations, roles,
   permissions, Actions, Events, workflows, surfaces, routes, capabilities and
   tenancy/audit/search/SEO/AI requirements.
2. `fnlla.product-implementation-facts.v1` records explicit observations from
   code, tests, configuration or registered routes. A file's existence is not
   evidence that its declared behavior works.
3. `fnlla.product-drift.v1` compares intent with facts. Each item is
   `declared`, `implemented`, `unverified` or `contradictory`.
4. `fnlla.product-graph.v1` is a derived, non-authoritative view. Consumers may
   rebuild it; they must not treat it as the source of product intent.

The property-maintenance example keeps the Product Specification free of
customer rows. Its separate scenario contains two synthetic tenant identifiers
and explicit isolation expectations. It is a test fixture, not a promise of a
ready-made SaaS or billing engine.

## Versioning

- `schema` is the compatibility discriminator. Consumers must reject an
  unknown schema instead of guessing.
- `specification_version` versions the document shape within
  `fnlla.product.v1`. Additive, optional fields may increase the minor version;
  clarifications may increase the patch version.
- Removing a field, changing its meaning or making an optional field required
  needs a new schema identifier.
- Identifier references are explicit. Duplicate identifiers and broken
  references are invalid.
- Capability identifiers are allow-listed by the validator. Unsupported
  capabilities fail explicitly rather than becoming pseudo-implementations.

## Standalone Validation

Validate a Product Specification with zero or more module declarations:

```text
php fnlla product:validate path/to/product.json \
  --module=path/to/first.module.json \
  --module=path/to/second.module.json
```

The command exits `0` only when declaration validation succeeds and prints a
deterministically ordered `fnlla.product-validation-report.v1` JSON document.
Every error has `id`, `category`, JSON-style `path` and `message`. Validation
covers input/schema versions, types, duplicate identifiers/references, broken
references, module dependency cycles, Product Specification links, supported
capabilities and workflow transitions.

A valid report is not runtime evidence: `runtime_evidence` is always
`not_evaluated`. Missing `fnlla.module.v1` declarations remain visible as
warnings and `missing_module_manifests`. A module declaration describes links
to product modules, entities, Actions, Events and capabilities; it does not
activate anything by itself.

## Product Module Lifecycle

Applications opt into lifecycle behavior through `config/product_modules.php`:

- `product` points to one `fnlla.product.v1` document;
- `manifests` lists every corresponding `fnlla.module.v1` declaration;
- `extensions` maps module IDs to trusted application classes implementing
  `ProductModuleExtensionInterface`;
- `state_path` stores only the versioned enabled-module list.

JSON manifests never contain a provider class, callable, shell command,
migration or uninstall instruction. An extension supplies service
implementations and cache-safe controller handler pairs, while the manifest
owns service abstracts/lifetimes, product route IDs, middleware and asset
ownership. The registry applies those declarations through the existing
Container and Router.

```text
php fnlla module:validate
php fnlla module:list
php fnlla module:inspect <module-id>
php fnlla module:enable <module-id>
php fnlla module:disable <module-id>
```

Enable resolves declared dependencies in deterministic order. Disable refuses
an active dependency and never deletes data, assets or runs a migration.
Enabled module routes must reference Product Specification routes marked
`implemented`; duplicate method/path, name, service abstract or asset target
fails closed. Privileged routes require `auth` or `authorize` middleware.

Product Module routes stay outside the application route cache and are
registered from current state during bootstrap. A disabled module therefore
has no direct endpoint, including after route caching. Asset declarations state
their target, publication ownership and explicit removal owner; disable always
uses `preserve`. Product Modules are independent of full-product Developer
Panel feature flags.

Historical `fnlla.business_app_blueprint.v1` input returns the explicit
`migration_required` error and points consumers to the migration below. Unknown
product/module schemas and unsupported capabilities fail rather than being
guessed or silently ignored.

## Historical Blueprint Migration

The full FNLLA repository retains
`resources/business-reference/blueprint.json` with schema
`fnlla.business_app_blueprint.v1`. It remains a historical input and must not
be silently rewritten as `fnlla.product.v1`.

Migration is explicit:

| Historical field | Product Specification target | Rule |
| --- | --- | --- |
| `routes` | `routes`, `surfaces`, `actions` | Mark routes `proposed` unless route and behavior evidence exists. |
| `tables` | `entities`, `relations` | Convert intent only; never copy customer rows. |
| `gates` | `roles`, `permissions`, Action permission references | Preserve ownership requirements as tenancy/security requirements. |
| `workflows` | structured `workflows` and transitions | Human-readable sequences need explicit states, Actions and Events. |
| `security_controls` | `requirements` and capabilities | Keep controls declarative; tests determine implementation status. |

The historical format has no direct equivalents for modules, Events, derived
graphs or implementation evidence. A migration tool must require those choices
instead of inventing them. The old blueprint can remain readable while product
teams adopt the new contract incrementally.

## Data And Capability Boundaries

Product Specifications and evidence files must not contain secrets, production
credentials, customer records or personal Fionn memory. AI may be `disabled`,
`deferred` or explicitly required by an approved use case. Search and SEO
requirements do not by themselves require semantic search, AI SEO or LLM
inference.

Graph databases, LLM inference and automatic code changes are outside this
contract. The contract is usable without the full FNLLA product or any
commercial UI.

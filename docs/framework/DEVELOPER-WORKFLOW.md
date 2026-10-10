# Build with AI. Stay in control.

Core supplies a Web application foundation, a project starter and developer tools.
Humans define the task, inspect the proposed changes and maintain the application.
Coding agents can use the same local contracts and checks. No runtime AI, model
account, provider SDK or commercial FNLLA package is required.

The developer tools below were introduced in Core 2.4.0 and remain available in
2.7.0. Core 2.5.0 introduced the [capability schema](CAPABILITIES.md)
in local inspection. Use the [getting-started guide](GETTING-STARTED.md) for a
new application and upgrade older immutable dependencies before adopting contracts.

## A repeatable development cycle

1. Describe the expected behavior, ownership, denied cases and acceptance checks.
2. Read `AGENTS.md` and the installed source. Capture `php fnlla runtime:inspect`.
3. Implement in application-owned `app/`, `routes/`, `config/` and tests. Use the
   existing container, authorization, tenant context, Actions and queue contracts.
4. Run the relevant checks, inspect `git diff`, and check failures and omissions.
5. A human decides whether to accept the change. Keep the diff and check results
   with the commit; repeat the checks after changes to the source or dependencies.

Instructions and diagnostics provide context, not proof of correctness. A useful
acceptance task includes cross-tenant denial, unauthorized requests, rollback,
retry and stale ownership where relevant. A passing synthetic task does not
establish a general quality or speed advantage for an agent.

## Context from installed code

```console
php fnlla runtime:inspect
```

The existing `fnlla.runtime.inspection.v1` report gains an additive `context` object,
versioned as `fnlla.runtime.context.v1`. Consumers must tolerate unknown fields.

| Field | Evidence and limit |
| --- | --- |
| `engine` | Actual Composer-installed Core version, or bundled engine `VERSION`; a maintainer checkout may report `dev-main` |
| `packages` | Declared lock versions; `installed_verified: false` deliberately distinguishes declarations from installed integrity |
| `routes` | Registered method, path, name, handler, route middleware aliases, declared authorization and explicit OpenAPI presence |
| `source` | Relative owner/path/line and SHA-256 of available handler source; missing/external source is reported without an absolute path |
| `contracts`, `interfaces` | Shipped JSON schema identities/hashes and reflected public adapter method signatures |
| `configuration` | Loaded files/cache mode, declared filenames, runtime override count and cache presence; no values or environment contents |
| `guidance`, `checks` | Existing local instruction files and supported local verification scripts |
| `fingerprint` | Hash of this metadata snapshot; not a signature, approval or hash of every application file |

Route handlers are not dispatched. Application bootstrap and route registration
are trusted PHP and may themselves have effects. Run inspection only on code you
trust. Global middleware and implicit permissions are not inferred. Route names,
paths and class names are source metadata: never embed credentials or customer
identifiers in declarations. Inspect the report before sharing it externally.

The CLI no longer resolves a queue adapter for live counts. It can supply context
while Redis is unavailable. Existing callers may still explicitly pass a queue
to `RuntimeInspector` for its legacy diagnostics. Configuration provenance covers
the loaded source category; it does not reconstruct each value's environment key.

## Bounded readiness probes

```console
php fnlla runtime:doctor --timeout=2
```

This reports `fnlla.runtime.readiness.v1` JSON and exits nonzero unless the
configured supported checks are ready. Each database/cache/queue check has its
own 0.1–10 second deadline. Supported checks are MySQL `SELECT 1`, Redis `PING`
and access to existing file-store directories. A custom driver is `unsupported`;
a missing configuration is `not_configured`, never an invented pass.

Probes run in separate PHP processes, receive connection settings on private
stdin and expose allowlisted status codes. Driver errors, credentials, DSNs and
stderr are excluded. PHP must allow `proc_open`; the child must have the relevant
extensions. File probes create no directories and queue probes consume no jobs.
The parent command still runs normal application bootstrap. The deadline bounds
each probe, not arbitrary bootstrap code.

Readiness does not prove migration state, write permission, end-to-end delivery,
mail or provider availability. `/api/health` remains liveness.

## Explicit API documentation

Attach a contract to a route, then export it locally:

```php
$router->get('/api/items/{id}', [ItemController::class, 'show'])
    ->name('items.show')
    ->openapi([
        'operationId' => 'items.show',
        'security' => [], // Intentionally public; protected APIs declare a scheme.
        'parameters' => [[
            'name' => 'id', 'in' => 'path', 'required' => true,
            'schema' => ['type' => 'string'],
        ]],
        'responses' => ['200' => [
            'description' => 'The item',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'required' => ['id'],
                'properties' => ['id' => ['type' => 'string']],
            ]]],
        ]],
    ]);
```

```console
php fnlla openapi:export
```

`config/openapi.php` owns `info` and optional `components`. The command prints
OpenAPI 3.1.1 JSON to stdout. Routes without explicit contracts are omitted and
counted. Contract metadata survives route caching. Operation IDs must be unique;
path parameters, responses and security must be explicit. Authorized routes
cannot declare public/optional authentication. Only resolvable local
`#/components/...` references are accepted; export performs no remote fetch.
Use a nonempty schema object, or boolean `true` for an unconstrained schema.

This validates the supported declaration structure, not every OpenAPI/JSON Schema
keyword. Validate exported documents with your chosen OpenAPI 3.1 validator before
client generation. It adds no request/response validation, permission checks or
authentication enforcement. Those remain application runtime responsibilities.
The normative format is [OpenAPI 3.1.1](https://spec.openapis.org/oas/v3.1.1.html).

## Custom adapter conformance

`Fnlla\Php\Testing\AdapterContractSuite` exposes reusable checks:

```php
AdapterContractSuite::cache(fn () => new MyCache($isolatedLocation));
AdapterContractSuite::reliableQueue(fn () => new MyQueue($emptyIsolatedQueue));
```

Each factory must return an independent client sharing the same isolated store.
Cache checks cover shared reads, counters and optional atomic admission. Reliable
queue checks cover exclusive reservation, stale settlement, lease renewal and
durable idempotency. The queue test requires an empty disposable queue. Never
point these checks at application data. A contract pass does not prove a custom
driver's concurrency behavior; add real parallel-process tests for that driver.

Core's service gate uses real MySQL and Redis and its quality matrix includes
Windows and Linux on PHP 8.3/8.4. Configuration of CI is not evidence of a remote
run: releases still require successful CI on the exact intended commit.

## Repeatable coding-agent evaluation

Run from the Core maintainer checkout with Python, Git and PHP with `pdo_sqlite`:

```console
python scripts/agent-eval.py self-test
python scripts/agent-eval.py prepare ../evaluation/codex-route --task route-json --agent codex --model unrecorded
python scripts/agent-eval.py grade ../evaluation/codex-route
```

Use a destination **outside the Core source tree**. Preparation creates a fresh
Core application and `TASK.md`. Give the selected coding tool only the `project/`
workspace and that task. Preparation and grading do not invoke models. Repeat in
a new directory with `--agent claude-code` for Claude Code; no installation or
account is assumed. Use the same source revision, task/grader hashes, PHP/tools,
instructions and constraints for a comparison.

Tasks cover JSON routing, tenant/owner policy denial, transaction after-commit
semantics and explicit OpenAPI. Graders and original file hashes stay outside
the editable project. Grading checks allowed paths, PHP lint and behavioral
invariants; dependency/instruction changes fail. The self-test proves that empty
solutions fail, reference solutions pass and engine modifications fail. Reference
solutions are synthetic harness checks, **not Codex or Claude Code results**.

`result.json` records input/result/grader hashes, changed paths, check outcomes,
claimed actor/model and `human_review: pending`, `approved: false`. It does not
infer authorship. Preparation-to-grade wall time includes waiting and is not
model execution time. Raw check output stays in local `check.log`; keep it private.
Record actual tool/model versions, elapsed execution, token/cost data when
available, reviewer corrections and failed/unfinished attempts separately.

The evaluator executes proposed PHP: it is not an OS security sandbox. Run
untrusted implementations in a disposable OS/container environment with no
credentials or network access and keep the grader outside the agent's writable
paths. Time/output limits bound normal checks, not hostile child processes.
No measured speed, quality or cost improvement follows from harness verification.

## Checking downstream integrations

Core remains independent of the commercial Framework and fnlla.com. Project
administration, local Git hosting, project files, team chat and analytics views
belong to Framework; commerce accounts and public documentation belong to the
application. Synchronizing those modules does not require adding them to Core.

Check downstream locks against the immutable Core artifact and its manifest.
When the Core working tree contains unreleased runtime changes, build a distinct
local prerelease with `scripts/build-local-artifact.php --local-review` and test
it in isolated consumers. Keep the source version, stable consumer locks and
bundled package unchanged until a reviewed dependency upgrade. A candidate test
does not authorize publication or make its contracts available in stable Core.

## Delivery from scope to release

Start with one user outcome, its owner, excluded scope and observable acceptance.
Use the existing Product Specification, App Map and Product Graph; do not create
a duplicate backlog. Implement one complete vertical slice through the owning
policies, Actions, HTTP and UI, including denial, missing-data and stale/conflict
cases. Keep synthetic fixtures separate from customers and production providers.

For each change, bind context, plan, tests and review to the exact source and
installed dependencies. Source drift requires renewed evidence. Automated tests
do not replace human usability or product acceptance. Update the maintained
contract and upgrade instructions in the same PR. Merge only after required CI
and the applicable owner/review policy; remove the completed branch afterwards.

Release acceptance includes clean install, protected application updates,
rollback/recovery, operational ownership and an immutable verified artifact.
Technical review, client acceptance, release authorization and deployment are
separate decisions. Record actual lead time, rework, defects and billed CI/AI
usage when measuring delivery; do not infer savings from commit timestamps.

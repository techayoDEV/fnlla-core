# Contributing To FNLLA Core

FNLLA Core accepts framework-level changes only. Keep proposals focused on the
public web framework core: routing, HTTP, container, validation, database, sessions,
cache, mail, queues, core CLI, docs, security and package maintenance.

Contributions should keep Core useful as a standalone framework package. Ideas
for the wider FNLLA product, project workflow or website experience are best
discussed through [fnlla.com](https://fnlla.com).

## Local Checks

Run these before opening a pull request:

```bash
composer validate --strict
php scripts/test.php
php scripts/lint.php
php scripts/static-analysis.php
```

Run these commands from the repository root. `composer analyse` uses the
configuration in `tools/quality/phpstan.neon.dist`; a direct PHPStan invocation
needs that explicit configuration path.

Browser build inputs and their unchanged dependency lock live in `tools/assets/`:

```bash
npm --prefix tools/assets ci
npm --prefix tools/assets run build
```

Prebuilt assets remain available without Node. Repository build scripts resolve
their inputs from `tools/assets/` and write the Core template's local assets.
Generated standalone applications retain their own documented build layout.

Use `php fnlla` for the CLI. The repository's Windows convenience launcher is
`scripts/windows/fnlla.cmd`; Composer's generated vendor launcher is unaffected.

Maintenance history is in [CHANGELOG.md](CHANGELOG.md). A development checkout
does not replace an immutable release or upgrade an installed consumer.

## Security

Do not report exploitable vulnerabilities in public issues or pull requests. Use
the private reporting routes in [SECURITY.md](SECURITY.md).

## Product Boundary

If a change introduces a reusable framework primitive, this repository is the
right home. If it belongs to the wider FNLLA product experience, it belongs in
the commercial product workflow described at [fnlla.com](https://fnlla.com).
When the product needs a new Core primitive, add the public primitive here first
and then consume it from the full product.

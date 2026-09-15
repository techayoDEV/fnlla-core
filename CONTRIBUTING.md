# Contributing To FNLLA Core

FNLLA Core accepts framework-level changes only. Keep proposals focused on the
public PHP core package: routing, HTTP, container, validation, database, sessions,
cache, mail, queues, core CLI, docs, security and package maintenance.

Contributions should keep Core useful as a standalone framework package. Ideas
for the wider FNLLA product shell, project workflow or website experience are
best discussed in the full FNLLA project context.

## Local Checks

Run these before opening a pull request:

```bash
composer validate --strict
php scripts/test.php
php scripts/lint.php
php scripts/static-analysis.php
```

## Security

Do not report exploitable vulnerabilities in public issues or pull requests. Use
the private reporting routes in [SECURITY.md](SECURITY.md).

## Product Boundary

If a change introduces a reusable framework primitive, this repository is the
right home. If it belongs to the wider FNLLA product experience, keep it in
`techayoDEV/fnlla`. When the product needs a new Core primitive, add the public
primitive here first and then consume it from the full product.

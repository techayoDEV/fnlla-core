# Contributing To FNLLA Core

FNLLA Core accepts framework-level changes only. Keep proposals focused on the
public PHP core package: routing, HTTP, container, validation, database, sessions,
cache, mail, queues, core CLI, docs, security and package maintenance.

Do not add the Developer Panel, Client Portal, setup UI, FIONN gateway UI,
commercial operations surfaces or platform-only marketing assets to this
repository.

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

If a change would be useful to full FNLLA but not to standalone Core, keep it in
`techayoDEV/fnlla`. If full FNLLA needs a new Core primitive, add the public
primitive here first and then consume it from the full product.

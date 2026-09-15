# FNLLA Core Support Policy

FNLLA Core is released publicly under the MIT License. Anyone may use it, fork it
and build self-service projects on top of it.

That permission does not create an obligation for TechAyo LTD to provide
support, maintenance, implementation help, security review, custom development or
release work for third-party projects.

## Best-Effort Public Support

Public issues and documentation are best-effort resources, not a support
contract. Support may be provided only when TechAyo LTD separately agrees to
provide it through direct delivery work, a private agreement or an explicit
maintenance arrangement.

## Downstream Responsibility

If a third party deploys, extends, modifies or operates a project built on
FNLLA Core, that third party is responsible for the resulting application,
hosting, infrastructure, integrations, cookie usage, security controls, secret
handling, monitoring, backups, patching and incident response.

## Recommended Public Routing

- Product reference: `https://fnlla.com`
- Core package source: `https://github.com/techayoDEV/fnlla-core`
- Full FNLLA source: `https://github.com/techayoDEV/fnlla`
- Security reports: [../../SECURITY.md](../../SECURITY.md)
- Business, partnership or commercial implementation requests: `https://techayo.co.uk`

## Self-Service Checks

Before opening a public issue, run:

```bash
php scripts/test.php
php scripts/lint.php
php scripts/static-analysis.php
```

When reporting a confirmed bug, include the FNLLA Core version, PHP version,
affected class or command, reproduction steps and the smallest relevant output.

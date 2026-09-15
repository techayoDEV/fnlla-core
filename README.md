# FNLLA Core

![FNLLA Core lockup](branding/assets/logo/fnlla-core-lockup.svg)

FNLLA Core is the open PHP framework core used by FNLLA. It carries the same
FNLLA identity system and maintainer attribution, but its product promise is
deliberately narrower: runtime primitives, routing, HTTP, container, validation,
database, session, cache, mail, queue and core CLI building blocks.

Package: `techayodev/fnlla-core`
Repository: `techayoDEV/fnlla-core`
Version: `2.2.4`

## Scope

FNLLA Core is for framework-level code and package consumers who need the base
runtime without the integrated product shell.

It does not include the Developer Panel, Client Portal, FNLLA UI surface,
project setup screens, commercial operations layer or platform marketing assets.
Those belong in the full FNLLA repository: `techayoDEV/fnlla`.

## Install

```powershell
composer require techayodev/fnlla-core
```

The package autoloads `Fnlla\Php\` from `src/` and includes the shared helper
file from `src/Support/helpers.php`.

## Validate

```powershell
php scripts/test.php
php scripts/lint.php
php scripts/static-analysis.php
```

## Documentation

- [Core package docs](docs/README.md)
- [Runtime contracts](docs/framework/RUNTIME-CONTRACTS.md)
- [Trademark notice](docs/framework/TRADEMARKS.md)
- [Support boundary](docs/framework/SUPPORT.md)
- [Security policy](SECURITY.md)

## Branding

FNLLA Core uses the same outline mark, Blueprint Blue palette and TechAyo
attribution as FNLLA. When this repository is shown on its own, use the name
**FNLLA Core** and the Core-specific lockup in `branding/assets/logo/`.

The canonical brand notes live in [branding/README.md](branding/README.md) and
[branding/BRAND-GUIDE.md](branding/BRAND-GUIDE.md). Keep package names,
commands and namespaces literal, especially `techayodev/fnlla-core` and
`Fnlla\Php\`.

## Maintenance

This repository is generated from the maintained FNLLA source manifest. Keep
changes aligned with the core package boundary and avoid adding platform-only
files here.

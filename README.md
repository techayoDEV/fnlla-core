# FNLLA Core

![FNLLA Core lockup](branding/assets/logo/fnlla-core-lockup.svg)

FNLLA Core is the open PHP framework core used by FNLLA. It carries the same
FNLLA identity system and maintainer attribution, with a framework-first promise:
runtime primitives, routing, HTTP, container, validation, database, session,
cache, mail, queue and core CLI building blocks.

Package: `techayodev/fnlla-core`
Repository: `techayoDEV/fnlla-core`
Version: `2.2.5`

## FNLLA Family

There are two public FNLLA tracks:

- **FNLLA Core** - this repository, the open PHP framework core package.
- **FNLLA** - the full product and application platform repository:
  `techayoDEV/fnlla`.

[fnlla.com](https://fnlla.com) is the public website and documentation hub that
ties FNLLA Core and FNLLA together.

## Origin And Maintainers

The FNLLA name comes from Finella Gardens in Dundee, Scotland, where the idea
for the framework began.

FNLLA Core is created and maintained by **TechAyo**.

Lead Developer / Product Manager - **Marcin Kordyaczny**.

Official public sources are the GitHub repositories under `techayoDEV` and
[fnlla.com](https://fnlla.com).

## Scope

FNLLA Core is for framework-level code and package consumers who need the base
runtime. The full FNLLA product builds on Core with the broader application
platform, project workflow and public website experience.

Core includes framework CLI commands for controllers, middleware, commands,
factories, seeders, migrations, migration execution, route inspection, route
cache, config cache, queues, database seeding and framework upgrades.

Core also includes a limited `make:project` command. It creates a minimal public
FNLLA Core application from this repository only. The full FNLLA repository owns
the broader platform profile and project tooling.

## Install

Install from the public GitHub VCS repository:

```powershell
composer config repositories.fnlla-core vcs https://github.com/techayoDEV/fnlla-core.git
composer require techayodev/fnlla-core:~2.2.0
```

The package autoloads `Fnlla\Php\` from `src/` and includes the shared helper
file from `src/Support/helpers.php`.

## Create A Core Project

From a clone of this repository:

```powershell
php fnlla make:project ../my-core-app "My Core App"
```

The generated project includes a small public homepage, routing, config,
tests, lint/static-analysis scripts, the `fnlla` CLI launcher and a local
`packages/fnlla-core` path package. It does not create the full FNLLA platform
application; use `techayoDEV/fnlla` when you need that profile.

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
changes aligned with the public framework-core scope so the package remains
clear, reusable and professional on its own.

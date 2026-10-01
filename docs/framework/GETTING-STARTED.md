# Getting started with FNLLA Core

[Documentation index](../README.md) · [Architecture](ARCHITECTURE.md) · [First capability](FIRST-CAPABILITY.md)

This guide targets Core 2.5.0. It creates a minimal PHP application with an
explicit route table, application configuration and local verification tools.
No AI account, provider SDK or FNLLA Full installation is required.

## Requirements

| Component | Requirement |
| --- | --- |
| PHP | `^8.3`; use the extensions declared by the installed package |
| Required extensions | `fileinfo`, `json`, `mbstring`, `pdo`, `session` |
| Composer | Required for dependency installation and lock management |
| MySQL | Configure when using database features; requires `pdo_mysql` |
| Redis | Optional cache, session or queue driver; requires `redis` |
| Web server | PHP development server locally; a configured production server for deployment |

The generated homepage and `/api/health` do not open a database connection.
Database-backed authentication and transactional commands need their own setup.

## 1. Generate an application from a released source

Run from the directory where you keep projects. Use a new destination:

```console
git clone --branch v2.5.0 --depth 1 https://github.com/techayoDEV/fnlla-core.git fnlla-core
cd fnlla-core
composer install
php fnlla make:project ../my-core-app "My Core App"
cd ../my-core-app
composer install
```

The generator bundles Core as a local Composer path package under
`packages/fnlla-core`. This is the generated project's dependency layout.
Installing the library into an existing application is a separate option below.
Keep the generated dependency and `composer.lock` under your project's chosen
versioning and distribution policy; do not modify runtime files as a shortcut
to an upgrade.

## 2. Configure the application

Copy `.env.example` to `.env` once:

```powershell
Copy-Item .env.example .env
```

On Linux/macOS use `cp .env.example .env`. Preserve an existing `.env`.
For local development, set `APP_URL=http://127.0.0.1:8081`; keep `APP_DEBUG=false`
unless diagnosing a local problem. Leave `ASSET_URL` empty unless assets use
a separate origin. Configure database credentials only for the services you use.
Environment files and customer data must stay out of source control.

## 3. Verify and run

```console
php scripts/test.php
php scripts/lint.php
php fnlla route:list
php fnlla runtime:inspect
php -S 127.0.0.1:8081 -t public public/router.php
```

Open `http://127.0.0.1:8081/`. Stop the development server with Ctrl+C.
Use an available port; the development server is not a production deployment.

The homepage should render and `/api/health` should answer. Health is liveness:
it does not establish database readiness, completed migrations or queue delivery.
After configuring services, run `php fnlla runtime:doctor --timeout=2` and
interpret each result using the [developer workflow](DEVELOPER-WORKFLOW.md).
A service that has not been configured need not report ready.

### Static analysis in the released starter

The generated project includes `php scripts/static-analysis.php` (also exposed
as `composer analyse`). It delegates to PHPStan/Psalm when installed and otherwise
runs a lightweight source check. In the 2.5.0 starter, that fallback also scans
bundled Core view templates and reports four missing `strict_types` declarations.
This is a known limitation of the generated fallback check, distinct from PHP
lint or a failing application request. Do not report it as a passing analysis
gate. The repository's maintained Core PHPStan suite is a separate check.

## 4. Know which files you own

| Location | Responsibility |
| --- | --- |
| `app/` (`App\`) | Controllers, providers, domain services and business rules |
| `routes/web.php` | Explicit application HTTP routes |
| `config/` | Application configuration and provider registration |
| `views/` | Server-rendered presentation |
| `database/` | Application migrations and seeders |
| `tests/` | Application behavior and denied-case checks |
| `public/` | Web document root and public assets |
| `storage/` | Private logs, runtime state, cache and session data |
| `packages/fnlla-core/`, `vendor/` | Dependency implementation; update through the dependency workflow |

Register custom providers in `config/app.php` while retaining the Core providers.
Use providers for bindings and capability registration; keep request-specific
work out of provider boot. See [your first capability](FIRST-CAPABILITY.md).

## Install only the library

For an existing Composer application, configure the public VCS repository:

```console
composer config repositories.fnlla-core vcs https://github.com/techayoDEV/fnlla-core.git
composer require techayodev/fnlla-core:^2.5.0
```

This installs a library; it does not generate an application or configure its
bootstrap. The namespace is `Fnlla\Php\`. Commit the resulting lock.
The official release ZIP also carries manifest and provenance files; downstream
distributors may pin that immutable artifact, as FNLLA Full does. A Git source
archive and the verified release ZIP are distinct distribution forms.

## Common first-run problems

| Symptom | Check |
| --- | --- |
| Composer reports missing extensions | Run `php --ini` and `php -m` for the same PHP executable used by Composer |
| Homepage fails before dispatch | Review local logs, provider boot and writable storage; keep diagnostics private |
| A capability is unauthorized | Verify application authentication, trusted tenant context and the declared permission |
| A capability is unavailable | Confirm registration, metadata, enabled module and audience |
| `runtime:doctor` fails | Inspect its safe status code; configure the required driver and service |
| Starter analysis reports four bundled views without `strict_types` | See the 2.5.0 fallback-analysis limitation above; do not edit installed dependencies to hide it |
| `/.fnlla` does not exist | Core does not install Full's capability discovery HTTP adapter |

Next: [architecture](ARCHITECTURE.md), [capabilities](CAPABILITIES.md),
[security](SECURITY-PRIMITIVES.md), and [operations](OPERATIONS.md).

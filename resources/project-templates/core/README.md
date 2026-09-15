# {{APP_NAME}}

This is a minimal public application built on FNLLA Core. It starts with a
small server-rendered homepage, routes, config, tests and the core CLI surface.
The full FNLLA platform is available separately from `techayoDEV/fnlla`; this
project intentionally stays focused on the open core runtime.

## Local Development

1. Copy `.env.example` to `.env` and set the application name and database credentials.
2. Run `composer install`. Core is a local `packages/fnlla-core` path package;
   the default install stays small and the fallback bootstrap works offline
   before Composer installation.
3. Run `php scripts/test.php`, `php scripts/lint.php` and `php fnlla route:list`.
4. Start `php -S 127.0.0.1:8080 -t public public/router.php` using an available port.

Application code belongs in `app/` (`App\`), routes in `routes/`, templates in `views/`.
The core (`Fnlla\Php\`) is a separate Composer library. Do not modify `vendor/`.
Database access is lazy: the homepage and `/api/health` do not require a database.
The health endpoint is liveness only, not database or deployment readiness.
Composer metadata, `.env.example`, `phpunit.xml`, `phpstan.neon`, `README.md`,
`LICENSE.md` and the `fnlla` launcher remain at root because common PHP tooling
discovers them there by default.

After installation, `php scripts/test.php` runs the bundled smoke harness.
`composer analyse` runs the dependency-light baseline unless the project adds
deeper tools later. Commit `composer.lock`; add heavier development tools only
when the project needs them.

## Core Updates

The core package is bundled locally under `packages/fnlla-core`. For reviewed
updates, use GitHub or fnlla.com to obtain a newer FNLLA Core source, replace
the local package, update the compatible version constraint in `composer.json`,
then run:

```powershell
composer update techayodev/fnlla-core
```

Commit `composer.lock` and run project tests. Keep the previous deployment for
rollback. When a project needs the full FNLLA platform, start from
`techayoDEV/fnlla` or migrate deliberately after reviewing the product boundary.

## Production

Point the web server document root at `public/`, never the project root. Set
`APP_ENV=production`, `APP_DEBUG=false`, configure HTTPS and `SESSION_SECURE=true`.
Keep `.env`, storage and database backups private. Run migrations explicitly with
`php fnlla migrate`; rollback requires reviewing each migration's `down()` behavior.
Install locked dependencies with `composer install --no-dev --optimize-autoloader`.
The local smoke-test runner has a limited PHPUnit-compatible API, not full PHPUnit.

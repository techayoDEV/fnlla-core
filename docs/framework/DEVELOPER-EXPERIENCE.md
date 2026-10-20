# Developer experience exercise

This is an isolated local exercise, not a production login implementation.
Use only synthetic accounts and a dedicated MySQL database named
`fnlla_core_test`. Bind the PHP server to loopback. Do not install demo routes
or credentials on a public host. Production login also needs its own rate limits,
recovery, verification and operational acceptance.

This exercise targets official Core 3.0.0 and its matching immutable documentation.
Verify the published tag or release ZIP before installing; source metadata and
local package rehearsals do not establish official availability. Before publication
only an explicitly labelled local rehearsal is possible.

## Package and minimal application

Use the [requirements and Composer instructions](GETTING-STARTED.md). For a
library-only consumer, configure the official public VCS repository, require
the exact version `3.0.0`, review and commit the resulting lock. Generate a
separate application with:

```console
php vendor/techayodev/fnlla-core/fnlla make:project ../demo-app "Developer Exercise"
cd ../demo-app
composer install --no-dev
composer check-platform-reqs
php fnlla route:list
php scripts/test.php
php scripts/lint.php
```

The generator bundles an immutable path package. For a separately verified ZIP
consumer, use an application-owned Composer `package` repository with its exact
name/version, declared requirements/autoload, ZIP `dist.url`, SHA1 `dist.shasum`
and source reference. Independently verify SHA256/provenance against the trusted
release before Composer. Use a `file:` URL only in the local candidate rehearsal;
it does not establish public availability. Replace the bundled path repository
when selecting this distribution form. Do not patch `vendor/` or package source.

## Configure database, cache and security

Copy `.env.example` to `.env` once. Set the local APP_URL, `APP_DEBUG=false`,
`TRUSTED_HOSTS=127.0.0.1`, `CACHE_STORE=file`, and private writable storage.
Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE=fnlla_core_test`, `DB_USERNAME` and
`DB_PASSWORD` for the disposable service. Keep real secrets out of Git and logs.
For this exercise also set `FNLLA_DX_DISPOSABLE=1` and
`AUTH_USERS_TABLE=dx_<12-random-lowercase-hex-digits>_users`. Choose a fresh suffix
for each attempt. HTTPS deployments require secure session cookies; loopback
HTTP is only the exercise transport. Core requires no AI or Workspace account.

Copy the three files from the selected library's
`resources/examples/developer-experience/`:

| Example | Application destination |
| --- | --- |
| `DemoController.php` | `app/Controllers/DemoController.php` |
| `setup.php` | `scripts/demo-setup.php` |
| `routes.php` | Append its route declarations to `routes/web.php`, preserving existing routes and omitting the second PHP opening tag |

In `config/security.php`, retain existing settings and explicitly add roles
`editor => ['permissions' => ['demo.report']]` and
`reader => ['permissions' => []]` under `authorization.roles`. A role supplied
by the browser is never an authenticated identity. In `config/app.php`, retain
existing settings and declare `live_config_fields` for any actual credential
fields needing live reads; a historical `developer_access` namespace must select
`users`. Put selected fields in application configuration, not package defaults.

```console
php scripts/demo-setup.php seed
php fnlla config:cache
php fnlla runtime:doctor --timeout=2
php -S 127.0.0.1:8081 -t public public/router.php
```

The report endpoint reads/writes the configured cache and resolves the current
actor from the database through session authentication. Test with an HTTP client
that retains cookies and sends `Accept: application/json`:

1. `/` renders; `/api/health` responds; anonymous `/demo/report` returns 401.
2. GET `/demo/token`; retain its session cookie. POST JSON email/password to
   `/demo/login`, including the token in the JSON `_token` field. Missing CSRF returns 419;
   wrong password returns 401.
3. The local fixtures are `editor@example.test` and `reader@example.test`, with
   password `local-demo-only`. Editor report returns 200 with `cache_ready=true`;
   reader report returns 403, including with a forged `role=editor` input.
4. Run `php scripts/demo-setup.php revoke`. The editor's existing session and
   another login with that removed identity must receive 401. Authentication
   re-resolves the database actor. `restore-editor` reinstalls this synthetic
   fixture only when continuing an exercise; it is never a production recovery action.

Stop the server before dependency/config changes. These account fixtures do not
grant real application rights. Remove demo routes/files after the exercise.

## Upgrade and partial-update rollback

Repeat with an original checksum-verified 2.8.1 package. Preserve the generated
application and its exact old Composer files, `.env`, source, uploads and database
state. Add the explicit live-field declarations before changing Core, rebuild
configuration under the old engine and drain all writers.

In a separate release directory select the verified target in Composer. Preserve
application-owned files and change only the intended dependency/repository.
Run `composer update techayodev/fnlla-core --with-dependencies --no-dev`, inspect
the lock diff and run platform requirements, config compilation, app tests,
lint, service doctor and the same HTTP denied/allowed cases. This configuration
change requires no destructive database reset. Check old records/private files.

Rehearse failure after installing the dependency but before activation. Keep
writers stopped, restore the old code and exact lock in a separate directory,
run `composer install --no-dev`, rebuild generated configuration with the old
engine and repeat critical behavior checks. A code-only rollback retains the
existing database, including current identity revocations. The automated drill
revokes the editor before activating rollback, verifies the exact remaining
record identity/role and denies the removed identity on the old engine.
Schema/data changes
need the [compatible snapshots and reconciliation](UPGRADING.md#recovery-and-rollback);
restoring a ZIP is insufficient. Never reactivate revoked secrets/roles from an
old backup. A failed recovery verification leaves traffic blocked.

Finally run `php scripts/demo-setup.php cleanup` against each attempt's random
table. Remove only the disposable exercise environment.

## Repeatable and independent acceptance

`python scripts/test-developer-experience.py --artifact ZIP --commit FULL_SHA`
rehearses the library install, fresh app and original 2.8.1 upgrade using the
packaged examples above. It needs PHP/Composer, a dedicated MySQL service and
the `FNLLA_CORE_TEST_MYSQL_*` environment from the service CI. It verifies the
selected package with the release verifier, validates the pinned old ZIP and reports
the actual steps/negative HTTP assertions. It neither publishes nor activates
a dependency in the canonical Framework or website.

An automated rehearsal is not an independent human review. Before broad
promotion, a new developer repeats the published version's guide on a clean
machine without private tooling or verbal help. Record elapsed time, failures,
undocumented interventions and the final app/lock identities. The owner reviews
that usability evidence separately from technical release acceptance.

# Application snapshot primitives

Status: optional Core 2.7.0 extension, available through its verified immutable
release. Core 2.6.0 does not contain these classes. Validate the exact new
artifact in the consumer; never patch an installed package or relabel 2.6.0.

`ApplicationSnapshot`, `MySqlDatabaseSnapshot` and `SnapshotProcess` provide
provider-neutral primitives. Full FNLLA owns the update workflow, GUI/CLI,
permissions and service orchestration. Core does not automatically upgrade an
application or stop its external writers.

The orchestrator must acquire and retain `FrameworkUpdateTransaction`'s writer
lock, drain application requests and stop **all** other writers before capture.
This includes queue consumers, cron, integrations, other application instances,
database events and administrators. A success flag is not evidence of a drained
service. Hooks must actually wait for in-flight work and verify no writers remain.

## Durable phases

`begin($exclusions, $recoveryPlan)` creates a private snapshot and an authoritative
`.fnlla/application-update/active.json` marker in phase `quiescing`. No application
mutation is allowed in `quiescing` or `capturing`. After quiescence, enter
`capturing`, call `captureFiles()` and capture/verify database artifacts. Enter
`installing` before the first application mutation. On failure, enter `restoring`,
validate **all** artifacts before any restore, restore files/database and run
application health checks. Enter `restored` only after those checks pass.

Enter `committed` after installation, migrations and meaningful health checks
pass, **before** resuming writers. This durable transition permanently forbids
automatic database rollback. Recovery in `committed` or `restored` only verifies
health and completes activation. `finish()` removes the traffic-blocking marker
after resume succeeds; snapshots stay retained. No API reactivates a historical
snapshot after traffic has resumed. Such disaster recovery needs a separately
controlled downtime/data-reconciliation plan.

Keep the marker independent of application bootstrap. Generated Core public
entrypoints check it before emergency responses and the shared request lease.
The gate fails closed on locking/filesystem errors. Read-only immutable/Composer
deployments retain their existing no-in-place-update boundary. External CLI,
static web-server responses and services must be controlled by the deployment's
maintenance barrier; PHP does not manage an external proxy or supervisor.

## File and database coverage

File snapshots stream copies of the application tree, including configuration,
private `.env`, dependencies, uploaded files and empty directories. Restoration
removes entries added after capture and restores deleted/modified entries and
permissions. Symlinks, traversal and entries outside the project are rejected.
`.fnlla/application-update` and `.fnlla/update-transaction` are always excluded.
Other exclusions are an explicit operational decision: excluded data is never
restored. Corrupt or missing artifacts keep recovery blocked.

The MySQL adapter takes the resolved connection and trusted tooling settings:
`exclusive_database: true`, `mysqldump_command: ['mysqldump']`,
`mysql_command: ['mysql']`, `timeout: 900`. Command prefixes are argv arrays, never
shell strings. It dumps schema/data, routines, triggers and events with
`--single-transaction`, `--hex-blob` and `--add-drop-database --databases`.
Restore drops and recreates the **entire named database**, preventing new tables
from a failed migration surviving rollback. The database must belong exclusively
to this application; credentials need the necessary dump/create/drop privileges.
All writers and DDL must remain stopped, including events and nontransactional
table writers. MySQL client tools supporting the configured flags are required;
MariaDB, SQLite, PostgreSQL, replicas and multiple databases are not automatically
supported. Never treat an untested tool/server combination as verified.

Credentials and TLS settings go into a transient private `--defaults-file`, not
argv, logs or output. TLS verification follows the connection's explicit policy.
Only the original connection in the private recovery plan is used for restore.
Restore validates its dump hash and database identity, uses `--skip-force` and
requires application health validation afterward. It does not snapshot database
server accounts, global settings or replication state.

## Operational limits

Snapshots contain secrets and customer data. Serve only `public/`; exclude
`.fnlla/application-update/` from Git, release artifacts, public downloads,
diagnostic bundles and agent context. Unix directories/files use private modes;
Windows deployments must enforce private NTFS ACLs. Keep enough disk space for
the complete tree and database, encrypted/off-host backups and an explicit
retention policy. Retained local snapshots are not off-host disaster recovery.

Atomic files are flushed/fsynced. Host power loss, disk/controller durability,
external stores, object uploads, Redis data, email, payments and other irreversible
effects require deployment-specific recovery/reconciliation; a local snapshot
cannot undo them. The Full wrapper retains independent recovery code because
normal bootstrap/dependencies may be the failed component.

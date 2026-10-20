# Readiness and evidence

These are distinct acceptance scopes, not editions or certifications. Record
source-bound evidence and decisions in the existing release review; this contract
does not create another live status registry.

| Level | Required evidence | Separate acceptance |
| --- | --- | --- |
| Engineering Verified | Exact source, supported PHP/platform matrix, behavioral and denied-case assertions, static analysis, security review, dependency integrity, service integration and original-release upgrade/interruption/rollback rehearsals; explicit omissions and limits | Publication, official Composer availability, usability gains and customer hosting |
| Release Verified | Engineering evidence for the final commit; all 15 Core checks on actual remote main through hosted Actions or the explicitly approved signed local venue with dedicated source-bound App checks; matching deterministic Windows/Linux artifacts and provenance; clean Composer consumption; reviewed installation/migration documentation; owner decision and verified publication | Consumer activation, catalogue, integration and deployment |
| Deployment Verified | Exact application release/lock on a named host; services/providers, permissions, monitoring/alerts, capacity, secret custody, backups/restore, measured RPO/RTO, rehearsed recovery and operator acceptance | Other applications, hosts and providers |

A candidate can have engineering evidence while release and deployment acceptance
remain unmet. A source edit invalidates the earlier commit's affected evidence.
Record commits, locks, artifact hashes, commands, omissions, date, environment
and human decisions. A green summary, policy flag or liveness response is insufficient.
The [release procedure](https://github.com/techayoDEV/fnlla-core/blob/v3.0.0/docs/RELEASING.md#signed-local-native-venue) distinguishes
hosted Actions from signed native local compute. The local venue requires a
trusted external controller policy, signature/current sequence, complete original
results/logs/artifacts and matching dedicated-App checks on actual remote main.
It does not turn local reports into GitHub Actions, replace missing execution or
establish customer deployment readiness.

## Configuration acceptance

Review `load_config_directory`, `ConfigCacheCommand`, `ConfigurationGeneration`,
queue/outbox reservation boundaries and the application's credential adapter.
Require concrete assertions for rotation, removal with a surviving package
default, malformed input/cache, cached/fresh permission equivalence, stale-process
reservation and cache-publication denial, and partial-update recovery.
`tests/ConfigurationSecurityTest.php` reports individual assertions; package
runtime, snapshot and service upgrade tests cover complementary boundaries.
`ConfigurationOpcacheTest.php` enables real bytecode caching with timestamp
validation disabled, proves the stale-bytecode fixture, then asserts rotation
and removal in both configuration modes and denial with a restricted OPcache API.

Selected live fields are application-owned. Missing selected application fields
are removed; package defaults cannot restore them. Other configuration is cached
until rebuilt. Live-field declaration changes require a cache rebuild. Trusted
configuration PHP must not come from requests. Applications deny missing or
malformed credentials; Core cannot infer every custom credential field.

Standard bootstrap captures configuration/environment file fingerprints before
loading. Standard queue/outbox workers compare that generation before reserving
the next item; `config:cache` checks before compilation and publication. Changed
inputs require a new process. Embedded runtimes use standard bootstrap or call
`ConfigurationGeneration::capture()` before loading environment/configuration
and check the same boundaries.

This process fence does not replace draining writers. It cannot cancel an active
handler, detect changes in an external secret service, or control arbitrary
writers. Drain writers, restart workers/OPcache, rotate secrets at their authority
and revalidate authorization before resuming traffic. Do not restore revoked
credentials or role assignments from a backup. Retain necessary old decryption
keys separately; reconcile current revocations before activating rollback.
Failed recovery verification keeps writers stopped.

## Developer experience acceptance

Use the [developer exercise](DEVELOPER-EXPERIENCE.md) in a new environment with
only the immutable package and matching documentation. Verify Composer install,
minimal application, database/cache setup, an authenticated permission-protected
endpoint and a Core 2.8.1 upgrade. Preserve commands and denied cases.

An automated maintainer rehearsal proves reproducibility, not independent human
usability. Before broad promotion, a developer who did not implement Core should
repeat the published guide without private tools or verbal help and record all
undocumented interventions. Core 3.0.0 is now published through its official
channel; historical candidate rehearsals remain evidence for their original
inputs and do not become independent human usability results.

## Comparative claims

Configuration, queue and recovery reliability establish foundation quality.
They do not establish superiority over another framework. Potential distinction
lies in shared inspectable Actions/authorization/metadata contracts and their
integration with Framework's product context and human review. Test that hypothesis
with equivalent applications and developer tasks, measuring correctness, time,
rework and operational cost. Comparative advantage remains unproven until then.

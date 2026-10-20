# Releasing FNLLA Core

Publication needs explicit owner authorization. Never replace a released tag or
asset. A successful build or the policy's `release_approved` flag is not authority
to publish. `published_baseline` records the preceding immutable release;
`candidate` and `VERSION` identify the source being prepared.

## Version selection

Follow the public [Semantic Versioning policy](framework/RUNTIME-CONTRACTS.md#semantic-versioning).
For every release, record the preceding immutable version, proposed version,
change classification, affected contracts and compatibility/migration evidence
in the release review. Counters are not limited to 0-9; `x.9.9` never forces a
major. Breaking contracts require a major and a separate owner decision, with
documented migration and recovery. Syntax checks cannot prove compatibility.

Maintain Core 3.x through compatible patch/minor releases. On 2026-10-20 the owner
authorized finalizing and publishing exactly Core 3.0.0, followed by Framework
5.1.0 consuming that official package, conditional on every final-source gate.
This standing authority removes another generic approval round for these two
versions; their technical acceptance remains separate and mandatory. It does not
cover future releases, application/catalogue/provider changes or deployment.
Core 2.8.1 is the preceding immutable release and remains unchanged. Source
metadata is not proof of publication or acceptance of a production host.

## Release sequence

Use the separate [Engineering Verified, Release Verified and Deployment Verified
scopes](framework/READINESS.md). Unresolved owner comments leave a release unapproved.

For a committed prerelease, VERSION and the policy must name the same candidate
with `channel=local-review` and `release_approved=false`. Build normally with the
expected commit so Git blobs produce identical Windows/Linux bytes. Verify with
`python scripts/verify-release.py ZIP FULL_SHA --local-review`. This explicit
mode verifies a clean candidate and never grants stable approval. A diagnostic
`build-local-artifact.php --local-review` may capture dirty source; its provenance
remains dirty and the exact-source verifier rejects it. Stable verification
without the flag rejects every prerelease and false approval state.

1. Review public contracts, operational migrations and rollback. Update VERSION,
   distribution policy, changelog, release notes and current-version docs.
   A prepared target retains `release_approved=false` and `channel=local-review`
   until the owner approves the concrete release scope. Use a distinct prerelease
   identifier with `--local-review` for candidate integration; it is not a
   published or approved stable dependency. Stable policy flags record the
   owner's acceptance, never an automated check's result.
2. Run strict Composer validation, the main suite, lint, static analysis,
   service/upgrade and Apache upload checks. Review the diff and commit all
   intended source. Stable building refuses a dirty checkout or mismatched version.
3. Build with `php scripts/build-local-artifact.php VERSION dist/release/fnlla-core-VERSION --expected-commit=FULL_SHA`.
   Stable input comes from Git blobs; ignored/untracked files cannot ship.
   Output paths must be new. Builds never overwrite previous outputs.
4. Run `python scripts/verify-release.py ZIP FULL_SHA` for manifest verification
   and a fresh Composer consumer. Validate the exact ZIP in the full FNLLA
   consumer/export suite before publication. A local path proves integration,
   not public availability.
5. Push the authorized stabilization branch and integrate through the protected
   pull-request process below. Select the approved compute venue explicitly.
   Hosted verification uses `python scripts/check-release-ci.py FULL_SHA`.
   The signed local venue uses the command below and a dedicated source-bound
   GitHub App, without impersonating Actions. Both require all 15 checks on
   the actual final remote-main commit. PR receipts and older sources do not
   replace final-main acceptance. Save the gate's JSON as `CI-EVIDENCE.json`.
6. Compare the Windows and Linux CI ZIP hashes with the local ZIP. Require
   strict equality. Recheck that the tag/release does not already exist.
7. Create the version tag at FULL_SHA and a draft GitHub release with the ZIP,
   ZIP SHA256, FNLLA-MANIFEST.sha256, FNLLA-PACKAGE.json,
   FNLLA-PROVENANCE.json and CI-EVIDENCE.json. Check draft asset names, lengths
   and hashes before publishing. No clobber/force operation is permitted.
8. Download the published ZIP, verify its SHA256 and provenance, then update
   FNLLA's canonical Composer package metadata (source, release URL, SHA1,
   SHA256, manifest) and lock. Install through Composer and rerun boundary,
   consumer and export checks. Framework 5.1.0 has standing owner authorization,
   but its own complete final-source/lock acceptance is required before its tag
   and publication; Core's 15 checks do not replace the Framework matrix.

Any source change invalidates the earlier commit's CI evidence. Repeat the
required gates for the new commit. If publication stops halfway, inspect the
remote tag/draft first; do not rewrite an existing version to hide a failure.

## CI compute and required checks

Linux is the production target. The public repository retains PHP 8.3/8.4/8.5
runtime checks on Linux and Windows and deterministic packages on both systems:
filesystem locking, path handling, mail HTTP transport and consumer installation
are development-platform boundaries. MySQL/Redis, upgrade and Apache checks run
on Linux. The same 15 checks may execute on the owner's isolated native local
Linux/Windows infrastructure under the signed local contract below. Compute
venue changes no test scope, package identity or application runtime requirement.

The stable checks `PHP 8.3`, `PHP 8.4` and `PHP 8.5` are aggregators. All require
all runtime, service, upload and package jobs to succeed and fail on cancellation
or skipping. Expanded OS matrix names alone do not satisfy those stable names.
The release CI reader also requires all three aggregators and all twelve underlying jobs.
Branch protection is not modified by workflow maintenance.

### Signed local native venue

Follow the [controller setup, command sequence and receipt schema](LOCAL-CI.md)
for bindings, attempt registration, real pending checks, job execution, sealing,
verification and publication. The release-reader command below is the final gate,
not a replacement for that controller sequence.

The owner approved a dedicated local-CI GitHub App and source-specific protected
checks as an alternative to hosted Actions. This is an explicit policy selection,
never a silent fallback after a failed hosted run. Retain the hosted workflow
and its independent evidence when used. Local check names start with
`Local CI / ` and are issued only by the configured dedicated App; they are not
GitHub Actions checks. The external operator policy pins the App identity,
controller public key, current monotonic attempt state and approved controller
tool bytes. The committed `.github/local-ci-policy.json` binds the exact 15
required names, source workflow/lock/verifier files, allowed refs, freshness
limits and narrowly named Windows symlink omissions with executed Linux
counterparts. Do not waive checks, synthesize successful statuses, register a
new issuer without owner authority or remove PR/admin/conversation protections.

Run the actual maintained workflow commands in clean native checkouts, including
all six PHP/platform cells, three MySQL/Redis cells, two deterministic package
cells, Apache upload validation and all three executed aggregators. Preserve
original per-job results/logs, source/tree/workflow/lock and controller hashes,
start/completion times and both platforms' original artifacts. The trusted
controller registers a new latest attempt before work starts, and records failure
or success for that same source/ref/run/sequence. A newer running or failed
attempt invalidates an older green receipt; sealing cannot create a new favorable
attempt after ignoring a failure. Publish genuine in-progress App checks before
the workers start and failed checks if that attempt fails, so protected PRs cannot
reuse a previous green result while a newer attempt is unfinished or failed.
Hash verification
alone does not prove execution: the trusted controller supplies the actual
result files and logs, and the verifier checks their identities, omissions and
artifact equality before accepting a signed receipt. Application deployments and
the independent full Framework consumer acceptance remain separate gates.

Python 3 and Node.js 22+ are maintainer-only verification requirements. No extra
Python crypto package, framework runtime service or customer Node dependency is
introduced. Keep the Ed25519 controller private key, GitHub App private key,
external trusted policy and latest attempt state outside the source checkout,
package ZIP and evidence directory. Evidence cannot supply its own trust root.
Never print credentials, installation tokens or private keys. The App needs only
the approved repository installation and checks-write/contents-read access.

Seal and verify the retained files with `scripts/local-ci/receipt.mjs` using
the independently trusted operator policy. Publishing a PR check requires its
explicitly approved release ref and a freshly verified receipt. It does not
satisfy release acceptance: after protected integration, rerun all invalidated
gates for the actual main commit, seal the new final receipt and publish its
source-bound App checks. The release reader enforces actual remote main before
and after verification and requires the latest successful complete App check
set to reference that same signed receipt.

```console
python scripts/check-release-ci.py FULL_MAIN_SHA --venue local-native --receipt /private/evidence/receipt.json --signature /private/evidence/receipt.sig --policy /private/controller/operator-policy.json --evidence-root /private/evidence --source-root /clean/fnlla-core
```

Use `--node /managed/node` when Node is not on PATH. Receipt verification checks
signature, latest controller sequence, source/ref, committed policy/workflow/lock/
verifier identities, approved execution tools, all actual results/logs/artifacts
and freshness. The Python gate then reads the live GitHub checks API and requires
the configured App ID, exact names, current source, success and matching receipt
digest. Failed, missing, duplicated, stale, replayed, incomplete or substituted
evidence fails closed, including under optimized Python. It never creates check
runs, changes protection, signs evidence or publishes a release. A GitHub API
outage still blocks protected integration/publication; local compute does not
replace GitHub's repository authority.

Retain `CI-EVIDENCE.json`, the signed receipt/signature, public verification
material and original source-bound logs/artifacts for review. Identify its venue
as `signed-local-native`; it is not a public GitHub Actions receipt. A prepared
unsigned local report, an App status by itself or an earlier commit's receipt is
insufficient. Rotate a compromised controller/App key through an owner-approved
trust-policy update and new exact-source acceptance, never by rewriting an
accepted tag or artifact.

Only superseded PR validation can be cancelled. Main and manual acceptance have
unique concurrency groups and are never cancelled by a later push. Every job
has a bounded timeout. Composer's download cache uses OS and exact lock digest;
installs, strict validation and analysis still run. Vendor/build outputs are not
cached. Deterministic package artifacts retain a 14-day review window. Expired
evidence requires fresh validation, never replacement of a released artifact.

## GitHub titles and protected integration

Release tags and artifacts are immutable. The display title is always
`FNLLA-CORE X.X.X`, including prerelease suffixes when applicable. Never use a
title edit to imply different artifact contents. Main requires a PR, strict
source-specific PHP 8.3 and PHP 8.4 checks from the selected approved issuer and
conversation resolution, with administrator enforcement and force-push/deletion
disabled. Hosted protection binds GitHub Actions. The owner-approved local policy
migrates those bindings to `Local CI / PHP 8.3` and `Local CI / PHP 8.4` from the
dedicated App; a matching text label from another App cannot satisfy them.
The owner-approved solo policy requires zero approving GitHub reviews; CI and
PR requirements remain mandatory. Changing issuers is an owner-authorized
administrative action, never an automated reaction to failing checks.

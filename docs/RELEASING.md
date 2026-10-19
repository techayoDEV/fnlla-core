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

The accepted next-generation direction is Core 3.x, maintained through compatible
patch/minor releases. Core 3 is not yet published or accepted for production;
Core 2.8.1 remains the current immutable release. Preparing the next generation
requires the complete release and Framework-consumer checks below. Updating
policy text does not change VERSION, distribution approval or a consumer lock.

## Release sequence

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
5. Push the authorized commit to main. Require the latest exact-commit quality
   run: `python scripts/check-release-ci.py FULL_SHA`. This read-only gate
   rejects missing, skipped, failed or incomplete required jobs, an older run,
   or a commit different from remote main. Save its JSON as `CI-EVIDENCE.json`.
6. Compare the Windows and Linux CI ZIP hashes with the local ZIP. Require
   strict equality. Recheck that the tag/release does not already exist.
7. Create the version tag at FULL_SHA and a draft GitHub release with the ZIP,
   ZIP SHA256, FNLLA-MANIFEST.sha256, FNLLA-PACKAGE.json,
   FNLLA-PROVENANCE.json and CI-EVIDENCE.json. Check draft asset names, lengths
   and hashes before publishing. No clobber/force operation is permitted.
8. Download the published ZIP, verify its SHA256 and provenance, then update
   FNLLA's canonical Composer package metadata (source, release URL, SHA1,
   SHA256, manifest) and lock. Install through Composer and rerun boundary,
   consumer and export checks. Framework publication is a separate decision.

Any source change invalidates the earlier commit's CI evidence. Repeat the
required gates for the new commit. If publication stops halfway, inspect the
remote tag/draft first; do not rewrite an existing version to hide a failure.

## CI compute and required checks

Linux is the production target. The public repository retains PHP 8.3/8.4
runtime checks on Linux and Windows and deterministic packages on both systems:
filesystem locking, path handling, mail HTTP transport and consumer installation
are development-platform boundaries. MySQL/Redis, upgrade and Apache checks run
on Linux. Standard public runners do not incur runner-minute charges; useful
portable-runtime coverage is retained rather than moved into private consumers.

The stable protected checks `PHP 8.3` and `PHP 8.4` are aggregators. Both require
all runtime, service, upload and package jobs to succeed and fail on cancellation
or skipping. Expanded OS matrix names alone do not satisfy those stable names.
The release CI reader also requires both aggregators and all nine underlying jobs.
Branch protection is not modified by workflow maintenance.

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
PHP 8.3 and PHP 8.4 checks and conversation resolution, with administrator
enforcement and force-push/deletion disabled. The owner-approved solo policy
requires zero approving GitHub reviews; CI and PR requirements remain mandatory.

# Maintainer local CI controller

This is a release-controller procedure, not application runtime configuration.
Read [the release policy](RELEASING.md#signed-local-native-venue) first. The
approved local venue retains all 15 Core checks and publishes distinct checks
from a dedicated, repository-scoped GitHub App. It never impersonates Actions.
Python 3, Node.js 22+, Git, GitHub CLI and the native workflow tools/services are
maintainer requirements. Only the controller receives signing or App key paths.

## Trust and operator configuration

The committed `.github/local-ci-policy.json` controls source refs, workflow/lock/
verifier bindings, all required checks and permitted omissions. An independently
trusted operator policy supplies the public key, latest-attempt state, approved
execution-tool identities and the selected GitHub App installation. Neither the
receipt nor worker output can supply its own trust root.

Provision the Ed25519 signing pair and GitHub App key outside the source and
worker evidence. Install the App only on the approved repository with checks
write and contents read. Protect the controller directory and state from worker
writes; use the same single controller lifecycle for all attempts. The external
policy has this shape; replace the illustrative IDs/tool hash/size with independently
verified values and retain public-key/App identity verification records:

```json
{
  "schema": "fnlla.local-ci.controller-policy.v1",
  "repository": "techayoDEV/fnlla-core",
  "source_policy_path": ".github/local-ci-policy.json",
  "controller_public_key": "keys/controller-public.pem",
  "latest_state": "state/latest.json",
  "github_app": {
    "app_id": 123456,
    "installation_id": 654321,
    "slug": "owner-approved-local-ci-app"
  },
  "execution_tools": {
    "native": { "sha256": "REPLACE_WITH_APPROVED_CONTROLLER_SHA256", "size": 12345 }
  }
}
```

Public key and latest state paths are relative to the trusted operator-policy
directory and must remain outside source/evidence after resolution. Create the
required trusted directories. Keep both private keys out of the receipt,
artifacts, logs, environment passed to workers and version control. Do not edit
or remove latest state to reuse an older successful attempt. Its monotonic
sequence and current run/ref/source/status are release authority inputs.

## Controller sequence

Use a clean checkout of the exact target source. Choose `--purpose pull-request`
only for the committed policy's approved release ref; choose `--purpose release`
only for actual remote main. Use a new run ID, evidence directory and output
files for every attempt. The paths below are examples; use real approved paths,
the exact 40-character SHA and one consistent purpose for the whole attempt.
`/private/controller` is the trusted controller directory; `/private/evidence`
contains worker outputs and public evidence, and must not contain trust keys/state.

1. Capture source bindings. Preserve this JSON from stdout through the trusted
   controller as `bindings.json`; do not synthesize the hashes:

   ```console
   node scripts/local-ci/receipt.mjs bindings --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python
   ```

2. Register the latest running attempt **before** starting any job. Capture its
   returned sequence and start time for the same run's draft:

   ```console
   node scripts/local-ci/receipt.mjs begin --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --run-id UNIQUE_RUN_ID
   ```

3. Publish genuine pending checks from the selected App before workers start.
   A failure here leaves the attempt unfinished; do not execute or seal a run
   that skipped this boundary:

   ```console
   node scripts/local-ci/receipt.mjs publish-pending --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --run-id UNIQUE_RUN_ID --app-private-key /private/controller/keys/app.pem
   ```

4. Execute the approved native adapter with no signing/App credentials. It must
   run the maintained workflow on the exact source and record actual per-job
   UTC start/end times, platform/PHP matrix, result files, logs and original
   artifacts. Complete the 12 underlying jobs, then execute the three source
   aggregators against their real outcomes. Retain failures and omissions.
   Construct `draft.json` from those original files, captured bindings and the
   registered attempt identity using the receipt schema below. A signed summary
   without those results is insufficient.

5. After successful workers, seal the draft through the trusted controller.
   The tool validates results, logs, omissions and deterministic source-bound
   artifacts before signing and advancing that same latest attempt to success:

   ```console
   node scripts/local-ci/receipt.mjs seal --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --draft /private/evidence/draft.json --receipt /private/evidence/receipt.json --signature /private/evidence/receipt.sig --private-key /private/controller/keys/controller-private.pem
   ```

6. Verify the latest receipt independently, then publish its successful
   source-bound App checks. Publication re-verifies before obtaining an
   installation token; it does not trust an earlier success flag:

   ```console
   node scripts/local-ci/receipt.mjs verify --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --receipt /private/evidence/receipt.json --signature /private/evidence/receipt.sig
   node scripts/local-ci/receipt.mjs publish --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --receipt /private/evidence/receipt.json --signature /private/evidence/receipt.sig --app-private-key /private/controller/keys/app.pem
   ```

7. Immediately before an owner-controlled protected PR integration, verify its
   current receipt and all current source-bound App checks again. After merge,
   rerun the invalidated gates for the actual new main SHA. A PR receipt cannot
   be relabelled as final-main evidence. For final publication, capture the
   release reader's JSON as `CI-EVIDENCE.json` and continue the artifact/draft
   release checks in [the release sequence](RELEASING.md#release-sequence):

   ```console
   python scripts/check-release-ci.py FULL_MAIN_SHA --venue local-native --policy /private/controller/policy.json --source-root /clean/fnlla-core --evidence-root /private/evidence --receipt /private/evidence/receipt.json --signature /private/evidence/receipt.sig
   ```

On any failed execution, validation or publication step, retain all files and
mark the matching latest attempt failed, then publish genuine failed App checks.
Do not reuse a prior green check set or seal a new favorable attempt over an
unrecorded failure:

```console
node scripts/local-ci/receipt.mjs fail --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --run-id UNIQUE_RUN_ID
node scripts/local-ci/receipt.mjs publish-failure --policy /private/controller/policy.json --source FULL_SHA --purpose release --source-root /clean/fnlla-core --evidence-root /private/evidence --python /managed/python --run-id UNIQUE_RUN_ID --app-private-key /private/controller/keys/app.pem
```

If GitHub is unavailable, the local state still records failure and release
verification remains blocked. Restore API access and update that attempt's
remote checks; do not bypass protection or publish. Retry with a new run/evidence
directory and the complete required gate set.

## Receipt and retained-file schema

`bindings` emits the exact source tree/ref, committed policy/workflow/lock/verifier
identities and trusted controller-policy hash. The draft uses schema
`fnlla.local-ci.receipt.v1`, with repository, source commit/tree/ref, purpose,
run ID, registered sequence/start time, actual completion time, bindings,
controller-policy hash, approved execution-tool descriptors, all 15 check records
and original Linux/Windows ZIP descriptors. Timestamps are UTC ISO 8601.

Each file descriptor contains a safe relative `path`, SHA256 `sha256` and byte
`size`. Each check contains `name`, `status`, `source_commit`, `started_at`,
`completed_at`, the actual raw omission lines, `result` and `evidence` descriptors.
`result.path` selects the retained JSON result; `result.index` selects its row
when the original result file is a checks array. The actual result supplies
`check` (or `name`), source, success status, platform, exact PHP/OS `matrix`, its
relative raw `log` path and `log_sha256`. The result and raw log must both appear
in `evidence`; do not rewrite either to satisfy a failed check. Aggregators also
need their own executed-result records/logs. Original package results bind all
five ZIP/checksum/manifest/package/provenance files and their retained descriptors.

`artifacts.linux` and `artifacts.windows` identify the original ZIP descriptors
from the corresponding package results. Verification requires identical bytes,
valid manifest/provenance, approved stable version/source and committed payload
identity. The separate full package/Composer and Framework-consumer gates remain
mandatory. Executed Windows omissions must match the committed narrow allowlist,
and their Linux counterparts must execute successfully without omissions.

Sealing produces canonical receipt bytes and a detached base64 Ed25519 signature.
Verification requires the latest successful registered attempt, valid signature,
freshness, approved tools, complete exact-source identities and unchanged raw
files. Publishing sets each App check's external ID to that receipt digest.
The final Python release reader additionally checks actual remote main before
and after verification and the complete latest App check set. It does not sign,
publish checks, merge, alter branch protection, tag or release anything.

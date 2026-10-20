"""Read-only release gate for exact remote main and an explicitly selected CI venue."""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys

REPO = "techayoDEV/fnlla-core"
REQUIRED = {"PHP 8.3 on ubuntu-latest", "PHP 8.4 on ubuntu-latest",
            "PHP 8.3 on windows-latest", "PHP 8.4 on windows-latest",
            "MySQL and Redis PHP 8.3", "MySQL and Redis PHP 8.4", "upload-http",
            "PHP 8.5 on ubuntu-latest", "PHP 8.5 on windows-latest", "MySQL and Redis PHP 8.5",
            "Package on ubuntu-latest", "Package on windows-latest", "PHP 8.3", "PHP 8.4", "PHP 8.5"}


def api(path):
    result = subprocess.run(["gh", "api", "repos/" + REPO + "/" + path],
                            check=True, capture_output=True, text=True, timeout=60)
    return json.loads(result.stdout)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def hosted_evidence(commit):
    runs = api("actions/workflows/quality.yml/runs?head_sha=" + commit + "&per_page=100")["workflow_runs"]
    eligible = [run for run in runs if run["head_sha"] == commit and run["head_branch"] == "main"
                and run["event"] in ("push", "workflow_dispatch")]
    require(eligible, "No quality run for this exact main commit")
    latest = max(eligible, key=lambda run: run["id"])
    require(latest["status"] == "completed" and latest["conclusion"] == "success", "Latest quality run must pass")
    jobs = api("actions/runs/" + str(latest["id"]) + "/jobs?filter=latest&per_page=100")["jobs"]
    names = [job['name'] for job in jobs]
    require(all(names.count(name) == 1 for name in REQUIRED), "Required CI jobs are missing or duplicated")
    require(all(job["status"] == "completed" and job["conclusion"] == "success" for job in jobs), "CI jobs failed or skipped")
    return {"schema": "fnlla.release.ci.v1", "venue": "github-actions", "repository": REPO, "source_commit": commit,
            "run_id": latest["id"], "run_attempt": latest["run_attempt"], "url": latest["html_url"],
            "conclusion": "success", "jobs": [{"name": job["name"], "conclusion": job["conclusion"],
                                                    "url": job["html_url"]} for job in jobs]}


def verify_signed_local_receipt(commit, options):
    """Cryptographic/file verification uses the maintained, source-bound Node tool."""
    source = options.source_root.resolve(strict=True)
    head = subprocess.run(["git", "-C", str(source), "rev-parse", "HEAD"], check=True,
                          capture_output=True, text=True, timeout=30).stdout.strip()
    require(head == commit, "Local verifier checkout must be the exact release commit")
    dirty = subprocess.run(["git", "-C", str(source), "status", "--porcelain=v1", "--untracked-files=all"],
                           check=True, capture_output=True, text=True, timeout=30).stdout.strip()
    require(not dirty, "Local verifier requires a clean source checkout")
    tool = source / "scripts/local-ci/receipt.mjs"
    require(tool.is_file() and not tool.is_symlink(), "Maintained local receipt verifier is unavailable")
    process = subprocess.run([options.node, str(tool), "verify", "--receipt", str(options.receipt.resolve(strict=True)),
                              "--signature", str(options.signature.resolve(strict=True)),
                              "--policy", str(options.policy.resolve(strict=True)), "--source", commit,
                              "--purpose", "release", "--evidence-root", str(options.evidence_root.resolve(strict=True)),
                              "--source-root", str(source), "--python", sys.executable],
                             check=True, capture_output=True, text=True, timeout=180)
    verified = json.loads(process.stdout)
    require(isinstance(verified, dict), "Local verifier did not return an evidence object")
    return verified


def local_evidence(commit, options):
    verified = verify_signed_local_receipt(commit, options)
    require(verified.get("schema") == "fnlla.local_ci.verified.v1", "Unknown signed local evidence schema")
    require(verified.get("repository") == REPO and verified.get("source_commit") == commit,
            "Signed local repository or source mismatch")
    require(verified.get("purpose") == "release" and verified.get("source_ref") == "refs/heads/main",
            "Release evidence must select actual remote main")
    for field in ("receipt_sha256", "signature_sha256", "policy_sha256", "controller_policy_sha256"):
        require(isinstance(verified.get(field), str) and re.fullmatch(r"[a-f0-9]{64}", verified[field]),
                "Missing local evidence identity: " + field)
    require(isinstance(verified.get("latest_sequence"), int) and not isinstance(verified["latest_sequence"], bool)
            and verified["latest_sequence"] > 0, "Missing latest signed controller sequence")
    require(verified.get("latest_status") == "success", "Latest controller attempt is not successful")
    checks = verified.get("checks")
    require(isinstance(checks, list) and all(isinstance(row, dict) for row in checks), "Local checks are missing")
    names = [row.get("name") for row in checks]
    require(all(isinstance(name, str) for name in names) and len(names) == len(REQUIRED)
            and len(names) == len(set(names)) and set(names) == REQUIRED,
            "Signed local evidence must contain every mandatory check exactly once")
    require(all(row.get("status") == "success" and isinstance(row.get("omissions"), list) for row in checks),
            "Signed local check failed, skipped or lacks its omission record")
    publisher = verified.get("publication_checks")
    require(isinstance(publisher, dict), "Signed local check publisher is missing")
    app_id = publisher.get("app_id")
    require(isinstance(app_id, int) and not isinstance(app_id, bool) and app_id > 0,
            "A dedicated GitHub App identity is required")
    require(app_id != 15368, "Local evidence cannot impersonate GitHub Actions")
    expected_names = {"Local CI / " + name for name in REQUIRED}
    published_names = publisher.get("names")
    require(isinstance(published_names, list) and len(published_names) == len(expected_names)
            and len(published_names) == len(set(published_names)) and set(published_names) == expected_names,
            "Local publisher check names do not match the release contract")
    require(publisher.get("external_id") == verified["receipt_sha256"], "Publisher receipt identity mismatch")
    remote = api("commits/" + commit + "/check-runs?filter=latest&per_page=100")
    remote_checks = remote.get("check_runs")
    require(isinstance(remote_checks, list) and remote.get("total_count") == len(remote_checks),
            "Latest remote check evidence is incomplete")
    selected = [row for row in remote_checks if row.get("name") in expected_names
                and isinstance(row.get("app"), dict) and row["app"].get("id") == app_id]
    require(len(selected) == len(expected_names) and {row.get("name") for row in selected} == expected_names,
            "Latest dedicated-App check set is missing or duplicated")
    require(all(row.get("head_sha") == commit and row.get("status") == "completed"
                and row.get("conclusion") == "success" and row.get("external_id") == verified["receipt_sha256"]
                for row in selected), "Latest dedicated-App checks failed or refer to another signed receipt")
    return {"schema": "fnlla.release.ci.v1", "venue": "signed-local-native", "repository": REPO,
            "source_commit": commit, "conclusion": "success", "public_github_actions_receipt": False,
            "signed_local_evidence": verified,
            "jobs": [{"name": row["name"], "conclusion": row["conclusion"], "app_id": row["app"]["id"],
                      "external_id": row["external_id"], "url": row["html_url"]} for row in selected]}


def main():
    if sys.argv[1:] == ["--required-checks"]:
        print(json.dumps(sorted(REQUIRED)))
        return
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("commit")
    parser.add_argument("--venue", choices=("github-actions", "local-native"), default="github-actions")
    parser.add_argument("--receipt", type=Path)
    parser.add_argument("--signature", type=Path)
    parser.add_argument("--policy", type=Path)
    parser.add_argument("--evidence-root", type=Path)
    parser.add_argument("--source-root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--node", default="node")
    options = parser.parse_args()
    commit = options.commit
    require(re.fullmatch(r"[a-f0-9]{40}", commit), "Full source commit required")
    local_options = (options.receipt, options.signature, options.policy, options.evidence_root)
    require(all(value is not None for value in local_options) if options.venue == "local-native"
            else all(value is None for value in local_options),
            "Select the local venue explicitly with receipt, signature, external policy and evidence root")
    require(api("git/ref/heads/main")["object"]["sha"] == commit, "Source must be current remote main")
    evidence = local_evidence(commit, options) if options.venue == "local-native" else hosted_evidence(commit)
    require(api("git/ref/heads/main")["object"]["sha"] == commit, "Remote main changed during release verification")
    print(json.dumps(evidence, indent=2))


if __name__ == "__main__":
    main()

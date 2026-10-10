"""Read-only release gate. Requires successful quality jobs on the exact main commit."""
import json
import re
import subprocess
import sys

REPO = "techayoDEV/fnlla-core"
REQUIRED = {"PHP 8.3 on ubuntu-latest", "PHP 8.4 on ubuntu-latest",
            "PHP 8.3 on windows-latest", "PHP 8.4 on windows-latest",
            "MySQL and Redis PHP 8.3", "MySQL and Redis PHP 8.4", "upload-http",
            "Package on ubuntu-latest", "Package on windows-latest", "PHP 8.3", "PHP 8.4"}


def api(path):
    result = subprocess.run(["gh", "api", "repos/" + REPO + "/" + path],
                            check=True, capture_output=True, text=True, timeout=60)
    return json.loads(result.stdout)


def main():
    commit = sys.argv[1]
    assert re.fullmatch(r"[a-f0-9]{40}", commit), "Full source commit required"
    assert api("git/ref/heads/main")["object"]["sha"] == commit, "Source must be current remote main"
    runs = api("actions/workflows/quality.yml/runs?head_sha=" + commit + "&per_page=100")["workflow_runs"]
    eligible = [run for run in runs if run["head_sha"] == commit and run["head_branch"] == "main"
                and run["event"] in ("push", "workflow_dispatch")]
    assert eligible, "No quality run for this exact main commit"
    latest = max(eligible, key=lambda run: run["id"])
    assert latest["status"] == "completed" and latest["conclusion"] == "success", "Latest quality run must pass"
    jobs = api("actions/runs/" + str(latest["id"]) + "/jobs?filter=latest&per_page=100")["jobs"]
    assert REQUIRED <= {job["name"] for job in jobs}, "Required CI jobs are missing"
    assert all(job["status"] == "completed" and job["conclusion"] == "success" for job in jobs), "CI jobs failed or skipped"
    print(json.dumps({"schema": "fnlla.release.ci.v1", "repository": REPO, "source_commit": commit,
                      "run_id": latest["id"], "run_attempt": latest["run_attempt"], "url": latest["html_url"],
                      "conclusion": "success", "jobs": [{"name": job["name"], "conclusion": job["conclusion"],
                                                              "url": job["html_url"]} for job in jobs]}, indent=2))


if __name__ == "__main__":
    main()

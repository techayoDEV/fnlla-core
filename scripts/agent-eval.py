#!/usr/bin/env python3
"""Prepare and grade isolated, synthetic coding-agent tasks. Never calls a model."""
import argparse
import hashlib
import json
import pathlib
import shutil
import subprocess
import tempfile
import time

ROOT = pathlib.Path(__file__).resolve().parents[1]
RESOURCES = ROOT / "resources/agent-evaluation"
TASKS = {task["id"]: task for task in json.loads((RESOURCES / "tasks.json").read_text())["tasks"]}


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def snapshot(project):
    result = {}
    for path in sorted(project.rglob("*")):
        relative = path.relative_to(project).as_posix()
        if relative.split("/")[0] in (".git", "storage"):
            continue
        if path.is_symlink() or getattr(path, "is_junction", lambda: False)():
            raise ValueError("Evaluation does not allow links: " + relative)
        if path.is_file():
            result[relative] = digest(path)
    return result


def fingerprint(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def execute(args, cwd, timeout=45):
    # Poll an output file so a noisy task cannot fill an unbounded pipe buffer.
    with tempfile.TemporaryFile() as stream:
        with subprocess.Popen(args, cwd=cwd, stdout=stream, stderr=subprocess.STDOUT) as process:
            deadline = time.monotonic() + timeout
            while process.poll() is None:
                if time.monotonic() >= deadline or stream.tell() > 1048576:
                    process.kill()
                    process.wait()
                    return 124, "Evaluation command exceeded its time/output limit."
                time.sleep(0.02)
            stream.seek(0)
            output = stream.read(65537)
            return (process.returncode if len(output) <= 65536 else 125), output[:65536].decode(errors="replace")


def prepare(target, task_id, agent, model):
    target = target.resolve()
    if target.exists():
        raise ValueError("Evaluation target must not already exist.")
    target.mkdir(parents=True)
    project = target / "project"
    code, output = execute(["php", str(ROOT / "fnlla"), "make:project", str(project), "Agent Evaluation"], ROOT, 90)
    if code:
        raise RuntimeError(output)
    task = TASKS[task_id]
    prompt = (
        "# Synthetic evaluation task\n\n"
        "Read AGENTS.md and run php fnlla runtime:inspect before editing. Use installed source as evidence. "
        "Do not call providers, browse, deploy or access private data. Work only in the allowed application paths. "
        "Never edit dependencies, tests, TASK.md or instruction files. Finish with changed files and checks actually run. "
        "All data in this task is synthetic.\n\n" + task["prompt"] + "\n\nAllowed paths: " + ", ".join(task["allowed"]) + "\n"
    )
    (project / "TASK.md").write_text(prompt, encoding="utf-8")
    baseline = snapshot(project)
    metadata = {"schema": "fnlla.agent_evaluation.run.v1", "task": task_id, "agent_claimed": agent,
                "model_claimed": model, "agent_verified": False, "prepared_at_unix": time.time(),
                "baseline": baseline, "input_sha256": fingerprint(baseline),
                "task_sha256": fingerprint(task), "grader_sha256": digest(RESOURCES / "evaluate.php"),
                "synthetic": True}
    (target / "run.json").write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    execute(["git", "init", "--quiet"], project)
    return project


def allowed(path, task):
    return any(path.startswith(rule) if rule.endswith("/") else path == rule for rule in task["allowed"])


def grade(target):
    target = target.resolve()
    metadata = json.loads((target / "run.json").read_text(encoding="utf-8"))
    if metadata.get("schema") != "fnlla.agent_evaluation.run.v1":
        raise ValueError("Invalid evaluation run.")
    task = TASKS[metadata["task"]]
    if metadata["task_sha256"] != fingerprint(task) or metadata["grader_sha256"] != digest(RESOURCES / "evaluate.php"):
        raise ValueError("Task/grader changed since preparation; prepare a new run.")
    project = target / "project"
    current = snapshot(project)
    changed = sorted(path for path in set(current) | set(metadata["baseline"]) if current.get(path) != metadata["baseline"].get(path))
    forbidden = [path for path in changed if not allowed(path, task)]
    checks = []
    if forbidden:
        checks.append({"name": "ownership", "status": "failed", "paths": forbidden})
    else:
        checks.append({"name": "ownership", "status": "passed"})
        for path in changed:
            if path.endswith(".php") and (project / path).is_file():
                code, output = execute(["php", "-l", str(project / path)], project)
                if code:
                    (target / "check.log").write_text(output, encoding="utf-8")
                checks.append({"name": "lint:" + path, "status": "passed" if code == 0 else "failed"})
        if all(check["status"] == "passed" for check in checks):
            code, output = execute(["php", str(RESOURCES / "evaluate.php"), str(project), task["id"]], project)
            # Raw agent/program output remains local; the portable report records only evidence hashes.
            (target / "check.log").write_text(output, encoding="utf-8")
            checks.append({"name": "behavior", "status": "passed" if code == 0 else "failed",
                           "exit_code": code, "output_sha256": hashlib.sha256(output.encode()).hexdigest()})
    report = {"schema": "fnlla.agent_evaluation.result.v1", "task": task["id"],
              "agent_claimed": metadata["agent_claimed"], "model_claimed": metadata["model_claimed"],
              "agent_verified": False, "synthetic": True, "approved": False, "human_review": "pending",
              "status": "passed" if all(check["status"] == "passed" for check in checks) else "failed",
              "input_sha256": metadata["input_sha256"], "result_sha256": fingerprint(current),
              "grader_sha256": metadata["grader_sha256"],
              "wall_seconds_since_prepare": round(time.time() - metadata["prepared_at_unix"], 3),
              "changed_files": changed, "checks": checks}
    (target / "result.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return report


def reference_check():
    with tempfile.TemporaryDirectory(prefix="fnlla-evaluation-self-test-") as directory:
        for task_id in TASKS:
            target = pathlib.Path(directory) / task_id
            project = prepare(target, task_id, "synthetic-reference", "not-a-model")
            if grade(target)["status"] != "failed":
                raise RuntimeError("Empty implementation passed: " + task_id)
            reference = RESOURCES / "reference" / task_id
            for source in reference.rglob("*"):
                if source.is_file():
                    relative = source.relative_to(reference)
                    destination = project / relative
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    if relative.as_posix() == "routes/web.php":
                        with destination.open("a", encoding="utf-8") as stream:
                            stream.write(source.read_text(encoding="utf-8"))
                    else:
                        shutil.copyfile(source, destination)
            report = grade(target)
            if report["status"] != "passed":
                raise RuntimeError(json.dumps(report) + "\n" + (target / "check.log").read_text())
            with (project / "packages/fnlla-core/src/Application.php").open("a", encoding="utf-8") as stream:
                stream.write("\n// forbidden engine patch\n")
            if grade(target)["status"] != "failed":
                raise RuntimeError("Dependency modification passed: " + task_id)
            print(task_id + ": empty fails, reference passes, dependency tampering fails")
    print("Evaluation harness verified; these are synthetic reference checks, not agent results.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    create = sub.add_parser("prepare")
    create.add_argument("target", type=pathlib.Path)
    create.add_argument("--task", required=True, choices=TASKS)
    create.add_argument("--agent", required=True, choices=["codex", "claude-code", "human", "other"])
    create.add_argument("--model", required=True, help="Actual model/version used, or explicitly 'unrecorded'.")
    assess = sub.add_parser("grade")
    assess.add_argument("target", type=pathlib.Path)
    sub.add_parser("self-test")
    args = parser.parse_args()
    if args.command == "prepare":
        print(prepare(args.target, args.task, args.agent, args.model))
    elif args.command == "grade":
        result = grade(args.target)
        print(json.dumps(result, indent=2))
        raise SystemExit(0 if result["status"] == "passed" else 1)
    else:
        reference_check()


if __name__ == "__main__":
    main()

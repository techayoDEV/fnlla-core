#!/usr/bin/env python3
"""Exercise a pinned released Core -> current Core -> reconciled rollback on isolated services."""
import hashlib
import json
import os
import pathlib
import subprocess
import tempfile
import urllib.request
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASELINE_URL = "https://github.com/techayoDEV/fnlla-core/releases/download/v2.3.1/fnlla-core-2.3.1.zip"
BASELINE_SHA256 = "83b3717e4a2518e8c15341b8468a4fd48909e0f4c8ecd5408d4b74a44a6544dc"


def main():
    if "dbname=fnlla_core_test" not in os.environ.get("FNLLA_CORE_TEST_MYSQL_DSN", ""):
        raise RuntimeError("An isolated fnlla_core_test database is required.")
    with tempfile.TemporaryDirectory(prefix="fnlla-upgrade-drill-") as temporary:
        directory = pathlib.Path(temporary)
        archive = directory / "baseline.zip"
        with urllib.request.urlopen(BASELINE_URL, timeout=30) as response:
            data = response.read(4 * 1024 * 1024)
        if hashlib.sha256(data).hexdigest() != BASELINE_SHA256:
            raise RuntimeError("Released baseline checksum does not match the pinned artifact.")
        archive.write_bytes(data)
        with zipfile.ZipFile(archive) as package:
            for info in package.infolist():
                path = pathlib.PurePosixPath(info.filename)
                if path.is_absolute() or ".." in path.parts or "\\" in info.filename or ":" in info.filename:
                    raise RuntimeError("Unsafe baseline path.")
            package.extractall(directory)
        baseline = directory / "fnlla-core-2.3.1"
        state = directory / "state.json"
        state.write_text(json.dumps({"prefix": "upgrade_" + os.urandom(6).hex()}))
        def phase(engine, name):
            subprocess.run(["php", str(ROOT / "tests/fixtures/upgrade-worker.php"), str(engine), name, str(state)],
                           cwd=ROOT, check=True, timeout=30)
        try:
            phase(baseline, "seed")
            phase(ROOT, "migrate")
            phase(ROOT, "recover")
            phase(ROOT, "reconcile")
            phase(baseline, "verify-rollback")
        finally:
            phase(ROOT, "cleanup")
    print("Released 2.3.1 upgrade, crash recovery and reconciled rollback passed.")


if __name__ == "__main__":
    main()

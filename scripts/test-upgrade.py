#!/usr/bin/env python3
"""Exercise old and recent immutable Core releases through upgrade, recovery and rollback."""
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
BASELINES = {
    '2.3.1': BASELINE_SHA256,
    '2.8.1': 'de40d8ca061d74503551e15ff8246d9844804ed8ba0138213b6014394b511233',
}


def main():
    if "dbname=fnlla_core_test" not in os.environ.get("FNLLA_CORE_TEST_MYSQL_DSN", ""):
        raise RuntimeError("An isolated fnlla_core_test database is required.")
    for version, checksum in BASELINES.items():
        exercise(version, checksum)
    print('Every pinned Core upgrade, crash recovery and reconciled rollback passed.')


def exercise(version, checksum):
    with tempfile.TemporaryDirectory(prefix="fnlla-upgrade-drill-") as temporary:
        directory = pathlib.Path(temporary)
        archive = directory / "baseline.zip"
        url = f'https://github.com/techayoDEV/fnlla-core/releases/download/v{version}/fnlla-core-{version}.zip'
        with urllib.request.urlopen(url, timeout=30) as response:
            data = response.read(4 * 1024 * 1024 + 1)
        if len(data) > 4 * 1024 * 1024 or hashlib.sha256(data).hexdigest() != checksum:
            raise RuntimeError("Released baseline checksum does not match the pinned artifact.")
        archive.write_bytes(data)
        with zipfile.ZipFile(archive) as package:
            for info in package.infolist():
                path = pathlib.PurePosixPath(info.filename)
                if path.is_absolute() or ".." in path.parts or "\\" in info.filename or ":" in info.filename:
                    raise RuntimeError("Unsafe baseline path.")
            package.extractall(directory)
        baseline = directory / ('fnlla-core-' + version)
        if (baseline / 'VERSION').read_text(encoding='utf-8').splitlines()[0].strip() != version:
            raise RuntimeError('The baseline version does not match its immutable artifact.')
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
    print(f'Released {version} upgrade, crash recovery and reconciled rollback passed.')


if __name__ == "__main__":
    main()

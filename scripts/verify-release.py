"""Verify a stable ZIP, its manifest and a fresh Composer consumer. No publication."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile


def digest(data):
    return hashlib.sha256(data).hexdigest()


def run(command, cwd):
    subprocess.run(command, cwd=cwd, check=True, timeout=180)


def main():
    archive = Path(sys.argv[1]).resolve()
    commit = sys.argv[2]
    assert re.fullmatch(r"[a-f0-9]{40}", commit), "Expected source commit is required"
    expected = archive.with_suffix(archive.suffix + ".sha256").read_text().split()[0]
    assert digest(archive.read_bytes()) == expected, "Archive checksum mismatch"
    with tempfile.TemporaryDirectory(prefix="fnlla-release-") as temp:
        root = Path(temp)
        with zipfile.ZipFile(archive) as package:
            names = package.namelist()
            assert len(names) == len(set(names)), "Duplicate ZIP entries"
            for name in names:
                assert re.fullmatch(r"[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*", name), "Unsafe ZIP path"
                assert all(part not in (".", "..") for part in name.split("/")), "Unsafe ZIP component"
            package.extractall(root / "expanded")
        folders = list((root / "expanded").iterdir())
        assert len(folders) == 1 and folders[0].is_dir(), "Expected one package root"
        source = folders[0]
        provenance = json.loads((source / "FNLLA-PROVENANCE.json").read_text())
        metadata = json.loads((source / "FNLLA-PACKAGE.json").read_text())
        version = (source / "VERSION").read_text().strip()
        assert re.fullmatch(r"\d+\.\d+\.\d+", version), "Stable version required"
        assert provenance["base_commit"] == commit and metadata["source"]["commit"] == commit
        assert provenance["version"] == metadata["version"] == version
        assert provenance["channel"] == metadata["channel"] == "stable"
        assert provenance["release_approved"] is True and metadata["release_approved"] is True
        assert provenance["workspace_dirty"] is False
        manifested = set()
        for line in (source / "FNLLA-MANIFEST.sha256").read_text().splitlines():
            checksum, relative = line.split("  ", 1)
            assert relative not in manifested, "Duplicate manifest entry"
            assert (source / relative).resolve().is_relative_to(source.resolve()), "Unsafe manifest path"
            assert digest((source / relative).read_bytes()) == checksum, "Manifest mismatch: " + relative
            manifested.add(relative)
        actual = {path.relative_to(source).as_posix() for path in source.rglob("*") if path.is_file()}
        assert actual == manifested | {"FNLLA-MANIFEST.sha256"}, "Unmanifested package files"
        consumer = root / "consumer"
        consumer.mkdir()
        package = json.loads((source / "composer.json").read_text())
        package.update(version=version, dist={"type": "zip", "url": archive.as_uri(),
                                            "shasum": hashlib.sha1(archive.read_bytes()).hexdigest()})
        config = {"name": "fnlla-test/release-consumer", "description": "Synthetic release check",
                  "license": "MIT", "repositories": [{"type": "package", "package": package}],
                  "require": {"techayodev/fnlla-core": version},
                  "config": {"allow-plugins": False}}
        (consumer / "composer.json").write_text(json.dumps(config, indent=2) + "\n")
        composer = [shutil.which("composer") or "composer"]
        for binary in [os.environ.get("COMPOSER_BINARY", ""), "C:/ProgramData/ComposerSetup/bin/composer.phar"]:
            if binary and Path(binary).is_file():
                composer = ["php", binary]
                break
        run(composer + ["validate", "--strict"], consumer)
        run(composer + ["install", "--no-interaction", "--prefer-dist", "--no-scripts", "--no-plugins"], consumer)
        installed = consumer / "vendor/techayodev/fnlla-core"
        for relative in actual:
            assert (installed / relative).read_bytes() == (source / relative).read_bytes(), "Installed bytes differ"
        run(["php", "-r", "require 'vendor/autoload.php'; foreach ([Fnlla\\Php\\Support\\RuntimeInspector::class, "
             "Fnlla\\Php\\Support\\RuntimeDoctor::class, Fnlla\\Php\\Events\\OutboxWorker::class] as $class) "
             "{ if (!class_exists($class)) { exit(1); } }"], consumer)
        print(json.dumps({"verified": True, "version": version, "commit": commit, "sha256": expected,
                          "manifest_sha256": digest((source / "FNLLA-MANIFEST.sha256").read_bytes())}))


if __name__ == "__main__":
    main()

"""Verify an immutable stable or explicitly selected review ZIP and a fresh consumer."""
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


def require(condition, message):
    if not condition:
        raise ValueError(message)


def main():
    archive = Path(sys.argv[1]).resolve()
    commit = sys.argv[2]
    options = sys.argv[3:]
    require(options in ([], ['--local-review']), "Unknown artifact verification options")
    local_review = options == ['--local-review']
    require(re.fullmatch(r"[a-f0-9]{40}", commit), "Expected source commit is required")
    expected = archive.with_suffix(archive.suffix + ".sha256").read_text().split()[0]
    require(digest(archive.read_bytes()) == expected, "Archive checksum mismatch")
    with tempfile.TemporaryDirectory(prefix="fnlla-release-") as temp:
        root = Path(temp)
        with zipfile.ZipFile(archive) as package:
            names = package.namelist()
            require(len(names) == len(set(names)), "Duplicate ZIP entries")
            for name in names:
                require(re.fullmatch(r"[A-Za-z0-9_.+-]+(?:/[A-Za-z0-9_.+-]+)*", name), "Unsafe ZIP path")
                require(all(part not in (".", "..") for part in name.split("/")), "Unsafe ZIP component")
            package.extractall(root / "expanded")
        folders = list((root / "expanded").iterdir())
        require(len(folders) == 1 and folders[0].is_dir(), "Expected one package root")
        source = folders[0]
        provenance = json.loads((source / "FNLLA-PROVENANCE.json").read_text())
        metadata = json.loads((source / "FNLLA-PACKAGE.json").read_text())
        version = (source / "VERSION").read_text().strip()
        number = r"(?:0|[1-9][0-9]*)"
        if local_review:
            identifier = r"(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)"
            pattern = rf"{number}\.{number}\.{number}-{identifier}(?:\.{identifier})*(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?"
            require(re.fullmatch(pattern, version), "Distinct SemVer prerelease required for local review")
        else:
            require(re.fullmatch(rf"{number}\.{number}\.{number}", version), "Stable version required")
        require(provenance["base_commit"] == commit and metadata["source"]["commit"] == commit, "Source commit mismatch")
        require(provenance["version"] == metadata["version"] == version, "Package version mismatch")
        require(provenance["channel"] == metadata["channel"] == ('local-review' if local_review else 'stable'), "Package channel mismatch")
        require(provenance["release_approved"] is (not local_review) and metadata["release_approved"] is (not local_review), "Package approval mismatch")
        require(provenance["workspace_dirty"] is False, "Artifact source must be clean and committed")
        manifested = set()
        for line in (source / "FNLLA-MANIFEST.sha256").read_text().splitlines():
            checksum, relative = line.split("  ", 1)
            require(relative not in manifested, "Duplicate manifest entry")
            require((source / relative).resolve().is_relative_to(source.resolve()), "Unsafe manifest path")
            require(digest((source / relative).read_bytes()) == checksum, "Manifest mismatch: " + relative)
            manifested.add(relative)
        actual = {path.relative_to(source).as_posix() for path in source.rglob("*") if path.is_file()}
        require(actual == manifested | {"FNLLA-MANIFEST.sha256"}, "Unmanifested package files")
        consumer = root / "consumer"
        consumer.mkdir()
        package = json.loads((source / "composer.json").read_text())
        package.update(version=version, dist={"type": "zip", "url": archive.as_uri(),
                                            "shasum": hashlib.sha1(archive.read_bytes()).hexdigest()})
        config = {"name": "fnlla-test/release-consumer", "description": "Synthetic release check",
                  "license": "MIT", "repositories": [{"type": "package", "package": package}],
                  "require": {"techayodev/fnlla-core": "^" + version},
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
            require((installed / relative).read_bytes() == (source / relative).read_bytes(), "Installed bytes differ")
        run(["php", "-r", "require 'vendor/autoload.php'; foreach ([Fnlla\\Php\\Support\\RuntimeInspector::class, "
             "Fnlla\\Php\\Support\\RuntimeDoctor::class, Fnlla\\Php\\Events\\OutboxWorker::class] as $class) "
             "{ if (!class_exists($class)) { exit(1); } }"], consumer)
        print(json.dumps({"verified": True, "version": version, "commit": commit, "sha256": expected,
                          "channel": 'local-review' if local_review else 'stable', "release_approved": not local_review,
                          "manifest_sha256": digest((source / "FNLLA-MANIFEST.sha256").read_bytes())}))


if __name__ == "__main__":
    main()

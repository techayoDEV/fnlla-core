"""Install or verify a pinned, local fnlla-brand export. Python standard library only."""
from pathlib import Path, PurePosixPath
import argparse
import hashlib
import json
import os
import tempfile


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(root, relative):
    path = PurePosixPath(relative)
    if not relative or path.is_absolute() or any(p in ('..', '.') for p in path.parts) or '\\' in relative or ':' in relative:
        raise ValueError('Unsafe brand path: ' + relative)
    target = root / relative
    current = target
    while current != root:
        if current.is_symlink():
            raise ValueError('Symbolic links are not valid brand destinations: ' + relative)
        current = current.parent
    if not target.resolve().is_relative_to(root.resolve()):
        raise ValueError('Brand path escapes consumer root')
    return target


def atomic_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    name = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as output:
            name = output.name
            output.write(data)
        os.replace(name, path)
    finally:
        if name and os.path.exists(name):
            os.unlink(name)


def verify(root):
    lock = json.loads((root / 'branding/brand-lock.json').read_text(encoding='utf-8'))
    failures = []
    for relative, expected in lock['files'].items():
        target = safe_path(root, relative)
        if not target.is_file() or digest(target.read_bytes()) != expected:
            failures.append(relative)
    if failures:
        raise ValueError('Brand files changed or missing: ' + ', '.join(failures))
    print(f"{lock['name']} {lock['version']}: {len(lock['files'])} local files verified ({lock['profile']}).")


def install(root, package, profile, adopt=False, dry_run=False):
    package = package.resolve()
    manifest_bytes = (package / 'manifest.json').read_bytes()
    manifest = json.loads(manifest_bytes)
    if manifest.get('name') != 'fnlla-brand' or manifest.get('schema') != 'fnlla.brand-package.v1':
        raise ValueError('Unrecognised brand package')
    if profile not in manifest['profiles']:
        raise ValueError('Unknown consumer profile')
    changes, hashes = {}, {}
    for item in manifest['profiles'][profile]:
        allowed = item['target'].startswith(('public/assets/brand/fnlla/', 'branding/assets/', 'docs/assets/brand/')) or item['target'] in ['branding/tokens.json', 'scripts/brand-consumer.py', 'scripts/branding/build-runtime.php']
        if not allowed:
            raise ValueError('Destination is outside the brand contract: ' + item['target'])
        target = safe_path(root, item['target'])
        source = safe_path(package, item['source'])
        data = source.read_bytes()
        if digest(data) != item['sha256']:
            raise ValueError('Package checksum mismatch: ' + item['source'])
        if item['target'].lower() in {s.lower() for s in changes}:
            raise ValueError('Duplicate package destination')
        changes[item['target']] = data
        hashes[item['target']] = digest(data)
    lock_path = safe_path(root, 'branding/brand-lock.json')
    old_lock = json.loads(lock_path.read_text()) if lock_path.exists() else None
    if old_lock:
        if old_lock['profile'] != profile:
            raise ValueError('Consumer profile cannot change during an update')
        verify(root)
        if old_lock['version'] == manifest['version'] and old_lock['manifest_sha256'] != digest(manifest_bytes):
            raise ValueError('A published brand version cannot change. Increment the package version.')
        removed = set(old_lock['files']) - set(hashes)
        if removed:
            raise ValueError('Removed destinations require an explicit migration: ' + ', '.join(sorted(removed)))
        for relative in set(hashes) - set(old_lock['files']):
            target = safe_path(root, relative)
            if target.exists() and digest(target.read_bytes()) != hashes[relative]:
                raise ValueError('New destination conflicts with a local file: ' + relative)
    elif not adopt:
        raise ValueError('First installation requires --adopt after reviewing --dry-run.')
    if dry_run:
        for relative, data in changes.items():
            target = safe_path(root, relative)
            action = 'unchanged' if target.is_file() and target.read_bytes() == data else 'replace' if target.exists() else 'add'
            print(action + ': ' + relative)
        return
    guard = safe_path(root, 'branding/.brand-update-lock')
    guard.parent.mkdir(parents=True, exist_ok=True)
    guard.mkdir()  # Atomic process lock; never remove another process's lock.
    backups = {}
    try:
        if old_lock:
            verify(root)
        for relative in list(changes) + ['branding/brand-lock.json']:
            target = safe_path(root, relative)
            backups[relative] = target.read_bytes() if target.exists() else None
        if not old_lock:
            # Keep original bytes for review/rollback; no source edits are discarded.
            backup_root = safe_path(root, 'storage/brand-adoption/' + manifest['version'])
            for relative, data in backups.items():
                if data is not None:
                    backup = safe_path(backup_root, relative)
                    if backup.exists() and backup.read_bytes() != data:
                        raise ValueError('Adoption backup already exists with different bytes')
                    atomic_write(backup, data)
        for relative, data in changes.items():
            atomic_write(safe_path(root, relative), data)
        lock = {key: manifest[key] for key in ['name', 'version', 'schema']}
        lock.update(profile=profile, manifest_sha256=digest(manifest_bytes), files=hashes)
        atomic_write(lock_path, (json.dumps(lock, indent=2) + '\n').encode())
    except Exception:
        for relative, data in backups.items():
            path = safe_path(root, relative)
            if data is None:
                path.unlink(missing_ok=True)
            else:
                atomic_write(path, data)
        raise
    finally:
        guard.rmdir()
    verify(root)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['check', 'install'])
    parser.add_argument('--root', type=Path, default=Path.cwd())
    parser.add_argument('--package', type=Path)
    parser.add_argument('--profile', choices=['site', 'framework', 'core'])
    parser.add_argument('--adopt', action='store_true')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    try:
        if args.command == 'check':
            verify(args.root.resolve())
        else:
            if not args.package or not args.profile:
                parser.error('install requires --package and --profile')
            install(args.root.resolve(), args.package, args.profile, args.adopt, args.dry_run)
    except (ValueError, OSError, KeyError) as error:
        raise SystemExit(str(error)) from error


if __name__ == '__main__':
    main()

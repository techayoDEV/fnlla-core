# FNLLA brand consumer: core

The maintained identity sources and authoring tools live in the **fnlla.com**
repository under `branding/` and `scripts/branding/`. This directory contains
versioned exports, not an independently editable brand kit.

`brand-lock.json` pins the installed `fnlla-brand` version, profile, manifest hash
and SHA-256 of every managed file. Product versions remain independent.

## Verify locally

```console
python scripts/brand-consumer.py check
```

No website checkout or network access is needed for this check, application
runtime or normal project builds. Font licence files travel with web fonts.

## Review and update

Build the package in fnlla.com with `python scripts/branding/build-package.py`.
Supply its unpacked version directory explicitly:

```console
python scripts/brand-consumer.py install --package /path/to/fnlla-brand/1.0.1 --profile core --dry-run
python scripts/brand-consumer.py install --package /path/to/fnlla-brand/1.0.1 --profile core
python scripts/brand-consumer.py check
```

Updates reject changed local files, checksum failures, path escapes and modified
packages that reuse an installed version. Reconcile local changes in the
canonical source and export a new version before retrying. Keep the previous
package for rollback. Existing files are backed up on first adoption.

Core uses only its repository identity and documentation artwork. It has no
runtime dependency on fnlla.com, the commercial Framework, a brand service or a
font provider. The Core-specific mark and README artwork remain at their
existing relative paths. Code and package licence terms are unchanged.

# Upload validation hardening

Current contract: Core 2.7.0. Introduced in Core 2.4.0.

`UploadedFile::validate()` checks the actual temporary file size as well as reported
metadata and rejects symlinked temporary files. Previously understated metadata could
bypass the byte limit until an application checked the resulting file. The public
method signature is unchanged; callers relying on oversized or symlinked fixtures must
supply a regular file within the limit. MIME validation continues to inspect bytes.

`UploadedFile::hashName()` now chooses an inert extension from detected MIME bytes.
It retains a compatible original extension for JPEG, PNG, WebP, PDF, plain text,
CSV, ZIP and the configured Office document types; an unknown type receives `.bin`.
It uses a random name instead of a name derived from client metadata. Applications
that relied on preserving an arbitrary original extension must use the original
name as display metadata, not as a public storage path. Calling `hashName()` now
requires a readable temporary file because the extension comes from its bytes.

`FilesystemAdapter::putFile()` validates the upload even when called directly.
An explicit storage name must have a simple basename and the extension selected
by `hashName()`; incompatible names now throw before the file moves. The disk
resolves its configured root and refuses nested symlinks. A deployment may use a
symlink for the disk root itself, such as a shared upload volume, but must prevent
untrusted local processes from replacing paths concurrently during writes.

The generated Core application blocks active file types under `public/uploads`
in its PHP development router and Apache `.htaccess`. Other web servers must
apply the same deny rule before a PHP or other script handler. Review existing
uploads for executable extensions before upgrading; older files are not renamed.

Framework upgrade classification also recognizes shared widget/settings partials and
assets as managed. SEO, newsletter and support configuration stays project-owned.
The plain Core starter removes link underlines on hover while preserving focus cues.
Chat UI, AI routing, business roles, newsletter and SEO workflows remain outside Core.

These changes were introduced in Core 2.4.0 and remain supported in 2.7.0. Existing Core 2.3.1 packages are immutable;
consumers must explicitly update their dependency and lock file.

# Upload validation hardening (unreleased)

`UploadedFile::validate()` checks the actual temporary file size as well as reported
metadata and rejects symlinked temporary files. Previously understated metadata could
bypass the byte limit until an application checked the resulting file. The public
method signature is unchanged; callers relying on oversized or symlinked fixtures must
supply a regular file within the limit. MIME validation continues to inspect bytes.

Framework upgrade classification also recognizes shared widget/settings partials and
assets as managed. SEO, newsletter and support configuration stays project-owned.
The plain Core starter removes link underlines on hover while preserving focus cues.
Chat UI, AI routing, business roles, newsletter and SEO workflows remain outside Core.

This is a source change for a future reviewed release. Existing Core 2.3.1 packages
and consumer lock files have not been rewritten.

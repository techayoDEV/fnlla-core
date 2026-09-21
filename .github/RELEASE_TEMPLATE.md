# FNLLA Core Release Notes Template

```md
# FNLLA Core <version>

<One concise paragraph describing the Core change and the consumer impact.>

## Highlights

- <Public runtime, CLI or contract change.>
- <Compatibility or security outcome.>

## Upgrade notes

- <Required application action or "No migration required.">
- <Minimum supported PHP or extension change, if any.>

## Verification

- Source commit: `<40-character SHA>`
- Exact-commit CI: <workflow URL>
- Distribution ZIP SHA-256: `<sha256>`
- Manifest/provenance assets: <asset names>

## Supported scope and limitations

- Normal isolated PHP requests are supported.
- Long-lived HTTP workers remain unsupported unless a release explicitly says otherwise.
- Production RPO/RTO is application-owned and is not proven by package tests.
- Checksums and CI evidence do not constitute a security certification.

---

## Release record

- **Product:** FNLLA Core
- **Version:** `<version>`
- **Package:** `techayodev/fnlla-core`
- **Source:** immutable Git tag `v<version>`
- **Licence:** MIT
- **Product and documentation:** [fnlla.com](https://fnlla.com)
```

Never replace an existing tag or artefact. Keep release claims tied to the exact
source commit, uploaded distribution and evidence for that version.

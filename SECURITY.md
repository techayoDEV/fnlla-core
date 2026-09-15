# FNLLA Core Security Policy

FNLLA Core is maintained as a public MIT-licensed framework core by TechAyo LTD
(techayo.co.uk). Product website: [fnlla.com](https://fnlla.com). Policy edition:
**2.2.4**.

## Version And Support Boundary

The source edition is recorded in [VERSION](VERSION). A source version is not
evidence of a published release or a security certification. Consult the
repository releases and security advisories for available updates.

If you believe you have found a security issue in FNLLA Core, please report it
privately.

## Reporting Routes

1. Report a vulnerability privately on GitHub:
   `https://github.com/techayoDEV/fnlla-core/security/advisories/new`
2. If GitHub reporting is unavailable, email `hello@techayo.co.uk` with the
   subject `FNLLA CORE SECURITY`. Start with a minimal, redacted description and
   agree on a suitable private channel before sending sensitive evidence.

Do not report exploitable vulnerabilities in public issues.

## Please Include

- a clear description of the issue
- affected file, class, command or bootstrap path
- environment details if relevant
- reproduction steps
- impact assessment
- any temporary mitigation already identified

Do not paste `.env`, database dumps, access tokens, session cookies or raw
customer data into public issues.

## Operator Checks

For this package repository, run:

```bash
php scripts/test.php
php scripts/lint.php
php scripts/static-analysis.php
```

For deployed applications using FNLLA Core, also review your application routes,
environment configuration, dependency lockfile, web-server document root,
session storage, cache storage, database permissions and backup procedures.

## Deployment Boundaries

- Serve only the application `public/` directory; never expose repository roots,
  private storage, Composer credentials, backups or environment templates.
- Use HTTPS, `APP_DEBUG=false`, trusted host/proxy allowlists and secure session
  cookies in production.
- Keep cache, sessions, queues, uploads and logs outside the public document
  root and writable only by trusted runtime identities.
- File rollback does not reverse schema changes, payments, uploads or delivered
  messages.

Downstream hosting, application code, third-party scripts, server hardening,
secret management and operational monitoring remain the responsibility of the
team operating that separate deployment.

# Security Policy

## Reporting a vulnerability

Please **do not** open a public GitHub issue. Email the maintainer at
info@wadejbeckett.com with:

- a description of the issue,
- a minimal reproducer or affected code path, and
- the version of the extension you observed it on (`meta.xml` → `<version>`).

Initial acknowledgement target: 72 hours. Fix or coordinated-disclosure
timeline depends on severity.

## Scope

In scope:
- Credential exposure (Cloudflare API token, account ID).
- Privilege escalation via the Plesk admin UI.
- Unauthenticated triggering of the sync poll or Resync endpoints.
- Cross-tenant data exposure (anything visible to one Plesk customer
  about another's domains or Cloudflare state).

Out of scope:
- Vulnerabilities in Plesk or Cloudflare themselves (report to those
  vendors).
- Findings that require pre-existing Plesk admin access — the extension
  is admin-only by design (until v1.0).

## Supported versions

Only the latest tagged release receives security fixes. Older versions
are out of support.

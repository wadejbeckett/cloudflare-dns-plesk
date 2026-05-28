# Changes

Mirror of [CHANGELOG.md](CHANGELOG.md) under Plesk's expected catalog
filename. Edit CHANGELOG.md; copy the updated content here at release time.

---

All notable changes to this project are documented here. Versions follow
[SemVer](https://semver.org/). The authoritative per-tag detail lives in the
[GitHub Releases](https://github.com/wadejbeckett/cloudflare-dns-plesk/releases).

## [0.5.11] — 2026-05-28
### Fixed
- A type-conflict where Cloudflare already has a foreign record at the
  same name as a desired record but with an incompatible type (e.g.
  Plesk wants `ftp.example.com CNAME → example.com.`, CF already has
  `ftp.example.com A 1.2.3.4` from a pre-extension manual entry, per
  RFC 1034 §3.6.2 these can't coexist) no longer loops `[81053]` rejections
  every poll. `ZoneSync::plan` detects the collision at plan time via a
  new `findTypeConflict` helper, suppresses the doomed CREATE, surfaces it
  in the per-domain status row as `Synced — N records, M conflict(s)`,
  and logs a clear `conflict at <name>: foreign <type> exists, cannot
  create <type>` line. Resolution path: the operator removes either the
  Plesk record (if CF is authoritative for that name) or the foreign CF
  record (if Plesk should be).
### Added
- `SyncPlan::$conflicts` — new public field carrying `{type, name,
  foreign_type}` tuples for surfaced conflicts. Constructor signature
  extended with a `$conflicts = []` parameter at the end (backwards
  compatible with all existing call sites).
- 5 new ZoneSync tests covering A/CNAME mutual exclusion, CNAME-vs-CNAME,
  non-conflicting coexistence (TXT over A), and the subtle case where a
  managed record at the same name being deleted in the same plan does
  NOT trigger a self-conflict (because apply() runs deletes before
  creates). Suite: 64 tests, 168 assertions, all green
  (1 skipped chmod-0 case under root, unchanged from v0.5.10).

## [0.5.10] — 2026-05-28
### Fixed
- The per-domain lockfile (`sync-<domain>.lock`) can now be acquired
  even when it was previously created under a different uid — e.g. an
  admin who ran the sync manually as root, leaving a root-owned file
  the psaadm-owned cron could not write. `LockFile::open` (new
  `Noiz\CloudflareDns\PleskDns\LockFile`) tries write-mode fopen
  first, falls back to read-mode when the file exists but isn't
  writable, and uses `flock` on the resulting fd — works on any open
  mode on Linux. Best-effort `chmod 0666` after open prevents
  recurrence on freshly-created lockfiles.
### Added
- `tests/LockFileTest.php` — 5 cases covering fresh open, read-only
  fallback, chmod-0 throw, missing-parent throw, repeat-open
  idempotence. Suite: 59 tests, 150 assertions, all green
  (1 skipped: chmod-0 case auto-skips when running as root, since
  root bypasses POSIX permission checks).

## [0.5.9] — 2026-05-28
### Fixed
- Auto-enable now enrols each Plesk domain exactly once instead of
  re-enrolling manually-deactivated domains on every cron cycle (N1
  from the 2026-05-28 code-quality audit). State is tracked in a new
  `seen_domains` setting. A one-time bootstrap on first upgrade marks
  every existing domain as already-seen — no auto-enrolment blast on
  upgrade.
### Added
- `DESCRIPTION.md`, `CHANGES.md`, `meta.xml <vendorUrl>` and
  `<os>unix</os>` — structural prep for an eventual Plesk Extensions
  Catalog submission. No submission planned yet; the catalog path is
  deferred until production hardening is comfortable.
- `tests/AutoEnableTest.php` — seven cases for the new enrolment
  predicate (`AutoEnable::computeNewDomains`), including the explicit
  N1 regression case. Suite: 54 tests, 140 assertions, all green on
  Plesk PHP 8.3.31.

## [0.5.8] — 2026-05-28
### Security
- Explicit `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST` on every Cloudflare request — pins TLS verification at the call site so a future php.ini change cannot silently leak the API token.
- Reject CR/LF/NUL in the API token at construction time and in every outgoing HTTP header — defence-in-depth against header injection.
- Defang CR/LF/NUL in `sync.log` writes — Cloudflare error bodies can no longer forge log lines.
### Fixed
- `domainStatusAction` now validates its `domain` argument like the other AJAX endpoints (was the only one accepting arbitrary input).
- Lockfile `fopen` failures (disk full, permission denied) no longer masquerade as the benign "another sync in progress" case — they surface as an explicit error status and a `lock acquisition failed` log line.
- `removeTask` failures during install now log to STDERR so an operator can see why a stale scheduler task survived an upgrade.
- README's EXTPLESK-13681 link pointed back at the repo itself — now targets the canonical Plesk change-log URL.
- Stale "every 5 minutes" docblock in `post-install.php` corrected to `EVERY_MIN`.
- Dead breadcrumb to a non-existent README "Upgrading from v0.4.x" section removed.
### Added
- README "Recommended: install the pre-built zip" section — was previously sending admins down the build-from-source path despite 21 published Release zips.
- Skipped records (DS, HTTPS, oversized TXT) now surface as `Synced — N records, K skipped` in the per-domain UI status, not only in `sync.log`.
- `cf_zone_id_<domain>` cache is cleared when a domain is deactivated in the UI — re-activation re-resolves the CF zone rather than reusing a possibly-stale ID.
- `SECURITY.md` (vulnerability disclosure policy).
- `CHANGELOG.md` (this file).
- ROADMAP shipped/v0.5 list now ends at v0.5.7 (was stuck at v0.5.6).
### Changed
- PHP floor bumped `>=8.0` → `>=8.1` (PHP 8.0 is EOL; Plesk bundles 8.1+).
- PHPUnit dev constraint bumped `^9.6` → `^9.6.33` to clear GHSA-vvj3-c3rp-c85p.
- CI matrix drops PHP 8.0, gains a `composer audit` step in the `test` job.
- `composer.lock` is no longer git-ignored.

## [0.5.7] — 2026-05-28
### Fixed
- Seven cleanups from the v0.5.7 codebase audit (see _internal/AUDIT-2026-05-28-codebase-review.md).

## [0.5.6] — 2026-05-28
### Removed
- Dead custom-DNS-backend code (~600 lines).

## [0.5.5] — 2026-05-27
### Removed
- The Plesk Navigation hook (unused).

## [0.5.4] — 2026-05-27
### Fixed
- Search hook now uses a named class (Plesk Lucene cannot load anonymous classes).

## [0.5.3] — 2026-05-27
### Added
- Plesk top-bar search integration; per-row Resync button lockout during sync.

## [0.5.2] — 2026-05-27
### Fixed
- Stopped calling `server_dns --disable-custom-backend` in lifecycle hooks
  — that call evicts whichever extension currently holds the slot,
  including slave-dns-manager.

## [0.5.1] — 2026-05-27
### Changed
- Scheduled poll cadence reduced from 5 min to 1 min.

## [0.5.0] — 2026-05-27
### Changed
- Replaced the event-driven custom-DNS-backend with a `pm_Scheduler` poll.
  The extension no longer claims Plesk's exclusive custom-DNS-backend slot,
  so it now coexists with `slave-dns-manager` and other DNS-backend
  extensions.

(See git history for v0.1.0 → v0.4.13.)

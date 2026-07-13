# Changelog

All notable changes to this project are documented here. Versions follow
[SemVer](https://semver.org/). The authoritative per-tag detail lives in the
[GitHub Releases](https://github.com/wadejbeckett/cloudflare-dns-sync/releases).

## [Unreleased]

## [0.5.19] — 2026-07-13
### Security
Hardening pass following a full code audit — all defense-in-depth; no
exploitable vulnerability was found in 0.5.18.
- **Lock files and `sync.log` are no longer world-accessible.** Advisory-lock
  files in the extension var dir are now born `0660` (umask-bracketed, so
  there is no world-readable window) with their group aligned to the var
  dir's group, so an unprivileged local user can no longer open a lock and
  hold it to stall the scheduled sync. `sync.log` gets the same treatment on
  creation, keeping zone names and sync diffs out of world-readable reach.
  On upgrade, `post-install` sweeps files left behind by earlier versions to
  the new permissions. Cross-uid locking (scheduler vs. manual root runs)
  keeps working via the group grant.
- **Cloudflare ids are validated before URL use.** Zone and record ids are
  checked against a strict character allowlist before being interpolated
  into API request paths, and the client rejects request paths containing
  whitespace, `?`, `#`, or control bytes — closing any path/query-injection
  route via a crafted id.
- **Settings are validated on save.** The Cloudflare account ID must be a
  32-character hex id and the alert email must be a valid address; malformed
  values are rejected with a clear error instead of breaking sync or the
  watchdog alert downstream.
### Changed
- **API retries now also carry a wall-clock ceiling.** Retrying was already
  bounded by the retry count (3 attempts, 30s transport timeout each); a
  ~2-minute wall-clock budget now backstops it so a future change to either
  knob can never let a degraded upstream stall a poll cycle unboundedly.

## [0.5.18] — 2026-05-30
### Added
- **Proxy-status badge** on the settings page — a read-only, per-domain
  indicator of whether the web-facing records (apex + `www`) are proxied
  through Cloudflare. Shows **Proxied** (orange) only when *every* proxiable
  A/AAAA/CNAME at both apex and `www` is orange-clouded — so a stray grey-cloud
  AAAA (an IPv6 origin leak) reads as **Not proxied** rather than masking it;
  **Missing records** when apex or `www` has no A/AAAA/CNAME. Computed during
  the existing poll from records already fetched (no extra Cloudflare calls)
  and stored in the per-domain status; refreshes on page reload. Backed by a
  new panel-agnostic `Cloudflare\ProxyPosture` core class with unit tests.

## [0.5.17] — 2026-05-30
### Added
- **Watchdog.** A second scheduled task (every 5 minutes) checks a heartbeat
  the poll writes each cycle; if the every-minute poll stops running while the
  extension is configured, it emails the administrator and shows a banner on the
  settings page. This guards the silent-scheduler failure mode behind the
  2026-05-25 outage. New optional **"Alert email"** setting overrides the
  recipient (defaults to the Plesk administrator address).
- Settings page now shows the on-server path of `sync.log`.
### Changed
- **`sync.log` is now rotated in-code** — capped at ~5 MB with two prior
  generations kept (`sync.log.1`, `sync.log.2`). Self-contained in the
  extension's var dir (no `/etc/logrotate.d` file to leave behind), so it stays
  clean-uninstall-safe and ports to any future panel adapter.
- `post-install` now reconciles its scheduled tasks idempotently (keeps the
  poll and the watchdog, removes only strays/duplicates) instead of
  remove-all-then-re-add — so registering the watchdog never deletes the poll,
  and upgrades still converge to exactly one of each.

## [0.5.16] — 2026-05-30
### Fixed
- **Scheduled polls no longer pile up under load.** A slow domain (Cloudflare
  latency plus the API client's retry budget) could stretch one poll cycle past
  the every-minute cron interval, so the next cron found each domain's
  per-domain lock held and logged a `another sync in progress, skipping this
  cycle` line per domain — a recurring burst of log noise plus duplicated work.
  A scheduled cycle now takes a single process-wide run lock
  (`sync-poll-all.lock`) and an overlapping cycle exits at once and silently.
  The on-demand "Resync this domain" path is unaffected (it never takes the run
  lock, so a manual resync is never blocked by a long scheduled cycle).

## [0.5.15] — 2026-05-30
### Changed
- **Repository restructured into a monorepo.** The panel-agnostic Cloudflare
  core now lives in `core/` (composer package `noiz/cloudflare-dns-sync-core`)
  and the Plesk extension in `panels/plesk/` (`noiz/cloudflare-dns-sync-plesk`,
  path-depending on the core). The installable Plesk package is built with
  `build/build-panel.sh plesk <version>` and is byte-for-byte identical to the
  previous single-tree build — the shipped extension is unchanged.
- **Ownership marker is now panel-neutral: `[noiz-dns-sync]`** (was
  `[plesk-dns-sync]`). Records stamped by earlier versions are still recognised
  as managed, so no migration is required and existing record comments are left
  untouched; only newly created records use the new marker.

## [0.5.14] — 2026-05-29
### Fixed
- The Plesk server's own hostname (e.g. `neo.noiz.co.za` on neo) is
  no longer offered as a syncable row in the settings page or
  auto-enrolled by the cron. Activating sync on the server's own
  domain risked panel reachability if Cloudflare is authoritative
  for the parent zone. New helper `Noiz\CloudflareDns\PleskDns\
  SyncableDomain::isSyncable` filters in both the settings page
  and the auto-enable path.
- Slave / secondary Plesk zones no longer appear as syncable either.
  These mirror an external primary nameserver in Plesk and have no
  Plesk-authored content of their own; syncing them to Cloudflare
  as authoritative would push empty or stale records. Zone type is
  detected via `pm_Dns_Zone::getType()` when available, falling
  back to a `SELECT type FROM dns_zone` query, defaulting to
  inclusion if neither path can answer (so a misconfigured Plesk
  doesn't silently hide working domains).
### Added
- `plib/library/PleskDns/SyncableDomain.php` — pure helper for the
  syncability predicate.
- `tests/SyncableDomainTest.php` — host-sensitive unit coverage
  of the gethostname comparison. Zone-type detection is Plesk-
  runtime-dependent and covered by manual integration testing.
  Suite: 75 tests, 202 assertions, all green on Plesk PHP 8.3.31.

## [0.5.13] — 2026-05-28
### Fixed
- TXT records that Cloudflare stores as a single continuous string
  (the typical case when added manually via the dashboard, or returned
  by CF's API on any DKIM/SPF/DMARC record under 2 KB) no longer
  false-negative against the same content rendered by Plesk's
  BIND-style 255-byte chunking. `Record::normalisedContent` for TXT
  now collapses the literal 3-byte chunk separator (`" "`) in addition
  to stripping outer quotes. Resolves the v0.5.12 content conflict on
  `google._domainkey.escentia.co.za` observed live on `neo.noiz.co.za`
  2026-05-28, and the same brandexpert.co.za DKIM duplicate pattern
  from earlier testing. Foreign DKIM/SPF/DMARC records with identical
  content but different chunking form now adopt cleanly on first sync
  rather than surfacing as a content conflict.
### Added
- New `tests/RecordTest.php` with 5 cases covering chunked-vs-unchunked
  TXT equivalence (the live regression in test form), single-string
  quoted vs unquoted, three-way consistency on 400-byte DKIM keys,
  protection against over-collapsing distinct content, and a non-TXT
  sanity guard. Plus 1 new ZoneSync integration test
  (`testTxtChunkedDesiredAdoptsUnchunkedForeign`) confirming chunked
  Plesk desired adopts unchunked CF foreign. Suite: 73 tests, 200
  assertions, all green on Plesk PHP 8.3.31 (1 skipped chmod-0 case
  under root, unchanged from v0.5.10).

## [0.5.12] — 2026-05-28
### Fixed
- Same-name same-type records in Cloudflare with different content
  than the Plesk-side record (e.g. brandexpert's foreign SPF/DKIM/SRV
  alongside a Plesk-side fresher version) no longer get silently
  duplicated. `ZoneSync::plan` detects the content conflict at plan
  time (after the v0.5.11 type-conflict check), suppresses the CREATE,
  and surfaces as `Synced — N records, M conflict(s)` with a distinct
  `content conflict at <name> (<type>): foreign record with different
  content exists, refusing to duplicate` log line. The marker model's
  protection of operator-authored CF state is preserved; the operator
  resolves manually (delete one side in CF dashboard, or stamp the
  marker into the foreign record's comment to bring it under
  management). A future v0.5.13 will add a resolve-from-UI flow.
### Added
- 3 new ZoneSync tests covering the SPF/DKIM/SRV patterns from the
  brandexpert.co.za observation, plus a TTL-only sanity test
  confirming `sameValue` is content-only (TTL alone doesn't block
  adoption). Suite: 67 tests, 180 assertions, all green on Plesk
  PHP 8.3.31.
### Changed
- SyncPlan conflict entries now carry a `'reason' => 'type'|'content'`
  field distinguishing the v0.5.11 RFC 1034 collision from the new
  v0.5.12 content conflict. v0.5.11 conflict tests updated to assert
  this field. No schema break — operators / log readers see the same
  UI count; the reason surfaces in distinct sync.log lines.

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

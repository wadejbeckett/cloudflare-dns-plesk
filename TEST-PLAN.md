# Manual Test Plan

End-to-end tests against a real Plesk + Cloudflare. The goal: exercise
every Plesk-side DNS operation and actively try to break the sync.

**Environment:** dev Plesk box · throwaway domain `5gw.co.za` · a
Cloudflare test account.

**Legend:** ✅ passed · ⬜ not yet run · ⚠️ issue found

**v0.1 → v0.4 baseline:** 23 scenarios passed end-to-end against a real
Plesk + Cloudflare (2026-05-21). v0.5.x changed the underlying transport
from event-driven custom-DNS-backend to a 1-minute scheduled poll; the
record-level semantics below are unchanged but lifecycle scenarios (§6)
were rewritten.

## 1. Zone lifecycle

| # | Scenario | Expected | Status |
|---|---|---|---|
| 1.1 | Add a domain in Plesk | Cloudflare zone auto-created; all records synced, each stamped `[plesk-dns-sync]` | ✅ |
| 1.2 | Remove the domain in Plesk | Cloudflare zone left intact (conservative, by design) | ✅ |
| 1.3 | Remove then re-add the domain in Plesk | Existing CF zone re-adopted; records reconciled; no duplicates | ✅ |
| 1.4 | Add a domain whose zone already exists in Cloudflare | Zone reused (not duplicated); identical records adopted | ✅ |

## 2. Record operations

| # | Scenario | Expected | Status |
|---|---|---|---|
| 2.1 | Add an A / AAAA / CNAME / MX / TXT record in Plesk | Record appears in Cloudflare on the next poll, marked | ✅ |
| 2.2 | Change a record's content (IP / target / value) | Record `PATCH`ed; proxy state preserved | ✅ |
| 2.3 | Change a record's TTL | TTL updated (skipped for proxied records — Cloudflare forces them to auto-TTL) | ✅ |
| 2.4 | Delete a record in Plesk | Record removed from Cloudflare | ✅ |
| 2.5 | Add an SRV / CAA / TLSA record | Synced into Cloudflare's structured `data` form | ✅ |

## 3. Multiple records / round-robin

| # | Scenario | Expected | Status |
|---|---|---|---|
| 3.1 | Two A records at the same name | Both synced as separate Cloudflare records | ✅ |
| 3.2 | Change one of two same-name A records | Only that record updates; the other untouched | ✅ |
| 3.3 | Proxy one of two same-name A records, then sync | Each record's proxy state preserved independently | ✅ |

## 4. Proxy-state preservation — the core guarantee

| # | Scenario | Expected | Status |
|---|---|---|---|
| 4.1 | Orange-cloud a record, change its content in Plesk | Content updates; `proxied:true` survives | ✅ |
| 4.2 | Orange-cloud a record, change an unrelated record | Proxied record untouched, stays proxied | ✅ |
| 4.3 | A grey record stays grey through changes | `proxied:false` preserved | ✅ |
| 4.4 | A record created directly in Cloudflare (no marker) | Never modified or deleted by a sync — left as a foreign record | ✅ |

## 5. Robustness — "try to break it"

| # | Scenario | Expected | Status |
|---|---|---|---|
| 5.1 | Wildcard record (`*.5gw.co.za`) | Synced correctly | ✅ |
| 5.2 | Long / quoted TXT value | Synced correctly; long values split into 255-byte chunks; existing unquoted records are force-updated to canonical `"..."` form | ✅ |
| 5.3 | Create a subdomain in Plesk (shares parent zone) | Subdomain's records sync as part of the parent zone | ✅ |
| 5.4 | Issue a Let's Encrypt cert (`_acme-challenge` TXT) | The ACME TXT syncs on next poll; an existing foreign `_acme-challenge` is untouched | ✅ |
| 5.5 | Invalid API token configured | Poll fails gracefully (logged in `sync.log`, status row shows "Sync failed"); Plesk DNS not affected | ✅ |
| 5.6 | Cloudflare unreachable during a poll cycle | Poll script logs the error, marks affected domain row "Sync failed", recoverable on next cycle | ✅ |
| 5.7 | Add a regular subdomain in Plesk that shares the parent's DNS zone | Subdomain does **not** appear as its own row on the settings page — only main domains are listed. Its records still sync as part of the parent zone (covered by 5.3). | ⬜ |

## 6. v0.5.x poll-mode lifecycle

| # | Scenario | Expected | Status |
|---|---|---|---|
| 6.1 | Fresh install on a Plesk with no DNS-backend extension currently registered | `post-install.php` registers a `pm_Scheduler` task running every minute; does NOT claim Plesk's custom-DNS-backend slot. `plesk db -Ne "SELECT command FROM ScheduledTasks WHERE description LIKE '%cloudflare%'"` shows the entry. | ⬜ |
| 6.2 | In-place upgrade (`--install` over an existing install) | The previous scheduled task is replaced (not duplicated). slave-dns-manager remains in whatever state it was — our install does NOT touch its enable state or evict it from the custom-backend slot. | ⬜ |
| 6.3 | True uninstall (`--uninstall`) | The scheduled task is removed; we do NOT call `--disable-custom-backend` (would evict whoever else holds the slot). `plesk db` shows zero ScheduledTasks for our module. | ⬜ |
| 6.4 | Install with `slave-dns-manager` enabled and serving secondaries | Both extensions enabled in `Modules` table. BIND notify directives for activated zones unchanged. No GUI disable. | ⬜ |
| 6.5 | Scheduled poll fires on its own with no admin action | sync.log shows `[poll]` entries when there's drift to apply, silence at steady-state. Reconciliation happens within 1 minute of any Plesk-side DNS change. | ⬜ |

## 7. On-demand resync UI

| # | Scenario | Expected | Status |
|---|---|---|---|
| 7.1 | Click the per-row refresh icon on an activated domain | Row goes through "Resyncing… → Synced — N records". Toggle stays in "on" position. `sync.log` shows the poll. | ⬜ |
| 7.2 | Per-row refresh icon visibility | Only visible when the row's toggle is on. Toggling on reveals it; toggling off hides it. | ⬜ |
| 7.3 | Click "Resync all activated domains" with N activated | Button is disabled with a "Resyncing N of M…" countdown. Each row resolves independently. Button re-enables only when ALL rows complete. | ⬜ |
| 7.4 | Resync button stays disabled mid-sync | A second click while polling is in progress does nothing — the button is unclickable. | ⬜ |

## 8. Plesk UI integration

| # | Scenario | Expected | Status |
|---|---|---|---|
| 8.1 | Plesk extension icon | Orange cloud with white sync arrow appears on the Plesk Extensions page (instead of the default lego placeholder). Visible at 32 px and 64 px renderings. | ⬜ |
| 8.2 | Top-bar search | Typing `cloudflare`, `dns sync`, or `proxy` in Plesk's top-bar search returns a "Cloudflare DNS Sync" hit linking to the extension's settings page. | ⬜ |
| 8.3 | Activation modal | Toggling a domain ON opens the DNSSEC + registrar-NS checklist modal. Both items must be checked before "Activate and sync" enables. Cancel closes the modal and reverts the toggle. | ⬜ |

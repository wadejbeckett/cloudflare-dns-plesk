# Manual Test Plan

End-to-end tests against a real Plesk + Cloudflare. The goal: exercise every
Plesk-side DNS operation and actively try to break the sync.

**Environment:** dev Plesk box · throwaway domain `5gw.co.za` · a Cloudflare
test account.

**Legend:** ✅ passed · ⬜ not yet run · ⚠️ issue found

**Run 2026-05-21:** 15 of 19 remaining scenarios passed; the 4 zone-lifecycle /
uninstall scenarios (1.2–1.4, 5.7) are destructive and run last.

## 1. Zone lifecycle

| # | Scenario | Expected | Status |
|---|---|---|---|
| 1.1 | Add a domain in Plesk | Cloudflare zone auto-created; all records synced, each stamped `[plesk-dns-sync]` | ✅ |
| 1.2 | Remove the domain in Plesk | Cloudflare zone left intact (conservative, by design) | ⬜ |
| 1.3 | Remove then re-add the domain in Plesk | Existing CF zone re-adopted; records reconciled; no duplicates | ⬜ |
| 1.4 | Add a domain whose zone already exists in Cloudflare | Zone reused (not duplicated); identical records adopted | ⬜ |

## 2. Record operations

| # | Scenario | Expected | Status |
|---|---|---|---|
| 2.1 | Add an A / AAAA / CNAME / MX / TXT record in Plesk | Record appears in Cloudflare, marked | ✅ |
| 2.2 | Change a record's content (IP / target / value) | Record `PATCH`ed; proxy state preserved | ✅ |
| 2.3 | Change a record's TTL | TTL updated (skipped for proxied records — they are auto-TTL) | ✅ |
| 2.4 | Delete a record in Plesk | Record removed from Cloudflare | ✅ |
| 2.5 | Add an SRV or CAA record | Skipped with a log note (not yet supported) | ✅ |

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
| 5.2 | Long / quoted TXT value | Synced correctly | ✅ |
| 5.3 | Create a subdomain in Plesk | Its DNS records sync | ✅ |
| 5.4 | Issue a Let's Encrypt cert (`_acme-challenge` TXT) | The ACME TXT syncs; an existing foreign `_acme-challenge` is untouched | ✅ |
| 5.5 | Invalid API token configured | Handler fails gracefully (logged, non-zero exit); Plesk DNS not broken | ✅ |
| 5.6 | Cloudflare unreachable during a change | Graceful failure, logged, recoverable on next sync | ✅ |
| 5.7 | Uninstall the extension | Custom DNS backend deregistered; Plesk DNS back to normal | ⬜ |

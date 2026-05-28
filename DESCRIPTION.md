# Cloudflare DNS Sync

Cloudflare DNS Sync is a one-way, non-destructive DNS synchronisation
extension for Plesk. It treats Plesk as the source of truth for record
content and TTL, and treats Cloudflare as the source of truth for the
state Cloudflare alone manages: proxy (orange-cloud) status, per-record
comments, tags, and page-rule associations. The extension is for Plesk
administrators who manage DNS in Plesk but rely on Cloudflare for
proxying, caching, or page rules and need their Plesk-side workflow to
flow into Cloudflare without trampling Cloudflare-side configuration.

## Why this extension exists

The official Cloudflare DNS extension shipped by Plesk (`cloudflaredns`,
v1.0.8) has a long-standing defect tracked as **EXTPLESK-13681**: every
synchronisation cycle resets the Cloudflare proxy state of managed
records back to grey-cloud (DNS-only). An administrator who switches the
orange cloud on in the Cloudflare dashboard sees it switched off again
on the next Plesk-driven sync. The canonical Plesk change-log lives at
<https://docs.plesk.com/release-notes/obsidian/change-log/>.

Cloudflare DNS Sync was written to solve that one problem
unambiguously. It never sets, clears, or even reads the proxy flag as
part of its own writes — that field is owned by Cloudflare and stays
owned by Cloudflare for the lifetime of the record.

## How it works

The extension registers a scheduled task via Plesk's `pm_Scheduler`
that fires on the `EVERY_MIN` cadence (once per minute). On each tick,
for each activated domain, the task:

1. Reads Plesk's current DNS state via the Plesk SDK
   (`pm_Dns_Zone::getRecords()`).
2. Reads the live Cloudflare zone via the Cloudflare REST API.
3. Diffs the two and applies the minimum set of `PATCH` / `POST` /
   `DELETE` calls needed to reconcile them.

Ownership of a Cloudflare record is established by stamping the marker
`[plesk-dns-sync]` into the record's Cloudflare comment. The diff
engine ignores any record that does not carry that marker, so records
created directly in Cloudflare (or by another service) are never
touched. The marker survives reinstall and upgrade.

Updates are issued as HTTP `PATCH`, which is a *partial* update — only
the fields the diff engine explicitly writes (content, TTL) are sent,
so every Cloudflare-managed field (proxy, comment payload around the
marker, tags) is left untouched. Where a record's identity changes in a
way that would otherwise require a destroy-and-recreate, the diff
engine prefers an in-place update so the record's Cloudflare-side
state and page-rule attachments survive.

## What it preserves

- **Proxy state (orange-cloud).** Set in Cloudflare, persists across
  every Plesk-driven sync.
- **Per-record comments.** The `[plesk-dns-sync]` marker is appended
  to, never replacing, any operator-set comment text.
- **Tags.** Cloudflare tags are not enumerated or written by this
  extension.
- **Page-rule associations.** Because updates are in-place and use
  `PATCH`, the rule's record reference remains valid.

## What it does NOT do

- **No reverse sync.** Records created in Cloudflare are not pulled
  back into Plesk. The data flow is strictly one-way: Plesk to
  Cloudflare.
- **No registrar delegation.** Pointing a domain's nameservers at
  Cloudflare remains the administrator's job.
- **No custom-DNS-backend slot claim.** Since v0.5.0 the extension
  polls Plesk's DNS state instead of registering as Plesk's
  exclusive custom DNS backend. This means it coexists with
  `slave-dns-manager` and any other DNS-backend extension. The one
  exception is the official "DNS Integration for Cloudflare"
  extension, which covers the same job from the opposite direction
  and should not be run alongside this one.

## Supported record types

`A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `CAA`, `TLSA`.

`SOA` and `NS` are intentionally skipped — Cloudflare manages those
itself. Other types (`DS`, `HTTPS`, oversized `TXT` payloads) are
skipped and the skip is logged to `<extension-var-dir>/sync.log` so an
operator can see exactly why a record was not synced.

## Per-domain activation

The settings page lists every main domain on the server with an on/off
switch. Activating a domain pushes its current Plesk DNS state to
Cloudflare immediately and reports the result inline
(`Synced — N records`). If the Cloudflare zone does not exist yet, the
extension creates it. Deactivating a domain stops further syncing; the
Cloudflare zone is left intact.

An **Auto-enable new domains** toggle, off by default, enrols each
newly created Plesk domain in the sync list automatically. With the
toggle off, nothing syncs until an operator opts the domain in.

Per-row and bulk **Resync** controls force an immediate reconciliation
when sub-minute latency is required.

## Requirements

- Plesk Obsidian **18.0.55** or newer.
- PHP **8.1** or newer (bundled with current Plesk).
- A Cloudflare API token (not the Global API Key) with these
  permissions:
  - `Zone` · `Zone` · `Edit`
  - `Zone` · `DNS` · `Edit`
- A Cloudflare account ID.

## License

GPL-3.0-or-later.

## Source and issues

<https://github.com/wadejbeckett/cloudflare-dns-plesk>

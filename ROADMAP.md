# Roadmap

High-level direction. Granular work items live in
[GitHub Issues](https://github.com/wadejbeckett/cloudflare-dns-plesk/issues).

## Shipped

### v0.1 → v0.3 — foundation ✅

- One-way, non-destructive Plesk → Cloudflare DNS sync via the custom-
  DNS-backend slot
- Cloudflare API client + diff engine (ownership-aware, `PATCH`-based)
- Auto-create the Cloudflare zone on first activation
- Settings page — API token, account ID, per-domain on/off toggles
- Comment-marker ownership (`[plesk-dns-sync]`)
- Proxy-state preservation — verified end to end
- Per-domain activation + optional auto-enable for newly added domains
- `SRV` / `CAA` record support (structured `data` form)
- `TLSA` record support (mail DANE)
- 23-scenario manual test pass against real Plesk + Cloudflare

### v0.4 — operational maturity ✅

- GitHub Actions: CI on PHP 8.0/8.3/8.4/8.5, release zip published
  automatically on `vX.Y.Z` tag
- DNSSEC awareness — Phase 1 (UI guardrail / activation modal)
- Activation UX — background sync with polled status, sub-second
  single-zone trigger (replacing `--sync-all-zones`)
- Subdomain filtering — only main domains appear as toggleable rows
- TXT canonical-form quoting — values wrapped to `"..."` so Cloudflare's
  UI stops flagging "missing quotes" on records
- Resync UI — per-row refresh icon + "Resync all activated" bulk button
- Cloudflare-themed extension icon (orange cloud, sync arrow)
- _meta/icons/ + GH workflow bundles them
- Slave-dns-manager regression diagnosed — see v0.5.0 fix

### v0.5 — coexist with slave-dns-manager ✅

The slot-conflict era. Plesk's custom-DNS-backend slot is exclusive;
claiming it evicted slave-dns-manager and broke BIND secondary
replication for every domain on the server.

- v0.5.0 — replaced event-driven custom-backend with poll-mode via
  `pm_Scheduler`. Slot stays free for any other DNS-backend extension
- v0.5.1 — 1-minute poll cadence (was 5 min default)
- v0.5.2 — stopped calling `server_dns --disable-custom-backend`
  defensively in post-install / pre-uninstall (it was evicting
  whoever else held the slot, including slave-dns-manager)
- v0.5.3 — Plesk top-bar search hook + per-row Resync button lockout
- v0.5.4 — fixed broken search hook (named class for Plesk Lucene)
- v0.5.5 — dropped a useless Navigation hook
- v0.5.6 — cleanup: removed dead custom-backend code (~600 lines)

## Now

### v0.6 — close the slave-replication origin leak 🛡

When a domain is activated for sync and the Cloudflare proxy is on,
Plesk's local BIND is still authoritative for the zone — and if
slave-dns-manager (or any other secondary DNS extension) replicates
it to publicly-known secondaries, an attacker can query those
secondaries directly and read the unproxied origin record, defeating
the Cloudflare proxy entirely.

- [ ] On activation: `plesk bin dns --off <domain>` so local BIND
      stops serving the zone. Records stay in Plesk's DB (our SDK
      reader keeps working); only the public-BIND path goes away.
      Cloudflare becomes the sole authoritative source.
- [ ] On deactivation: `plesk bin dns --on <domain>` to restore.
- [ ] One-time migration sweep on upgrade: turn off local DNS for
      every already-activated domain.
- [ ] Activation modal: add an explicit "Cloudflare-only DNS"
      acknowledgement checklist item.
- [ ] Document the behaviour change in README + CHANGES.md.

### v0.7 — Cloudflare proxy-status audit view

Operator workflow: "tell me which Cloudflare zones have proxied
records so I can decide which domains to activate sync for here."
Today this requires opening each zone in Cloudflare's dashboard.

- [ ] New controller action `proxyStatusAction` that queries
      Cloudflare for every zone the account holds, reads each zone's
      records, and tallies "has at least one proxied record" per zone.
- [ ] Renders a table — Zone · Proxied records (count) · Already
      activated in this extension (yes/no) · Quick-activate button.
- [ ] Surface as a separate page or modal from the main settings UI.
- [ ] Read-only — does not flip the proxy flag itself (still operator's
      job in Cloudflare).

## Next

### DNSSEC awareness — Phase 2

Read each domain's DNSSEC state from Plesk's `dnssec` extension
(via its `pm_Settings`/DB or its PHP API) and surface it as a
per-row badge. Convert the generic activation-modal checklist into
a *targeted* warning that fires only when the row is actually signed.
Built as a **soft dependency** on `dnssec` (not declared in `meta.xml`):
runtime probe, query when available, fall back to Phase 1's generic
checklist when it isn't. Plesk's DNSSEC module is paid on Web Admin
licences, so a hard dependency would exclude valid installs.

### Operational documentation

Install (fresh) · upgrade (in-place) · configure · activate a domain ·
force a one-off resync · uninstall · log location · common errors.
Either expand the README or split out an `OPERATIONS.md`.

### Suppress Plesk's "domain does not resolve" warning

When Cloudflare's proxy is on, Plesk's built-in DNS check finds the
Cloudflare edge IP instead of the local server's, and raises a
false-positive notification per domain. Investigate Plesk's
notification suppression APIs (per-domain ideal, server-wide as
fallback). No clean documented path yet — research first.

## Later

- [ ] **Gate activation on Plesk-DNS-enabled state.** When DNS is off
      in Plesk for a domain, the row should disable the toggle and
      explain why. Without this, a customer using Cloudflare standalone
      could have Plesk's default records pushed into their Cloudflare
      zone the moment Plesk DNS is re-enabled. The marker system
      prevents *deletion* of foreign records but not this additive
      pollution.
- [ ] **Absorb slave-DNS functionality.** Per-domain "Sync target"
      toggle: Cloudflare / local secondaries (ns1, ns2) / both. Where
      "local secondaries" is picked, write the BIND notify directives
      ourselves (currently slave-dns-manager's job). Pre-req: v0.6
      origin-leak mitigation is in. Low priority until users actually
      request the consolidation.
- [ ] **Soft sync mode (observe-only) — speculative.** A per-domain
      "syncing but don't push" mode useful during migrations where
      records are being handed from one DNS provider to another and
      the operator wants to validate the diff before letting it apply.
      Low confidence this is needed — kept so it's not forgotten.
- [ ] Surface each domain's Cloudflare-assigned nameservers in the UI
      so the registrar-NS update step is right next to the toggle.
- [ ] Optional "delete the Cloudflare zone when the domain is removed
      in Plesk" (today the zone is deliberately left intact).
- [ ] Domain aliases and standalone subdomain zones.
- [ ] Localisation (currently English only).
- [ ] Plesk-side proxy management — **deliberately deferred**; proxy
      stays Cloudflare-owned, which is what keeps the sync non-
      destructive.
- [ ] Submit to the Plesk Extensions Catalog (requires the v0.6
      origin-leak fix, screenshots, DESCRIPTION.md, CHANGES.md, and
      a decision on the bidirectional-sync catalog cert requirement —
      we are one-way by design).

## v1.0 — multi-tenant / customer self-service

The extension is currently admin-only. v1.0 opens it up so hosting
providers can sell Cloudflare DNS Sync as a per-customer offering —
provisioned and billed through WHMCS, used directly by the domain
owner.

- [ ] **Per-domain entitlement state.** A new layer alongside the
      existing `enabled_domains` activation list: `entitled_<domain>`
      decides whether the domain owner sees the sync toggle at all.
      Activation gates *syncing*; entitlement gates *visibility*.
- [ ] **Customer-area UI.** A scoped per-customer view of the
      extension — same toggle UX as the admin page, but listing only
      the customer's own domains and only those they're entitled to.
- [ ] **Token-authenticated HTTPS API.** A new controller endpoint
      (`POST /api/entitlement`) accepting a Bearer token and a
      payload of `{ domain, entitled, autoActivate }` so any external
      system can grant or revoke entitlement for a specific domain.
- [ ] **WHMCS provisioning module.** A separate package living in
      WHMCS's `modules/addons/` (or `servers/`), with admin config
      for the extension's API token and Plesk URL. Order-complete
      hook grants entitlement (and optionally auto-activates);
      suspend hook revokes visibility; cancel hook revokes
      entitlement entirely. The Cloudflare zone is left intact on
      revocation — non-destructive throughout.

## Beyond Plesk

- [ ] DirectAdmin adapter, reusing the panel-agnostic `Cloudflare\` core.

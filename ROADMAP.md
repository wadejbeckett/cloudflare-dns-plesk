# Roadmap

High-level direction for the project. Granular work items live in
[GitHub Issues](https://github.com/wadejbeckett/cloudflare-dns-plesk/issues),
grouped into version **Milestones**.

## v0.1.0 — first working release ✅

One-way, non-destructive Plesk → Cloudflare DNS sync.

- [x] Cloudflare API client + diff engine (ownership-aware, `PATCH`-based)
- [x] Plesk custom DNS backend handler + install/uninstall scripts
- [x] Auto-create the Cloudflare zone when a domain is added in Plesk
- [x] Settings page — API token, account ID, instant per-domain on/off
      toggles with sync-on-activate and live status
- [x] Comment-marker ownership (`[plesk-dns-sync]`)
- [x] Proxy-state preservation — verified end to end on a real domain
- [x] Per-domain activation — sync is opt-in per domain, with an optional
      auto-enable for newly added domains
- [x] Full manual test pass — all 23 scenarios in [TEST-PLAN.md](TEST-PLAN.md)
- [x] Install / usage documentation in the README
- [x] Tag the release

## v0.2.0 — broader record-type support ✅

- [x] Add `SRV` and `CAA` record support (structured `data` object)

## v0.3.0 — DANE / TLSA support ✅

- [x] Add `TLSA` record support — surfaced by the `neo` pilot
      (6 TLSA records on `wadejbeckett.com` for mail DANE)

## v0.4.0 — operational maturity 🔨

- [x] **GitHub Actions: CI + release publishing.** PHPUnit on every push and
      pull request across PHP 8.0/8.3/8.4/8.5. On a `vX.Y.Z` tag, build the
      install zip and attach it to a GitHub Release — making installs a
      `curl … | plesk bin extension --install` one-liner.
- [x] **DNSSEC awareness — Phase 1** (UI guardrail). Activation now opens a
      confirmation modal whose checklist forces the operator to acknowledge
      DNSSEC state at the registrar **and** the nameserver-update step
      before a domain can be activated. A Plesk-signed domain whose NS
      moves to Cloudflare without removing the old registrar DS first
      breaks DNS resolution for validating resolvers — this gate stops
      that being a silent footgun.
- [ ] **DNSSEC awareness — Phase 2** (programmatic detection). Read each
      domain's DNSSEC state from Plesk's `dnssec` extension (its
      `pm_Settings`/DB, or its PHP API) and surface it as a per-row badge.
      Convert the generic checklist into a *targeted* warning that fires
      only when the row is actually signed. Plesk does not include
      DNSKEY/RRSIG/NSEC in the custom-backend payload, so this detection
      can't come from the records data path.

      Built as a **soft dependency** on the `dnssec` extension (not
      declared in `meta.xml`) — runtime probe, query when available, fall
      back to Phase 1's generic checklist when it isn't. Plesk's DNSSEC
      module is paid on "Web Admin" licences, so a hard dependency would
      exclude valid installs.
- [x] **Activation UX — background the sync** (v0.4.1). The AJAX no longer
      blocks on `plesk bin dns --sync-all-zones`; it fires in the background
      and returns immediately. The toggle stays disabled until polling either
      lands a final status or times out (2 min). Final-state messages on the
      cell are now explicit ("Still syncing — refresh the page to update").
- [ ] **Operational docs.** Install (fresh) · update (in-place via the
      auto-built release zip) · configure · activate a domain · sync one
      domain (`--add`/`--del` trick) · sync all (`--sync-all-zones`,
      noting it walks every zone) · uninstall · log location · common
      errors. Either expand the README or split out an `OPERATIONS.md`.

## Later

- [ ] Re-add a "Resync all domains" action — force a re-push for every
      activated domain — on its own page.
- [ ] Surface each domain's Cloudflare-assigned nameservers in the UI so
      the registrar-NS update step is right next to the toggle.
- [ ] File remaining work as GitHub Issues / Milestones for proper tracking.
- [ ] Optional "delete the Cloudflare zone when the domain is removed in
      Plesk" (today the zone is deliberately left intact)
- [ ] Domain aliases and standalone subdomain zones
- [ ] **Gate activation on Plesk-DNS-enabled state.** When DNS is off in
      Plesk for a domain, the row should disable the toggle and explain
      why. Without this, a customer using Cloudflare standalone could
      have Plesk's default records (A, MX etc.) pushed into their
      Cloudflare zone the moment Plesk DNS is re-enabled — alongside
      their existing foreign records, polluting the zone. The marker
      system prevents *deletion* of foreign records but not this
      additive pollution.
- [ ] **Soft sync mode (observe-only) — speculative.** A per-domain
      "syncing but don't push" mode useful during migrations where
      records are being handed from Cloudflare to Plesk (or vice versa)
      and the operator wants to validate the diff before letting it
      apply. Low confidence this is needed — left here so it's not
      forgotten if a real use case emerges.
- [ ] Localisation (currently English only)
- [ ] Plesk-side proxy management — **deliberately deferred**; proxy stays
      Cloudflare-owned, which is what keeps the sync non-destructive
- [ ] Submit to the Plesk Extensions Catalog

## v1.0 — multi-tenant / customer self-service

The extension is currently admin-only. v1.0 opens it up so hosting providers
can sell Cloudflare DNS Sync as a per-customer offering — provisioned and
billed through WHMCS, used directly by the domain owner.

- [ ] **Per-domain entitlement state.** A new layer alongside the existing
      `enabled_domains` activation list: `entitled_<domain>` decides whether
      the domain owner sees the sync toggle at all. Activation gates *syncing*;
      entitlement gates *visibility*.
- [ ] **Customer-area UI.** A scoped per-customer view of the extension —
      same toggle UX as the admin page, but listing only the customer's own
      domains and only those they're entitled to.
- [ ] **Token-authenticated HTTPS API.** A new controller endpoint
      (`POST /api/entitlement`) accepting a Bearer token and a payload of
      `{ domain, entitled, autoActivate }` so any external system can grant
      or revoke entitlement for a specific domain.
- [ ] **WHMCS provisioning module.** A separate package living in WHMCS's
      `modules/addons/` (or `servers/`), with admin config for the
      extension's API token and Plesk URL. Order-complete hook grants
      entitlement (and optionally auto-activates); suspend hook revokes
      visibility; cancel hook revokes entitlement entirely. The Cloudflare
      zone is left intact on revocation — non-destructive throughout.

## Beyond Plesk

- [ ] DirectAdmin adapter, reusing the panel-agnostic `Cloudflare\` core

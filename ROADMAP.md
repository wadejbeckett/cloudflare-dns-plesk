# Roadmap

High-level direction for the project. Granular work items are tracked as
[GitHub Issues](https://github.com/wadejbeckett/cloudflare-dns-plesk/issues),
grouped into version **Milestones**.

## v0.1.0 — first working release (in progress)

One-way, non-destructive Plesk → Cloudflare DNS sync.

- [x] Cloudflare API client + diff engine (ownership-aware, `PATCH`-based)
- [x] Plesk custom DNS backend handler + install/uninstall scripts
- [x] Auto-create the Cloudflare zone when a domain is added in Plesk
- [x] Settings page (API token, account ID, per-domain activation)
- [x] Comment-marker ownership (`[plesk-dns-sync]`)
- [x] Proxy-state preservation — verified end to end on a real domain
- [x] Per-domain activation — sync is opt-in per domain, with an optional
      auto-enable for newly added domains
- [ ] Full manual test pass — see [TEST-PLAN.md](TEST-PLAN.md)
- [ ] Install / usage documentation in the README
- [ ] Tag the release

## v0.2.0 — usability

- [ ] Re-add the "Resync all domains" button on its own page (two
      `pm_Form_Simple` forms on one page collide over a hardcoded button id)
- [ ] Broader record-type support: `SRV`, `CAA` (today: A/AAAA/CNAME/MX/TXT)
- [ ] Surface each domain's Cloudflare-assigned nameservers in the UI

## Later

- [ ] Optional "delete the Cloudflare zone when the domain is removed in
      Plesk" (today the zone is deliberately left intact)
- [ ] Domain aliases and standalone subdomain zones
- [ ] Localisation (currently English only)
- [ ] Plesk-side proxy management — **deliberately deferred**; proxy stays
      Cloudflare-owned, which is what keeps the sync non-destructive
- [ ] Submit to the Plesk Extensions Catalog

## Beyond Plesk

- [ ] DirectAdmin adapter, reusing the panel-agnostic `Cloudflare\` core

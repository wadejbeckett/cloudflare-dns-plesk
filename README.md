# Cloudflare DNS Sync for Plesk

One-way, **non-destructive** DNS synchronisation from Plesk to Cloudflare.

Plesk is the master, Cloudflare is the slave: you manage DNS in Plesk as
normal, and every change to an activated domain flows to Cloudflare
automatically — without ever resetting the things Cloudflare owns.

## What makes it different

A Cloudflare record carries settings a control panel has no concept of — the
proxy (orange-cloud) toggle, per-record comments, tags, page-rule
associations. This extension treats every one of those as Cloudflare-owned and
never overwrites them:

- **Updates use HTTP `PATCH`** — a partial update. Only the fields Plesk owns
  (content, TTL) are written; the proxy flag and everything else are left
  exactly as they are.
- **The diff engine prefers *updating* a record** over delete-and-recreate — a
  recreated record would lose its proxy state.
- **Foreign records are never touched** — anything created directly in
  Cloudflare (or by another service) is left completely alone.
- **`SOA` and `NS` are skipped** — Cloudflare manages those itself.

The practical result: switch the orange cloud on for a record in Cloudflare,
and it stays on through every subsequent Plesk-side DNS change. This is the bug
([EXTPLESK-13681](https://docs.plesk.com/release-notes/obsidian/change-log/)) in the
official Cloudflare extension that this project exists to avoid.

## Requirements

- Plesk Obsidian **18.0.55** or newer
- A Cloudflare account
- PHP 8.1+ with `ext-curl` and `ext-json` (bundled with Plesk)

## Compatibility

This is a **Plesk** extension and installs only on Plesk. The Cloudflare
client and diff engine live in the panel-agnostic `core/` package
(`core/library/Cloudflare/`) and are shared across panel adapters, so a
**DirectAdmin** adapter can reuse that core unchanged — see
[Repository layout](#repository-layout) below.

## Installation

The extension is not in the Plesk Extensions Catalog — install the package
directly.

### Recommended: install the pre-built zip

Every tagged release publishes a ready-to-install zip on the
[GitHub Releases page](https://github.com/wadejbeckett/cloudflare-dns-sync/releases/latest).
On the Plesk server:

```sh
wget https://github.com/wadejbeckett/cloudflare-dns-sync/releases/latest/download/cloudflare-dns-sync.zip
plesk bin extension --install cloudflare-dns-sync.zip
```

Or in Plesk: **Extensions → My Extensions → Upload Extension**, choose the zip.

### Build from source

1. **Build the package** from a checkout of this repository. The build script
   bundles the shared `core/` into the Plesk panel and writes the zip to
   `dist/`:

   ```sh
   build/build-panel.sh plesk <version>     # e.g. build/build-panel.sh plesk 0.5.14
   ```

2. **Install it** — in Plesk: **Extensions → My Extensions → Upload Extension**,
   choose the zip. Or from the command line:

   ```sh
   plesk bin extension --install dist/cloudflare-dns-sync-<version>.zip
   ```

> **Coexists with `slave-dns-manager` and other DNS extensions.** Since
> v0.5.0 this extension does NOT claim Plesk's exclusive custom-DNS-backend
> slot — it polls Plesk's DNS state on a schedule instead. Plesk's
> `slave-dns-manager` (or any other DNS-backend extension) is free to use
> the slot. The earlier "one DNS backend at a time" warning no longer
> applies. The exception is the official **"DNS Integration for Cloudflare"**
> extension — it covers the same job from the other direction and
> shouldn't be run alongside this one.

## Setup

### 1. Create a Cloudflare API token

Use a scoped **API token** — *not* the Global API Key.

In Cloudflare: **My Profile → API Tokens → Create Token → Create Custom Token**.

- **Permissions:**
  - `Zone` · `DNS` · `Edit`
  - `Zone` · `Zone` · `Edit` — lets the extension create a Cloudflare zone the
    first time you activate a domain (`Edit` includes `Read`).
- **Zone Resources:** `Include` · `All zones from an account` · *your account*.
  Creating new zones cannot be limited to specific existing zones.

### 2. Find your Cloudflare account ID

In the Cloudflare dashboard, open your account — the **Account ID** is shown on
the account home page (and in the dashboard URL).

### 3. Configure the extension

Open **Extensions → Cloudflare DNS Sync**, paste the API token and account ID,
and **Save**. The page confirms with *"Connected to Cloudflare"*. Once a token
is saved the field locks; tick **Change the API token** to replace it.

## Usage

The **Domains to sync** section lists every main domain on the server, each
with an on/off switch:

- **Switch a domain on** — it is activated and pushed to Cloudflare
  immediately; the row shows the result (`Synced — N records`). If the zone
  does not exist in Cloudflare yet, it is created.
- **Switch a domain off** — syncing stops. The Cloudflare zone is left intact.
- **Auto-enable new domains** — when on, a domain added in Plesk starts syncing
  automatically. Off by default, so nothing syncs until you choose it to.
- **Resync this domain** (per-row refresh icon) — forces an immediate
  reconciliation for one activated domain.
- **Resync all activated domains** (button below the table) — same, for
  every activated row in parallel.

After a domain is activated, every DNS change you make in Plesk is picked up
on the next poll cycle (default every 1 minute) and pushed to Cloudflare.
If you need a change to land instantly, click the per-row Resync icon.

## Good to know

- **New records are created grey** (DNS-only). The orange cloud is yours to
  manage in Cloudflare — the extension never turns it on or off.
- **Record types synced:** `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `CAA`,
  `TLSA`. `SOA`/`NS` are left to Cloudflare. Other types (e.g. `DS`, `HTTPS`)
  are skipped, and each skip is logged.
- **Removing a domain in Plesk** leaves its Cloudflare zone in place — nothing
  is deleted.
- **Nameserver delegation** at your registrar is still your job. The extension
  syncs records into Cloudflare; it does not point your domain's nameservers
  there.
- Each sync is logged to `<extension-var-dir>/sync.log` on the server.

## How it works

The extension runs a scheduled task — registered via Plesk's `pm_Scheduler`
— that fires every minute. For each activated domain, the task reads
Plesk's current DNS state via the SDK (`pm_Dns_Zone::getRecords()`), diffs
it against the live Cloudflare zone, and applies the minimum set of
`PATCH` / `POST` / `DELETE` calls.

Ownership is tracked by stamping a panel-neutral marker (`[noiz-dns-sync]`)
into each managed record's Cloudflare comment — so the extension only ever
touches records it created, and the marker survives a reinstall. Records
stamped by older versions (`[plesk-dns-sync]`) are still recognised, so no
migration is needed.

Earlier versions (v0.4.x) used Plesk's *custom DNS backend* slot for an
event-driven sync. That mechanism is single-slot and exclusive — claiming
it evicts other DNS-backend extensions (e.g. `slave-dns-manager`) and
breaks their replication. v0.5.0 switched to polling so the slot stays
free for other extensions. Trade-off: sync latency is one poll cycle
(currently 1 minute) instead of sub-second. The per-row and bulk Resync
buttons trigger an immediate sync when you need it now.

## Repository layout

This repository is a monorepo: a panel-agnostic core shared by per-panel
adapters.

```
core/library/Cloudflare/       Panel-agnostic Cloudflare client + diff engine
core/tests/                    PHPUnit tests for the core (no Plesk runtime)
core/composer.json             Shared core package (noiz/cloudflare-dns-sync-core)
panels/plesk/                  Plesk extension — adapter over the core
  meta.xml                     Plesk extension manifest
  _meta/icons/                 Extension icons (32/64/128 PNG)
  htdocs/                      Web entry point
  plib/controllers/            Settings-page controller
  plib/views/                  Settings-page view
  plib/hooks/                  Plesk integration hooks (top-bar search)
  plib/scripts/                Lifecycle hooks + the sync-poll script
  plib/library/PleskDns/       Plesk SDK reader + record-field mapper
  tests/                       Plesk-adapter unit tests
  composer.json                Plesk package (path-depends on the core)
build/build-panel.sh           Assembles a panel install zip (core + panel)
```

The `core/` package has **no Composer runtime dependencies** and no Plesk
coupling — a DirectAdmin adapter (`panels/directadmin/`) can reuse it as-is.

## Development

Each package has its own suite; run it from the package directory:

```sh
cd core         && composer install && composer test     # panel-agnostic core
cd panels/plesk && composer install && composer test     # Plesk adapter
```

The tests use a fake HTTP transport, so no Cloudflare account or network
access is required.

## Roadmap

See [ROADMAP.md](ROADMAP.md). Manual end-to-end coverage is tracked in
[TEST-PLAN.md](TEST-PLAN.md).

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

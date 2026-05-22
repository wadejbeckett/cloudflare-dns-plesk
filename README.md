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
([EXTPLESK-13681](https://github.com/wadejbeckett/cloudflare-dns-plesk)) in the
official Cloudflare extension that this project exists to avoid.

## Requirements

- Plesk Obsidian **18.0.55** or newer
- A Cloudflare account
- PHP 8.0+ with `ext-curl` and `ext-json` (bundled with Plesk)

## Compatibility

This is a **Plesk** extension and installs only on Plesk. The Cloudflare
client and diff engine in `plib/library/Cloudflare/` are deliberately
panel-agnostic, so a **DirectAdmin** adapter is planned that would reuse that
core unchanged — but it is separate work and not part of this package.

## Installation

The extension is not in the Plesk Extensions Catalog — install the package
directly.

1. **Build the package** from a checkout of this repository:

   ```sh
   zip -r cloudflare-dns-sync.zip meta.xml plib htdocs
   ```

2. **Install it** — in Plesk: **Extensions → My Extensions → Upload Extension**,
   choose the zip. Or from the command line:

   ```sh
   plesk bin extension --install /path/to/cloudflare-dns-sync.zip
   ```

> **One DNS backend at a time.** The extension registers itself as Plesk's
> custom DNS backend, and Plesk has a single slot for that. Do not run it
> alongside the official "DNS Integration for Cloudflare" extension.

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

The **Domains to sync** section lists every domain on the server, each with an
on/off switch:

- **Switch a domain on** — it is activated and pushed to Cloudflare
  immediately; the row shows the result (`Synced — N records`). If the zone
  does not exist in Cloudflare yet, it is created.
- **Switch a domain off** — syncing stops. The Cloudflare zone is left intact.
- **Auto-enable new domains** — when on, a domain added in Plesk starts syncing
  automatically. Off by default, so nothing syncs until you choose it to.

After a domain is activated, every DNS change you make in Plesk is pushed to
Cloudflare automatically.

## Good to know

- **New records are created grey** (DNS-only). The orange cloud is yours to
  manage in Cloudflare — the extension never turns it on or off.
- **Record types synced:** `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `CAA`.
  `SOA`/`NS` are left to Cloudflare. Any other type (e.g. `TLSA`, `DS`) is
  skipped, and each skip is logged.
- **Removing a domain in Plesk** leaves its Cloudflare zone in place — nothing
  is deleted.
- **Nameserver delegation** at your registrar is still your job. The extension
  syncs records into Cloudflare; it does not point your domain's nameservers
  there.
- Each sync is logged to `<extension-var-dir>/sync.log` on the server.

## How it works

Plesk routes every DNS zone change through a registered *custom DNS backend*.
This extension's backend translates the change, diffs the desired zone against
the live Cloudflare zone, and applies the minimum set of `PATCH` / `POST` /
`DELETE` calls. Ownership is tracked by stamping a marker (`[plesk-dns-sync]`)
into each managed record's Cloudflare comment — so the extension only ever
touches records it created, and the marker survives a reinstall.

## Repository layout

```
meta.xml                       Plesk extension manifest
htdocs/                        Web entry point
plib/controllers/              Settings-page controller
plib/views/                    Settings-page view
plib/scripts/                  DNS backend handler + install/uninstall hooks
plib/library/Cloudflare/       Panel-agnostic Cloudflare client + diff engine
plib/library/PleskDns/         Plesk payload translator
tests/                         PHPUnit unit tests (no network required)
```

The `Cloudflare\` core has **no Composer runtime dependencies** and no Plesk
coupling — a DirectAdmin adapter can reuse it as-is.

## Development

```sh
composer install
composer test
```

The tests use a fake HTTP transport, so no Cloudflare account or network
access is required.

## Roadmap

See [ROADMAP.md](ROADMAP.md). Manual end-to-end coverage is tracked in
[TEST-PLAN.md](TEST-PLAN.md).

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

# cloudflare-dns-plesk

A one-way Cloudflare DNS sync extension for Plesk.

It mirrors the DNS zones of your Plesk subscriptions into Cloudflare: Plesk is
the master, Cloudflare is the slave. You manage DNS in Plesk as normal, and the
records flow to Cloudflare automatically.

## What makes it different

The sync is **non-destructive**.

A Cloudflare record carries settings a control panel has no concept of — the
proxy (orange-cloud) toggle, per-record comments, tags, page-rule
associations. This extension treats every one of those as Cloudflare-owned and
never overwrites them:

- **Updates use HTTP `PATCH`** — a partial update. Only the fields Plesk
  actually owns (content, TTL) are written; the proxy flag and everything else
  are left exactly as they are.
- **The sync engine diffs** the panel against the live Cloudflare zone and
  prefers *updating* a record over delete-and-recreate — a recreated record
  would lose its proxy state.
- **Cloudflare-native records can be left alone** — records created directly in
  Cloudflare do not have to be pruned.
- **`SOA` and `NS` records are skipped** — Cloudflare manages those itself.

The practical result: switch the orange cloud on for a record in Cloudflare,
and it stays on through every subsequent Plesk-side DNS change.

## Status

Early development.

- ✅ **Cloudflare sync core** — API client + diff engine — complete, with a
  PHPUnit test suite.
- 🔜 **Plesk extension wrapper** — `meta.xml`, the custom DNS backend handler,
  the settings UI, install scripts.
- 🔜 A DirectAdmin adapter, reusing the same core.

## Repository layout

```
src/Cloudflare/      Panel-agnostic Cloudflare API client + sync engine — the
                     reusable core (a DirectAdmin adapter can build on it)
tests/               PHPUnit unit tests (no network required)
```

## The core

| Class | Responsibility |
|---|---|
| `Cloudflare\Client` | HTTP transport, Bearer auth, JSON, retries, pagination |
| `Cloudflare\Zones` | Look up / create Cloudflare zones |
| `Cloudflare\DnsRecords` | List / create / `PATCH` / delete records; apply a plan |
| `Cloudflare\Record` | Immutable DNS record value object |
| `Cloudflare\ZoneSync` | Pure diff engine → produces a `SyncPlan` |
| `Cloudflare\SyncPlan` / `SyncReport` | The planned changes / the outcome |

The runtime has **no Composer dependencies** — just PHP 8.0+ with `ext-curl`
and `ext-json`.

## Running the tests

```sh
composer install
composer test
```

The tests use a fake HTTP transport, so no Cloudflare account or network access
is required.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

# cloudflare-dns-plesk

Non-destructive Cloudflare DNS synchronisation for the Plesk control panel.

## Why this exists

Plesk's official **DNS Integration for Cloudflare** extension (`cloudflaredns`)
resets the Cloudflare **proxy state** (the orange cloud) of DNS records whenever
a zone is synchronised — confirmed by Plesk as bug **EXTPLESK-13681**. Every
zone change silently turns proxied records back into DNS-only records.

The cause is how DNS records are written to Cloudflare. Cloudflare's `PUT`
endpoint (and its zone-file import) is a **full overwrite**: any field you don't
send is reset to its default, and the default for `proxied` is `false`.

## The approach

The control panel is the **master**; Cloudflare is the **slave**. Sync is
one-way (panel → Cloudflare) and **non-destructive**:

- Updates use HTTP **`PATCH`**, a partial update. Only the fields that actually
  changed are sent. `proxied`, `comment`, `tags` and page-rule associations are
  **never sent**, so Cloudflare keeps them exactly as they are.
- The sync engine **diffs** desired records against live Cloudflare records and
  prefers **updating** an existing record over delete-and-recreate — because a
  recreated record loses its proxy state.
- Records created directly in Cloudflare can be **left untouched** (see
  `managedIds` / `prune` options) so the tool never clobbers manual changes.
- `SOA` and `NS` records are skipped — Cloudflare manages those itself.

## Repository layout

```
src/Cloudflare/      Panel-agnostic Cloudflare API client + sync engine (this is
                     the reusable core; a DirectAdmin adapter can reuse it later)
tests/               PHPUnit unit tests (no network required)
```

The Plesk extension wrapper (`plib/`, the custom DNS backend handler, the
settings UI) is added once a dev Plesk box is available; the core below is
fully testable on its own.

## The core

| Class | Responsibility |
|---|---|
| `Cloudflare\Client` | HTTP transport, Bearer auth, JSON, retries, pagination |
| `Cloudflare\Zones` | Look up / create Cloudflare zones |
| `Cloudflare\DnsRecords` | List / create / `PATCH` / delete records; apply a plan |
| `Cloudflare\Record` | Immutable DNS record value object |
| `Cloudflare\ZoneSync` | Pure diff engine → produces a `SyncPlan` |
| `Cloudflare\SyncPlan` / `SyncReport` | The planned changes / the outcome |

## Running the tests

```sh
composer install
composer test
```

The tests use a fake HTTP transport, so no Cloudflare account or network access
is needed. `tests/DnsRecordsApplyTest.php` asserts the core guarantee: updates
go out as `PATCH` and the request body never contains `proxied`.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

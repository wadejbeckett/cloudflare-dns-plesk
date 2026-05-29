<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

/**
 * Predicate: is this Plesk main domain a sensible candidate for Cloudflare sync?
 *
 * Two cases are filtered out, both observed live on `neo.noiz.co.za` 2026-05-29:
 *
 *   1. The server's own hostname. `pm_Domain::getAllDomains(true)` returns every
 *      main domain — including the one Plesk itself answers on. Activating sync
 *      for that domain would push the panel's A record up to Cloudflare with our
 *      `[plesk-dns-sync]` ownership marker. If Cloudflare is authoritative for
 *      the parent zone, panel reachability gets tangled. Compare against
 *      `gethostname()` (returns the FQDN on a Plesk box).
 *
 *   2. Slave / secondary zones. Plesk can host a zone in "slave" mode, mirroring
 *      an external primary nameserver. Such a zone has no Plesk-authored content
 *      of its own — syncing it to Cloudflare as authoritative would push empty
 *      or stale records. Detect via `pm_Dns_Zone::getType()` when available, or
 *      a `SELECT type FROM dns_zone` query as a fallback.
 *
 * The hostname check is pure PHP and unit-testable (see `tests/SyncableDomainTest.php`).
 * The zone-type check requires the Plesk runtime, so it is intentionally split
 * into its own method and only exercised by manual / integration testing.
 *
 * Used by both `IndexController::listDomainNames` (settings page table) and
 * `scripts/sync-poll.php` (auto-enable cron) so the filter applies uniformly.
 */
final class SyncableDomain
{
    /** True when $name is a candidate for Cloudflare sync. */
    public static function isSyncable(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        if ($name === gethostname()) {
            return false;
        }
        return self::isMasterZone($name);
    }

    /**
     * True when Plesk's DNS zone for $name is a master/primary zone (the only
     * type we can safely sync). Returns true defensively when neither lookup
     * path can answer — better to surface a real working domain than silently
     * hide it from the operator.
     */
    private static function isMasterZone(string $name): bool
    {
        try {
            if (class_exists('\pm_Dns_Zone') && method_exists('\pm_Dns_Zone', 'get')) {
                $zone = \pm_Dns_Zone::get($name);
                if (is_object($zone) && method_exists($zone, 'getType')) {
                    $type = strtolower(trim((string) $zone->getType()));
                    return $type === '' || $type === 'master';
                }
            }
        } catch (\Throwable $e) {
            // SDK path failed — fall through to the DB-fallback below.
        }

        try {
            if (class_exists('\pm_Bootstrap') && method_exists('\pm_Bootstrap', 'getDbAdapter')) {
                $row = \pm_Bootstrap::getDbAdapter()->fetchRow(
                    'SELECT type FROM dns_zone WHERE name = ?',
                    [$name]
                );
                if (is_array($row) && isset($row['type'])) {
                    return strtolower((string) $row['type']) === 'master';
                }
            }
        } catch (\Throwable $e) {
            error_log("cfdns: SyncableDomain master-zone lookup failed for '$name': " . $e->getMessage());
        }

        // Could not determine — default to INCLUSIVE so a misconfigured Plesk
        // does not silently hide working setups. The operator will still see
        // the row in the settings page and can choose to activate it.
        return true;
    }
}

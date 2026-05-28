<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

use Noiz\CloudflareDns\Cloudflare\Record;

/**
 * Reads a Plesk DNS zone via the Plesk PHP SDK and returns it as
 * Record[] for the diff engine. This is the data source for v0.5.0+
 * poll-mode sync — we read Plesk's current state on a schedule and
 * reconcile to Cloudflare, rather than registering for Plesk's
 * exclusive custom-DNS-backend slot.
 */
final class ZoneReader
{
    /**
     * Read the current Plesk-side zone for $domainName.
     *
     * @return Record[]
     *
     * @throws \RuntimeException when the domain does not exist or has no DNS zone.
     */
    public static function read(string $domainName): array
    {
        try {
            $domain = \pm_Domain::getByName($domainName);
        } catch (\pm_Exception $e) {
            throw new \RuntimeException("Domain '$domainName' not found in Plesk: " . $e->getMessage(), 0, $e);
        }

        try {
            $zone = $domain->getDnsZone();
        } catch (\pm_Exception $e) {
            throw new \RuntimeException("DNS zone for '$domainName' not available: " . $e->getMessage(), 0, $e);
        }

        $soaTtl = self::soaTtl($zone);

        $records = [];
        foreach ($zone->getRecords() as $pmRecord) {
            $type = strtoupper((string) $pmRecord->getType());
            $host = (string) $pmRecord->getHost();

            // NS/SOA are Cloudflare-managed; legacy trigger TXTs left over
            // from a v0.4.x deployment (where the matched `--del` may have
            // failed) are silently dropped so they don't pollute Cloudflare.
            // Plesk-specific record types beyond our supported list are
            // skipped — there's no useful way to sync them.
            if (str_starts_with($host, Payload::TRIGGER_HOST_PREFIX)) {
                continue;
            }
            if (in_array($type, ['SOA', 'NS'], true)) {
                continue;
            }
            if (!in_array($type, Payload::SUPPORTED_TYPES, true)) {
                continue;
            }

            $records[] = Payload::toRecord([
                'host' => $host,
                'type' => $type,
                'value' => (string) $pmRecord->getValue(),
                'opt' => (string) ($pmRecord->getOption() ?? ''),
                'ttl' => $pmRecord->getTtl(),
            ], $type, $soaTtl);
        }

        return $records;
    }

    /** Default TTL for records that don't carry one — the zone's SOA minimum. */
    private static function soaTtl(\pm_Dns_Zone $zone): int
    {
        try {
            return (int) $zone->getSoaRecord()->getMinimum();
        } catch (\Throwable $e) {
            return 3600;
        }
    }
}

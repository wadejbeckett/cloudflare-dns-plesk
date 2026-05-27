<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

use Noiz\CloudflareDns\Cloudflare\Record;

/**
 * Reads a Plesk DNS zone via the Plesk PHP SDK and returns it in the same
 * Record[] shape the custom-DNS-backend Payload parser produces. Used by
 * the poll-mode sync (v0.5.0) so this extension can reconcile against
 * Cloudflare WITHOUT registering as Plesk's exclusive custom-DNS-backend
 * — leaving the slot free for slave-dns-manager (or any other DNS
 * backend extension) to coexist.
 *
 * The trade-off vs the custom-backend slot: latency goes from
 * "milliseconds after the Plesk edit" to "next poll cycle" (typically
 * 60–120 s). Acceptable for an admin-driven DNS sync.
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
        $skipped = [];
        foreach ($zone->getRecords() as $pmRecord) {
            $type = strtoupper((string) $pmRecord->getType());
            $host = (string) $pmRecord->getHost();

            // Same gating Payload::parse applies to the JSON shape: NS/SOA
            // are Cloudflare-managed; the trigger TXT (left over from a v0.4.x
            // custom-backend deployment) is silently dropped.
            if (str_starts_with($host, Payload::TRIGGER_HOST_PREFIX)) {
                continue;
            }
            if (in_array($type, ['SOA', 'NS'], true)) {
                continue;
            }
            if (!in_array($type, Payload::SUPPORTED_TYPES, true)) {
                $skipped[] = trim($type . ' ' . $host);
                continue;
            }

            $rr = [
                // Plesk's JSON encodes hosts with a trailing dot; the SDK
                // typically does not. Payload::toRecord runs DnsName::normalise
                // which strips trailing dots either way, so we can pass through.
                'host' => $host,
                'type' => $type,
                'value' => (string) $pmRecord->getValue(),
                'opt' => (string) ($pmRecord->getOption() ?? ''),
                'ttl' => $pmRecord->getTtl(),
            ];
            $records[] = Payload::toRecord($rr, $type, $soaTtl);
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

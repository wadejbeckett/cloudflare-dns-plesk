<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

use Noiz\CloudflareDns\Cloudflare\DnsName;
use Noiz\CloudflareDns\Cloudflare\Record;

/**
 * Parses the JSON Plesk writes on stdin to a custom DNS backend.
 *
 * The payload is a JSON array of operation objects; each carries a `command`
 * and a `zone` (or a `ptr`, which Cloudflare cannot represent and is ignored).
 * An `update`/`create` `zone` always contains the WHOLE desired record set.
 */
final class Payload
{
    /** Record types translated into Cloudflare records. */
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    /** Types Cloudflare manages itself — silently dropped, no warning. */
    private const PROVIDER_MANAGED_TYPES = ['SOA', 'NS'];

    /**
     * @return array{operations: ZoneOperation[], skipped: string[]}
     *
     * @throws \RuntimeException when the payload is not a JSON array
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('The Plesk DNS payload is not a JSON array.');
        }

        $operations = [];
        $skipped = [];

        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['command'])) {
                continue;
            }

            $command = (string) $entry['command'];

            // Cloudflare has no reverse DNS — ignore PTR operations entirely.
            if ($command === 'createPTRs' || $command === 'deletePTRs') {
                continue;
            }

            $zone = $entry['zone'] ?? null;
            if (!is_array($zone)) {
                continue;
            }

            $zoneName = DnsName::normalise((string) ($zone['name'] ?? ''));
            if ($zoneName === '') {
                continue;
            }

            $records = [];
            $defaultTtl = (int) ($zone['soa']['ttl'] ?? 1);

            foreach (($zone['rr'] ?? []) as $rr) {
                if (!is_array($rr)) {
                    continue;
                }

                $type = strtoupper(trim((string) ($rr['type'] ?? '')));

                if (in_array($type, self::PROVIDER_MANAGED_TYPES, true)) {
                    continue; // Cloudflare owns SOA / NS
                }
                if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                    $skipped[] = trim($type . ' ' . DnsName::normalise((string) ($rr['host'] ?? '')));
                    continue;
                }

                $records[] = self::toRecord($rr, $type, $defaultTtl);
            }

            $operations[] = new ZoneOperation(
                $command === 'delete' ? ZoneOperation::DELETE : ZoneOperation::UPDATE,
                $zoneName,
                $records
            );
        }

        return ['operations' => $operations, 'skipped' => $skipped];
    }

    /**
     * @param array<string,mixed> $rr
     */
    private static function toRecord(array $rr, string $type, int $defaultTtl): Record
    {
        $host = DnsName::normalise((string) ($rr['host'] ?? ''));
        $value = trim((string) ($rr['value'] ?? ''));
        $opt = trim((string) ($rr['opt'] ?? ''));
        $ttl = (int) ($rr['ttl'] ?? $defaultTtl);

        // Hostname-valued records: drop the trailing dot Plesk includes.
        if ($type === 'CNAME' || $type === 'MX') {
            $value = rtrim($value, '.');
        }

        // For MX records Plesk delivers the priority in `opt`.
        $priority = ($type === 'MX' && $opt !== '') ? (int) $opt : null;

        return new Record($type, $host, $value, $ttl, $priority);
    }
}

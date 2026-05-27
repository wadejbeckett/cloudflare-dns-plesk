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
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'TLSA'];

    /** Types Cloudflare manages itself — silently dropped, no warning. */
    private const PROVIDER_MANAGED_TYPES = ['SOA', 'NS'];

    /**
     * Host-name prefix used by the extension's own activation trigger — a
     * marker TXT briefly added then removed on toggle-ON to fire the backend
     * for just one zone. Records under this prefix are silently dropped so
     * they never reach Cloudflare.
     */
    public const TRIGGER_HOST_PREFIX = '_cfdns-trigger.';

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
                $host = DnsName::normalise((string) ($rr['host'] ?? ''));

                if (str_starts_with($host, self::TRIGGER_HOST_PREFIX)) {
                    continue; // our own activation trigger — never sync
                }
                if (in_array($type, self::PROVIDER_MANAGED_TYPES, true)) {
                    continue; // Cloudflare owns SOA / NS
                }
                if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                    $skipped[] = trim($type . ' ' . $host);
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
     * Build a Record from the same shape Plesk's custom-DNS-backend JSON
     * delivers ($rr is the inner-array form). Reusable by the v0.5.0 poll
     * path which reads via the SDK and feeds the same fields here.
     *
     * @param array<string,mixed> $rr
     */
    public static function toRecord(array $rr, string $type, int $defaultTtl): Record
    {
        $host = DnsName::normalise((string) ($rr['host'] ?? ''));
        $value = trim((string) ($rr['value'] ?? ''));
        $opt = trim((string) ($rr['opt'] ?? ''));
        $ttl = (int) ($rr['ttl'] ?? $defaultTtl);

        if ($type === 'SRV') {
            // Plesk delivers SRV as value = target, opt = "priority weight port".
            [$priority, $weight, $port] = array_pad(
                array_map('intval', preg_split('/\s+/', $opt) ?: []),
                3,
                0
            );
            // An RFC 2782 target of "." ("service decidedly not available")
            // is meaningful and must survive normalisation, which would
            // otherwise strip the dot and leave an invalid empty target.
            $target = $value === '.' ? '.' : DnsName::normalise($value);

            return new Record($type, $host, "$weight $port $target", $ttl, null, null, null, null, [
                'priority' => $priority,
                'weight' => $weight,
                'port' => $port,
                'target' => $target,
            ]);
        }

        if ($type === 'CAA') {
            // Plesk delivers CAA as value = the value, opt = "flags tag".
            $parts = preg_split('/\s+/', $opt) ?: [];
            $flags = (int) ($parts[0] ?? 0);
            $tag = strtolower((string) ($parts[1] ?? 'issue'));
            $caaValue = trim($value, " \t\"");

            return new Record($type, $host, sprintf('%d %s "%s"', $flags, $tag, $caaValue), $ttl, null, null, null, null, [
                'flags' => $flags,
                'tag' => $tag,
                'value' => $caaValue,
            ]);
        }

        if ($type === 'TLSA') {
            // Plesk delivers TLSA as value = certificate (hex), opt = "usage
            // selector matching-type". DANE wants the cert as a hex string —
            // lower-case it so a re-uploaded cert that re-cases the hex
            // doesn't look like a change.
            [$usage, $selector, $matchingType] = array_pad(
                array_map('intval', preg_split('/\s+/', $opt) ?: []),
                3,
                0
            );
            $certificate = strtolower(trim($value));

            return new Record(
                $type,
                $host,
                sprintf('%d %d %d %s', $usage, $selector, $matchingType, $certificate),
                $ttl,
                null,
                null,
                null,
                null,
                [
                    'usage' => $usage,
                    'selector' => $selector,
                    'matching_type' => $matchingType,
                    'certificate' => $certificate,
                ]
            );
        }

        // Hostname-valued records: drop the trailing dot Plesk includes — but
        // keep a bare "." intact: a null MX (RFC 7505, "MX 0 .") uses it to
        // declare that the domain accepts no mail.
        if (($type === 'CNAME' || $type === 'MX') && $value !== '.') {
            $value = rtrim($value, '.');
        }

        // Cloudflare's UI flags TXT content not wrapped in double quotes
        // even though the record still resolves correctly. Plesk delivers
        // TXT values unquoted; normalise to BIND-style "..." here so the
        // dashboard never raises the warning. Long values (>255 bytes,
        // e.g. DKIM) are split at 255-byte boundaries — each chunk wrapped
        // individually, space-joined — which is the standard multi-string
        // representation. We do not yet handle embedded literal `"` in
        // values; nothing we sync today (SPF/DKIM/DMARC/ACME tokens/CAA)
        // contains one.
        if ($type === 'TXT') {
            $unquoted = trim($value, '"');
            if (strlen($unquoted) <= 255) {
                $value = '"' . $unquoted . '"';
            } else {
                $value = implode(' ', array_map(
                    static fn (string $chunk): string => '"' . $chunk . '"',
                    str_split($unquoted, 255)
                ));
            }
        }

        // For MX records Plesk delivers the priority in `opt`.
        $priority = ($type === 'MX' && $opt !== '') ? (int) $opt : null;

        return new Record($type, $host, $value, $ttl, $priority);
    }
}

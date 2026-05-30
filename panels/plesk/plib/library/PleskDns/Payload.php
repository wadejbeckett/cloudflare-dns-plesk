<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

use Noiz\CloudflareDns\Cloudflare\DnsName;
use Noiz\CloudflareDns\Cloudflare\Record;

/**
 * Maps Plesk DNS-record fields (host / type / value / opt / ttl) into
 * the panel-agnostic {@see Record} value object.
 *
 * Originally this class also parsed Plesk's custom-DNS-backend JSON
 * payload (the JSON Plesk wrote to stdin when this extension held the
 * single custom-backend slot). v0.5.0 dropped that approach in favour
 * of poll-mode — {@see ZoneReader} now reads Plesk's DNS state via the
 * SDK and feeds the per-record fields into {@see toRecord} here. The
 * JSON-parsing code path is gone; only the type-specific value handling
 * remains.
 */
final class Payload
{
    /** Record types this extension translates into Cloudflare records. */
    public const SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'TLSA'];

    /**
     * Host-name prefix used by v0.4.x for an on-demand single-zone backend
     * trigger (a marker TXT briefly added then removed on toggle-ON).
     * v0.5.x no longer uses this mechanism, but installs upgraded from
     * v0.4.x may still have leftover `_cfdns-trigger.*` records in their
     * Plesk zones if a previous `--del` ever failed; {@see ZoneReader}
     * filters them out so they don't pollute Cloudflare.
     */
    public const TRIGGER_HOST_PREFIX = '_cfdns-trigger.';

    /**
     * Build a {@see Record} from the fields one Plesk DNS record exposes.
     *
     * @param array<string,mixed> $rr Keys: host, type, value, opt, ttl.
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

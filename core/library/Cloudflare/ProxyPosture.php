<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Reports whether a single DNS name is fully proxied (Cloudflare orange-cloud)
 * across its web-facing record types. Pure and panel-agnostic — it drives the
 * read-only "is this domain proxied?" badge in a panel's settings UI.
 */
final class ProxyPosture
{
    /** Only these record types can carry Cloudflare's proxy (orange-cloud). */
    private const PROXIABLE = ['A', 'AAAA', 'CNAME'];

    public const PROXIED   = 'proxied';
    public const UNPROXIED = 'unproxied';
    public const MISSING   = 'missing';

    /**
     * Proxy posture of $name across all its proxiable records:
     *   - PROXIED   when at least one proxiable record exists and EVERY one is proxied
     *   - UNPROXIED when proxiable records exist but at least one is grey-cloud
     *   - MISSING   when the name has no proxiable (A/AAAA/CNAME) record at all
     *
     * "Every one proxied" is deliberate, not "any": a grey-cloud AAAA next to a
     * proxied A still leaks the origin over IPv6, so it must read as UNPROXIED.
     * Non-proxiable types (MX, TXT, NS, …) are ignored.
     *
     * @param Record[] $records all records in the zone
     */
    public static function ofName(array $records, string $name): string
    {
        $name = DnsName::normalise($name);

        $found = false;
        $allProxied = true;
        foreach ($records as $record) {
            if ($record->name !== $name || !in_array($record->type, self::PROXIABLE, true)) {
                continue;
            }
            $found = true;
            if ($record->proxied !== true) {
                $allProxied = false;
            }
        }

        if (!$found) {
            return self::MISSING;
        }
        return $allProxied ? self::PROXIED : self::UNPROXIED;
    }
}

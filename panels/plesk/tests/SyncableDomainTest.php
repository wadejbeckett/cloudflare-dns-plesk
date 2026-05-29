<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\PleskDns\SyncableDomain;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the syncability predicate that filters which Plesk main
 * domains are offered to Cloudflare sync.
 *
 * Coverage is intentionally limited to the pure-PHP hostname path —
 * the zone-type filter (`pm_Dns_Zone::getType()` + `dns_zone.type`
 * DB fallback) is Plesk-runtime-dependent and is exercised by manual
 * verification on dev-plesk. See `SyncableDomain::isMasterZone`.
 *
 * The hostname assertions are host-sensitive: they read whatever
 * `gethostname()` returns at test time and assert that value is
 * excluded. This keeps the tests pure with no Plesk dependency.
 */
final class SyncableDomainTest extends TestCase
{
    public function testEmptyStringIsExcluded(): void
    {
        self::assertFalse(SyncableDomain::isSyncable(''));
    }

    public function testHostnameComparisonExcludesGethostname(): void
    {
        $host = gethostname();
        // gethostname() returns false on extremely degraded systems; skip
        // there rather than asserting against a falsy value.
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('gethostname() returned no value on this host.');
        }
        self::assertFalse(
            SyncableDomain::isSyncable($host),
            "expected the local hostname '$host' to be excluded from sync"
        );
    }
}

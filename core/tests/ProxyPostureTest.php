<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\ProxyPosture;
use Noiz\CloudflareDns\Cloudflare\Record;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `ProxyPosture::ofName` — the rule behind the read-only
 * settings-page proxy badge. The load-bearing decision under test is that a
 * name is only "proxied" when EVERY proxiable record is orange-clouded, so a
 * stray grey-cloud AAAA (an IPv6 origin leak) reads as "unproxied".
 */
final class ProxyPostureTest extends TestCase
{
    /** @return Record[] */
    private function recs(array $rows): array
    {
        return array_map(static fn (array $r): Record => Record::fromCloudflare($r), $rows);
    }

    public function testProxiedWhenAllProxiableRecordsAreProxied(): void
    {
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => true],
            ['type' => 'AAAA', 'name' => 'example.com', 'content' => '2606::1', 'proxied' => true],
            ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'priority' => 10],
        ]);

        self::assertSame(ProxyPosture::PROXIED, ProxyPosture::ofName($records, 'example.com'));
    }

    public function testGreyAaaaAlongsideProxiedAReadsAsUnproxied(): void
    {
        // The IPv6-leak case: A is orange but AAAA is grey, so the origin still
        // leaks over IPv6 — must NOT report as fully proxied.
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => true],
            ['type' => 'AAAA', 'name' => 'example.com', 'content' => '2606::1', 'proxied' => false],
        ]);

        self::assertSame(ProxyPosture::UNPROXIED, ProxyPosture::ofName($records, 'example.com'));
    }

    public function testUnproxiedWhenAllGrey(): void
    {
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => false],
        ]);

        self::assertSame(ProxyPosture::UNPROXIED, ProxyPosture::ofName($records, 'example.com'));
    }

    public function testProxiedCnameCounts(): void
    {
        $records = $this->recs([
            ['type' => 'CNAME', 'name' => 'www.example.com', 'content' => 'example.com', 'proxied' => true],
        ]);

        self::assertSame(ProxyPosture::PROXIED, ProxyPosture::ofName($records, 'www.example.com'));
    }

    public function testMissingWhenNoProxiableRecordAtName(): void
    {
        // Only non-proxiable types at the name → "missing" (no orange-cloud concept).
        $records = $this->recs([
            ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 -all'],
        ]);

        self::assertSame(ProxyPosture::MISSING, ProxyPosture::ofName($records, 'example.com'));
    }

    public function testMissingWhenNameAbsentEntirely(): void
    {
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => true],
        ]);

        // www has no record at all.
        self::assertSame(ProxyPosture::MISSING, ProxyPosture::ofName($records, 'www.example.com'));
    }

    public function testNonProxiableSiblingsDoNotBlockProxied(): void
    {
        // A proxied A plus unrelated MX/TXT at the apex is still "proxied" —
        // MX/TXT can't be orange-clouded and must be ignored, not counted grey.
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => true],
            ['type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1 -all'],
            ['type' => 'NS', 'name' => 'example.com', 'content' => 'ns.example.com'],
        ]);

        self::assertSame(ProxyPosture::PROXIED, ProxyPosture::ofName($records, 'example.com'));
    }

    public function testQueriedNameIsNormalised(): void
    {
        $records = $this->recs([
            ['type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4', 'proxied' => true],
        ]);

        // Trailing dot + uppercase in the query must still match the stored name.
        self::assertSame(ProxyPosture::PROXIED, ProxyPosture::ofName($records, 'Example.com.'));
    }
}

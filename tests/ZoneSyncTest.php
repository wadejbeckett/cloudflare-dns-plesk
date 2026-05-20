<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\Record;
use Noiz\CloudflareDns\Cloudflare\ZoneSync;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the diff engine. ZoneSync::plan() is pure, so these need no
 * mocks and no network.
 */
final class ZoneSyncTest extends TestCase
{
    /** A record as it arrives from the control panel (no Cloudflare fields). */
    private function panel(string $type, string $name, string $content, int $ttl = 3600, ?int $priority = null): Record
    {
        return new Record($type, $name, $content, $ttl, $priority);
    }

    /** A record as it exists in Cloudflare (has an id, proxy state, etc.). */
    private function cf(string $id, string $type, string $name, string $content, int $ttl = 1, bool $proxied = false, ?int $priority = null): Record
    {
        return new Record($type, $name, $content, $ttl, $priority, $id, $proxied, true);
    }

    public function testIdenticalZoneProducesNoChanges(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '1.2.3.4', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)]
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->unchanged);
    }

    public function testContentChangeBecomesUpdateNotRecreate(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '9.9.9.9', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)]
        );

        self::assertCount(0, $plan->creates);
        self::assertCount(0, $plan->deletes);
        self::assertCount(1, $plan->updates);
        self::assertSame('r1', $plan->updates[0]->existing->id);
    }

    public function testProxiedRecordKeepsProxyStateOnContentChange(): void
    {
        // The exact bug scenario: an orange-clouded record whose IP changes.
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '9.9.9.9', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 1, true)]
        );

        self::assertCount(1, $plan->updates);

        $payload = $plan->updates[0]->patchPayload();
        // `proxied` must never appear — Cloudflare then keeps it as-is.
        self::assertArrayNotHasKey('proxied', $payload);
        // `ttl` must not be sent for a proxied record.
        self::assertArrayNotHasKey('ttl', $payload);
        self::assertSame('9.9.9.9', $payload['content']);
    }

    public function testNewRecordBecomesCreate(): void
    {
        $plan = ZoneSync::plan([$this->panel('A', 'new.example.com', '1.2.3.4')], []);

        self::assertCount(1, $plan->creates);
        self::assertCount(0, $plan->updates);
    }

    public function testRemovedRecordBecomesDeleteWhenPruning(): void
    {
        $plan = ZoneSync::plan(
            [],
            [$this->cf('r1', 'A', 'old.example.com', '1.2.3.4')],
            ['prune' => true]
        );

        self::assertCount(1, $plan->deletes);
    }

    public function testRemovedRecordIsKeptWhenPruneDisabled(): void
    {
        $plan = ZoneSync::plan(
            [],
            [$this->cf('r1', 'A', 'old.example.com', '1.2.3.4')],
            ['prune' => false]
        );

        self::assertCount(0, $plan->deletes);
        self::assertCount(1, $plan->ignored);
    }

    public function testCloudflareNativeRecordIsNotDeleted(): void
    {
        // managedIds lists only records we created; r2 is Cloudflare-native.
        $plan = ZoneSync::plan(
            [],
            [
                $this->cf('r1', 'A', 'a.example.com', '1.1.1.1'),
                $this->cf('r2', 'TXT', 'b.example.com', 'cf-native'),
            ],
            ['prune' => true, 'managedIds' => ['r1']]
        );

        self::assertCount(1, $plan->deletes);
        self::assertSame('r1', $plan->deletes[0]->id);
        self::assertCount(1, $plan->ignored);
        self::assertSame('r2', $plan->ignored[0]->id);
    }

    public function testRoundRobinRecordsArePairedAsUpdates(): void
    {
        // Two proxied A records for the same name; one IP changes.
        $plan = ZoneSync::plan(
            [
                $this->panel('A', 'www.example.com', '1.1.1.1'),
                $this->panel('A', 'www.example.com', '3.3.3.3'),
            ],
            [
                $this->cf('r1', 'A', 'www.example.com', '1.1.1.1', 1, true),
                $this->cf('r2', 'A', 'www.example.com', '2.2.2.2', 1, true),
            ]
        );

        self::assertCount(1, $plan->unchanged);
        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->creates);
        self::assertCount(0, $plan->deletes);
    }

    public function testNsAndSoaRecordsAreSkipped(): void
    {
        $plan = ZoneSync::plan(
            [
                $this->panel('NS', 'example.com', 'ns1.someother.com'),
                $this->panel('SOA', 'example.com', 'ns1.example.com admin.example.com'),
                $this->panel('A', 'example.com', '1.2.3.4'),
            ],
            [$this->cf('r1', 'NS', 'example.com', 'ns.cloudflare.com')]
        );

        // Only the A record is acted on; NS/SOA never create, update or delete.
        self::assertCount(1, $plan->creates);
        self::assertSame('A', $plan->creates[0]->type);
        self::assertCount(0, $plan->updates);
        self::assertCount(0, $plan->deletes);
    }

    public function testTtlChangeOnUnproxiedRecordBecomesUpdate(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '1.2.3.4', 7200)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)]
        );

        self::assertCount(1, $plan->updates);
        self::assertSame(7200, $plan->updates[0]->patchPayload()['ttl']);
    }

    public function testTxtRecordQuotingDifferenceIsNotAFalseChange(): void
    {
        // Cloudflare may return TXT content wrapped in quotes; Plesk may not.
        // Same TTL on both sides so only the quoting could trigger a diff.
        $plan = ZoneSync::plan(
            [$this->panel('TXT', 'example.com', 'v=spf1 -all', 3600)],
            [$this->cf('r1', 'TXT', 'example.com', '"v=spf1 -all"', 3600, false)]
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->unchanged);
    }
}

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
        return new Record($type, $name, $content, $ttl, $priority, $id, $proxied);
    }

    public function testManagedRecordAlreadyCorrectIsUnchanged(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '1.2.3.4', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)],
            ['managedIds' => ['r1']]
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->unchanged);
    }

    public function testContentChangeOnManagedRecordIsUpdate(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '9.9.9.9', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)],
            ['managedIds' => ['r1']]
        );

        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->creates);
        self::assertCount(0, $plan->deletes);
        self::assertSame('r1', $plan->updates[0]->existing->id);
    }

    public function testProxiedManagedRecordKeepsProxyStateOnContentChange(): void
    {
        // The exact bug scenario: an orange-clouded record whose IP changes.
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '9.9.9.9', 3600)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 1, true)],
            ['managedIds' => ['r1']]
        );

        self::assertCount(1, $plan->updates);
        $payload = $plan->updates[0]->patchPayload();
        // `proxied` must never appear -> Cloudflare keeps it as-is.
        self::assertArrayNotHasKey('proxied', $payload);
        // `ttl` must not be sent for a proxied record.
        self::assertArrayNotHasKey('ttl', $payload);
        self::assertSame('9.9.9.9', $payload['content']);
    }

    public function testForeignRecordIsNeverTouched(): void
    {
        // A record we do not manage, that the panel does not want either,
        // must be left completely alone — not deleted, not updated.
        $plan = ZoneSync::plan(
            [],
            [$this->cf('foreign1', 'TXT', 'other.example.com', 'someone-elses-record')],
            ['managedIds' => []]
        );

        self::assertCount(0, $plan->deletes);
        self::assertCount(0, $plan->updates);
        self::assertCount(1, $plan->ignored);
        self::assertSame('foreign1', $plan->ignored[0]->id);
    }

    public function testAcmeChallengeUpdatesOnlyPleskOwnRecord(): void
    {
        // _acme-challenge.example.com carries TWO TXT records at the same name:
        //   - 'mine'   created by this extension (managed), value now changing
        //   - 'theirs' created by another ACME client (foreign)
        $name = '_acme-challenge.example.com';

        $plan = ZoneSync::plan(
            [$this->panel('TXT', $name, 'plesk-token-new')],
            [
                $this->cf('mine', 'TXT', $name, 'plesk-token-old'),
                $this->cf('theirs', 'TXT', $name, 'other-service-token'),
            ],
            ['managedIds' => ['mine']]
        );

        // Exactly one update, on OUR record; the other service is untouched.
        self::assertCount(1, $plan->updates);
        self::assertSame('mine', $plan->updates[0]->existing->id);
        self::assertSame('plesk-token-new', $plan->updates[0]->patchPayload()['content']);
        self::assertCount(0, $plan->creates);
        self::assertCount(0, $plan->deletes);
        self::assertCount(1, $plan->ignored);
        self::assertSame('theirs', $plan->ignored[0]->id);
    }

    public function testNewRecordBecomesCreate(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'new.example.com', '1.2.3.4')],
            [],
            ['managedIds' => []]
        );

        self::assertCount(1, $plan->creates);
        self::assertCount(0, $plan->adopted);
    }

    public function testIdenticalForeignRecordIsAdoptedNotDuplicated(): void
    {
        // The panel wants a record that already exists in Cloudflare but is
        // not yet managed -> adopt it instead of creating a duplicate.
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '1.2.3.4', 1)],
            [$this->cf('pre1', 'A', 'www.example.com', '1.2.3.4', 1, false)],
            ['managedIds' => []]
        );

        self::assertCount(0, $plan->creates);
        self::assertCount(1, $plan->adopted);
        self::assertSame('pre1', $plan->adopted[0]->id);
        self::assertTrue($plan->isEmpty()); // adoption needs no API call
    }

    public function testRemovedManagedRecordIsDeletedWhenPruning(): void
    {
        $plan = ZoneSync::plan(
            [],
            [$this->cf('r1', 'A', 'old.example.com', '1.2.3.4')],
            ['managedIds' => ['r1'], 'prune' => true]
        );

        self::assertCount(1, $plan->deletes);
        self::assertSame('r1', $plan->deletes[0]->id);
    }

    public function testRemovedManagedRecordIsKeptWhenPruneDisabled(): void
    {
        $plan = ZoneSync::plan(
            [],
            [$this->cf('r1', 'A', 'old.example.com', '1.2.3.4')],
            ['managedIds' => ['r1'], 'prune' => false]
        );

        self::assertCount(0, $plan->deletes);
        self::assertCount(1, $plan->ignored);
    }

    public function testRoundRobinManagedRecordsArePairedAsUpdates(): void
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
            ],
            ['managedIds' => ['r1', 'r2']]
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
                $this->panel('NS', 'example.com', 'ns1.elsewhere.com'),
                $this->panel('SOA', 'example.com', 'ns1.example.com admin.example.com'),
                $this->panel('A', 'example.com', '1.2.3.4'),
            ],
            [$this->cf('r1', 'NS', 'example.com', 'ns.cloudflare.com')],
            ['managedIds' => ['r1']]
        );

        // Only the A record is acted on; NS/SOA never create, update or delete.
        self::assertCount(1, $plan->creates);
        self::assertSame('A', $plan->creates[0]->type);
        self::assertCount(0, $plan->updates);
        self::assertCount(0, $plan->deletes);
    }

    public function testTtlChangeOnManagedUnproxiedRecordIsUpdate(): void
    {
        $plan = ZoneSync::plan(
            [$this->panel('A', 'www.example.com', '1.2.3.4', 7200)],
            [$this->cf('r1', 'A', 'www.example.com', '1.2.3.4', 3600, false)],
            ['managedIds' => ['r1']]
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
            [$this->cf('r1', 'TXT', 'example.com', '"v=spf1 -all"', 3600, false)],
            ['managedIds' => ['r1']]
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->unchanged);
    }
}

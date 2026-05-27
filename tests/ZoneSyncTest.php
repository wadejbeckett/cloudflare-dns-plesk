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

    public function testQuotedTxtMatchingItsCloudflareEquivalentIsUnchanged(): void
    {
        // Post-v0.4.10 Plesk always wraps TXT in `"..."` (PleskDns\Payload).
        // When Cloudflare's stored form has the same shape: nothing to do.
        $plan = ZoneSync::plan(
            [$this->panel('TXT', 'example.com', '"v=spf1 -all"', 3600)],
            [$this->cf('r1', 'TXT', 'example.com', '"v=spf1 -all"', 3600, false)],
            ['managedIds' => ['r1']]
        );

        self::assertTrue($plan->isEmpty());
        self::assertCount(1, $plan->unchanged);
    }

    public function testLegacyUnquotedManagedTxtIsForciblyUpdatedToCanonicalForm(): void
    {
        // Legacy: a managed Cloudflare record stored UNQUOTED (created
        // before v0.4.10 introduced quote-wrapping). Plesk now sends the
        // canonical quoted form — sameValue() says they're equivalent so
        // we don't false-pair or duplicate-create, but matches() must NOT
        // mark it unchanged: a PATCH must fire to push the canonical shape
        // and clear Cloudflare's "missing double-quotes" UI warning.
        $plan = ZoneSync::plan(
            [$this->panel('TXT', 'example.com', '"v=spf1 -all"', 3600)],
            [$this->cf('r1', 'TXT', 'example.com', 'v=spf1 -all', 3600, false)],
            ['managedIds' => ['r1']]
        );

        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->unchanged);
        self::assertSame('"v=spf1 -all"', $plan->updates[0]->desired->content);
    }

    public function testAdoptedLegacyUnquotedTxtAlsoQueuesCanonicalUpdate(): void
    {
        // First sync against a zone holding a FOREIGN (no marker) unquoted
        // TXT: adopt the record AND queue a content update so a single
        // resync brings it into the canonical quoted form. Without the
        // adoption-side update, foreign records would stay unquoted forever
        // (adoption alone only stamps the marker, doesn't rewrite content).
        $plan = ZoneSync::plan(
            [$this->panel('TXT', 'example.com', '"v=spf1 -all"', 3600)],
            [$this->cf('r1', 'TXT', 'example.com', 'v=spf1 -all', 3600, false)],
            []
        );

        self::assertCount(1, $plan->adopted);
        self::assertCount(1, $plan->updates);
        self::assertSame('"v=spf1 -all"', $plan->updates[0]->desired->content);
    }

    public function testAcmeChallengeWithManualSiblingsUpdatesOnlyTheRenewedRecord(): void
    {
        // Plesk's own _acme-challenge TXT plus two a client added by hand —
        // all managed. Plesk renews only its own; the manual ones must not move.
        $name = '_acme-challenge.example.com';

        $plan = ZoneSync::plan(
            [
                $this->panel('TXT', $name, 'plesk-auto-NEW'),
                $this->panel('TXT', $name, 'client-manual-A'),
                $this->panel('TXT', $name, 'client-manual-B'),
            ],
            [
                $this->cf('auto', 'TXT', $name, 'plesk-auto-OLD', 3600),
                $this->cf('manA', 'TXT', $name, 'client-manual-A', 3600),
                $this->cf('manB', 'TXT', $name, 'client-manual-B', 3600),
            ],
            ['managedIds' => ['auto', 'manA', 'manB']]
        );

        // The two manual records are recognised unchanged; only the renewed
        // record is updated — a PATCH on the old auto record, not a recreate.
        self::assertCount(2, $plan->unchanged);
        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->creates);
        self::assertCount(0, $plan->deletes);
        self::assertSame('auto', $plan->updates[0]->existing->id);
        self::assertSame('plesk-auto-NEW', $plan->updates[0]->patchPayload()['content']);
    }

    public function testSrvRecordPortChangeIsAnUpdate(): void
    {
        $desired = new Record(
            'SRV', '_sip._tcp.example.com', '5 5061 sip.example.com', 3600,
            null, null, null, null,
            ['priority' => 10, 'weight' => 5, 'port' => 5061, 'target' => 'sip.example.com']
        );
        $existing = new Record(
            'SRV', '_sip._tcp.example.com', '5 5060 sip.example.com', 3600,
            null, 'srv1', null, null,
            ['priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com']
        );

        $plan = ZoneSync::plan([$desired], [$existing], ['managedIds' => ['srv1']]);

        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->creates);
        self::assertSame(5061, $plan->updates[0]->patchPayload()['data']['port']);
    }

    public function testTlsaRecordCertificateChangeIsAnUpdate(): void
    {
        // The exact DANE rotate scenario: same record, new certificate hash.
        $desired = new Record(
            'TLSA', '_443._tcp.example.com', '3 1 1 newhash', 3600,
            null, null, null, null,
            ['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => 'newhash']
        );
        $existing = new Record(
            'TLSA', '_443._tcp.example.com', '3 1 1 oldhash', 3600,
            null, 'tlsa1', null, null,
            ['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => 'oldhash']
        );

        $plan = ZoneSync::plan([$desired], [$existing], ['managedIds' => ['tlsa1']]);

        self::assertCount(1, $plan->updates);
        self::assertCount(0, $plan->creates);
        self::assertSame('newhash', $plan->updates[0]->patchPayload()['data']['certificate']);
    }
}

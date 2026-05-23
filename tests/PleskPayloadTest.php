<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\PleskDns\Payload;
use Noiz\CloudflareDns\PleskDns\ZoneOperation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the translator that turns Plesk's custom-DNS-backend JSON into
 * ZoneOperations and Records. Pure — no Plesk, no network.
 */
final class PleskPayloadTest extends TestCase
{
    /** A representative Plesk custom-DNS-backend payload. */
    private function samplePayload(string $command = 'update'): string
    {
        return (string) json_encode([
            [
                'command' => $command,
                'zone' => [
                    'name' => 'example.com.',
                    'displayName' => 'example.com.',
                    'soa' => ['ttl' => 86400],
                    'rr' => [
                        ['host' => 'example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'opt' => ''],
                        ['host' => 'www.example.com.', 'type' => 'CNAME', 'value' => 'example.com.', 'opt' => ''],
                        ['host' => 'example.com.', 'type' => 'MX', 'value' => 'mail.example.com.', 'opt' => '10'],
                        ['host' => 'example.com.', 'type' => 'NS', 'value' => 'ns1.example.com.', 'opt' => ''],
                        ['host' => '_acme-challenge.example.com.', 'type' => 'TXT', 'value' => 'acme-token', 'opt' => ''],
                    ],
                ],
            ],
        ]);
    }

    public function testParsesUpdateOperationWithSupportedRecords(): void
    {
        $result = Payload::parse($this->samplePayload());

        self::assertCount(1, $result['operations']);
        $operation = $result['operations'][0];
        self::assertSame(ZoneOperation::UPDATE, $operation->command);
        self::assertSame('example.com', $operation->zoneName);
        // A, CNAME, MX, TXT -> 4 records (NS is dropped).
        self::assertCount(4, $operation->records);
    }

    public function testCreateCommandIsNormalisedToUpdate(): void
    {
        $result = Payload::parse($this->samplePayload('create'));
        self::assertSame(ZoneOperation::UPDATE, $result['operations'][0]->command);
    }

    public function testDeleteCommandIsParsed(): void
    {
        $result = Payload::parse($this->samplePayload('delete'));
        self::assertSame(ZoneOperation::DELETE, $result['operations'][0]->command);
        self::assertSame('example.com', $result['operations'][0]->zoneName);
    }

    public function testMxPriorityComesFromOpt(): void
    {
        $mx = null;
        foreach (Payload::parse($this->samplePayload())['operations'][0]->records as $record) {
            if ($record->type === 'MX') {
                $mx = $record;
            }
        }

        self::assertNotNull($mx);
        self::assertSame(10, $mx->priority);
        self::assertSame('mail.example.com', $mx->content); // trailing dot stripped
    }

    public function testTrailingDotsAreStrippedFromNames(): void
    {
        $names = array_map(
            static fn ($record) => $record->name,
            Payload::parse($this->samplePayload())['operations'][0]->records
        );

        self::assertContains('_acme-challenge.example.com', $names);
        self::assertNotContains('_acme-challenge.example.com.', $names);
    }

    public function testNsRecordsAreSilentlyDropped(): void
    {
        $result = Payload::parse($this->samplePayload());

        foreach ($result['operations'][0]->records as $record) {
            self::assertNotSame('NS', $record->type);
        }
        // NS is provider-managed — dropped, but NOT reported as unsupported.
        self::assertSame([], $result['skipped']);
    }

    public function testUnsupportedTypeIsReportedAsSkipped(): void
    {
        // DS (DNSSEC delegation signer) — not synced; a sensible representative
        // of a Plesk-supported but extension-unsupported type.
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => 'example.com.', 'type' => 'DS', 'value' => '12345 8 2 abcdef', 'opt' => ''],
                    ],
                ],
            ],
        ]);

        $result = Payload::parse($json);

        self::assertCount(0, $result['operations'][0]->records);
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('DS', $result['skipped'][0]);
    }

    public function testTlsaRecordIsParsedIntoStructuredData(): void
    {
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => '_443._tcp.mail.example.com.', 'type' => 'TLSA', 'value' => 'ABC123', 'opt' => '3 1 1'],
                    ],
                ],
            ],
        ]);

        $records = Payload::parse($json)['operations'][0]->records;

        self::assertCount(1, $records);
        self::assertSame('TLSA', $records[0]->type);
        self::assertSame('_443._tcp.mail.example.com', $records[0]->name);
        self::assertSame(
            // The hex certificate is lower-cased so a re-uploaded cert that
            // re-cases the hex doesn't look like a real change.
            ['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => 'abc123'],
            $records[0]->data
        );
    }

    public function testSrvRecordIsParsedIntoStructuredData(): void
    {
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => '_imaps._tcp.example.com.', 'type' => 'SRV', 'value' => 'mail.example.com.', 'opt' => '1 5 993'],
                    ],
                ],
            ],
        ]);

        $records = Payload::parse($json)['operations'][0]->records;

        self::assertCount(1, $records);
        self::assertSame('SRV', $records[0]->type);
        self::assertSame('_imaps._tcp.example.com', $records[0]->name);
        self::assertSame(
            ['priority' => 1, 'weight' => 5, 'port' => 993, 'target' => 'mail.example.com'],
            $records[0]->data
        );
    }

    public function testCaaRecordIsParsedIntoStructuredData(): void
    {
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => 'example.com.', 'type' => 'CAA', 'value' => 'letsencrypt.org', 'opt' => '0 issue'],
                    ],
                ],
            ],
        ]);

        $records = Payload::parse($json)['operations'][0]->records;

        self::assertCount(1, $records);
        self::assertSame('CAA', $records[0]->type);
        self::assertSame(
            ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
            $records[0]->data
        );
    }

    public function testSrvRecordWithDotTargetIsPreserved(): void
    {
        // RFC 2782: a target of "." means the service is decidedly not
        // available — the dot must survive name normalisation.
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => '_autodiscover._tcp.example.com.', 'type' => 'SRV', 'value' => '.', 'opt' => '0 0 0'],
                    ],
                ],
            ],
        ]);

        $records = Payload::parse($json)['operations'][0]->records;

        self::assertCount(1, $records);
        self::assertSame('.', $records[0]->data['target']);
    }

    public function testTriggerHostRecordsAreSilentlyDropped(): void
    {
        // The extension uses `_cfdns-trigger.<domain>` as its own
        // single-zone-sync trigger. Records under that prefix must never
        // sync to Cloudflare AND must not appear in the "skipped" report —
        // they're an internal mechanism, invisible to the operator.
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => '_cfdns-trigger.example.com.', 'type' => 'TXT', 'value' => 'cfdns-trigger', 'opt' => ''],
                        ['host' => 'real.example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'opt' => ''],
                    ],
                ],
            ],
        ]);

        $result = Payload::parse($json);

        // Only the real A record makes it through; the trigger is invisible.
        self::assertCount(1, $result['operations'][0]->records);
        self::assertSame('A', $result['operations'][0]->records[0]->type);
        self::assertSame([], $result['skipped']);
    }

    public function testNullMxRecordKeepsItsDotTarget(): void
    {
        // RFC 7505: "MX 0 ." declares the domain accepts no mail — the bare
        // "." target must not be stripped to an empty (invalid) value.
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => 'example.com.', 'type' => 'MX', 'value' => '.', 'opt' => '0'],
                    ],
                ],
            ],
        ]);

        $records = Payload::parse($json)['operations'][0]->records;

        self::assertCount(1, $records);
        self::assertSame('MX', $records[0]->type);
        self::assertSame('.', $records[0]->content);
        self::assertSame(0, $records[0]->priority);
    }

    public function testPtrOperationsAreIgnored(): void
    {
        $json = (string) json_encode([
            ['command' => 'createPTRs', 'ptr' => ['ip_address' => '1.2.3.4', 'hostname' => 'example.com']],
        ]);

        self::assertSame([], Payload::parse($json)['operations']);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Payload::parse('this is not json');
    }
}

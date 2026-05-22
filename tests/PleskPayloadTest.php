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
        $json = (string) json_encode([
            [
                'command' => 'update',
                'zone' => [
                    'name' => 'example.com.',
                    'soa' => ['ttl' => 3600],
                    'rr' => [
                        ['host' => '_443._tcp.example.com.', 'type' => 'TLSA', 'value' => 'abcdef', 'opt' => '3 1 1'],
                    ],
                ],
            ],
        ]);

        $result = Payload::parse($json);

        self::assertCount(0, $result['operations'][0]->records);
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('TLSA', $result['skipped'][0]);
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

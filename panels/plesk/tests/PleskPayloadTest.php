<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\PleskDns\Payload;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Payload::toRecord — the mapper that turns one Plesk DNS
 * record's fields into a panel-agnostic Record value object. Pure: no
 * Plesk, no network.
 *
 * (Prior to v0.5.0 this class also parsed Plesk's custom-DNS-backend
 * JSON payload. That code path is gone; the per-record value handling
 * is the only thing this class still does, and it's the only thing
 * worth testing here.)
 */
final class PleskPayloadTest extends TestCase
{
    private function rr(string $host, string $type, string $value, string $opt = '', ?int $ttl = null): array
    {
        return ['host' => $host, 'type' => $type, 'value' => $value, 'opt' => $opt, 'ttl' => $ttl];
    }

    public function testTrailingDotsAreStrippedFromNames(): void
    {
        $record = Payload::toRecord($this->rr('_acme-challenge.example.com.', 'TXT', 'acme-token'), 'TXT', 3600);
        self::assertSame('_acme-challenge.example.com', $record->name);
    }

    public function testMxPriorityComesFromOpt(): void
    {
        $record = Payload::toRecord($this->rr('example.com.', 'MX', 'mail.example.com.', '10'), 'MX', 3600);
        self::assertSame('MX', $record->type);
        self::assertSame(10, $record->priority);
        // CNAME/MX target loses the trailing dot Plesk includes.
        self::assertSame('mail.example.com', $record->content);
    }

    public function testNullMxIsPreservedAsBareDot(): void
    {
        // RFC 7505 "MX 0 ." declares the domain accepts no mail. The bare
        // dot must survive — the trailing-dot strip applied to CNAME/MX
        // targets must not flatten it to an empty string.
        $record = Payload::toRecord($this->rr('example.com.', 'MX', '.', '0'), 'MX', 3600);
        self::assertSame('.', $record->content);
        self::assertSame(0, $record->priority);
    }

    public function testTlsaRecordIsParsedIntoStructuredData(): void
    {
        $record = Payload::toRecord(
            $this->rr('_443._tcp.mail.example.com.', 'TLSA', 'ABC123', '3 1 1'),
            'TLSA',
            3600
        );

        self::assertSame('TLSA', $record->type);
        self::assertSame('_443._tcp.mail.example.com', $record->name);
        self::assertSame(
            // Hex certificate is lower-cased so a re-uploaded cert that
            // re-cases the hex doesn't look like a real change.
            ['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => 'abc123'],
            $record->data
        );
    }

    public function testSrvRecordIsParsedIntoStructuredData(): void
    {
        $record = Payload::toRecord(
            $this->rr('_imaps._tcp.example.com.', 'SRV', 'mail.example.com.', '10 5 993'),
            'SRV',
            3600
        );

        self::assertSame('SRV', $record->type);
        self::assertSame([
            'priority' => 10,
            'weight' => 5,
            'port' => 993,
            'target' => 'mail.example.com',
        ], $record->data);
    }

    public function testSrvNullTargetIsPreservedAsBareDot(): void
    {
        // RFC 2782 "service decidedly not available" target ".". Must
        // not be flattened by name normalisation.
        $record = Payload::toRecord(
            $this->rr('_imaps._tcp.example.com.', 'SRV', '.', '0 0 0'),
            'SRV',
            3600
        );

        self::assertSame('.', $record->data['target']);
    }

    public function testCaaRecordIsParsedIntoStructuredData(): void
    {
        $record = Payload::toRecord(
            $this->rr('example.com.', 'CAA', 'letsencrypt.org', '0 issue'),
            'CAA',
            3600
        );

        self::assertSame('CAA', $record->type);
        self::assertSame([
            'flags' => 0,
            'tag' => 'issue',
            'value' => 'letsencrypt.org',
        ], $record->data);
    }

    public function testShortUnquotedTxtIsWrappedInDoubleQuotes(): void
    {
        $record = Payload::toRecord(
            $this->rr('_acme-challenge.example.com.', 'TXT', '_dKDQnSC_laK8d5kzf6wCDd8OgnPeI892Hh81sbeQ8M'),
            'TXT',
            3600
        );

        self::assertSame('"_dKDQnSC_laK8d5kzf6wCDd8OgnPeI892Hh81sbeQ8M"', $record->content);
    }

    public function testAlreadyQuotedTxtIsNotDoubleWrapped(): void
    {
        $record = Payload::toRecord(
            $this->rr('example.com.', 'TXT', '"v=spf1 -all"'),
            'TXT',
            3600
        );

        self::assertSame('"v=spf1 -all"', $record->content);
    }

    public function testLongTxtIsSplitInto255ByteChunks(): void
    {
        $first = str_repeat('a', 255);
        $second = str_repeat('b', 100);
        $record = Payload::toRecord(
            $this->rr('example.com.', 'TXT', $first . $second),
            'TXT',
            3600
        );

        self::assertSame('"' . $first . '" "' . $second . '"', $record->content);
    }

    public function testMissingTtlFallsBackToDefault(): void
    {
        $record = Payload::toRecord($this->rr('example.com.', 'A', '1.2.3.4'), 'A', 7200);
        self::assertSame(7200, $record->ttl);
    }
}

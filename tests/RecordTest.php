<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\Record;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `Record` value object — focused on `sameValue` /
 * `normalisedContent` equivalence across BIND-style 255-byte chunking
 * vs Cloudflare's single-continuous-string storage of TXT content.
 *
 * Live regression (2026-05-28, `neo.noiz.co.za`):
 * `google._domainkey.escentia.co.za` TXT was flagged as a content
 * conflict because Plesk's Payload renders DKIM as
 * `"<255 bytes>" "<remainder>"` while Cloudflare returns the same
 * content as a single unquoted string — byte-different, semantically
 * identical. `normalisedContent` for TXT must collapse the literal
 * 3-byte chunk separator (`" "`) in addition to stripping outer
 * quotes so `sameValue` returns true.
 */
final class RecordTest extends TestCase
{
    public function testTxtChunkedAndUnchunkedHaveSameValue(): void
    {
        // Chunked form as Plesk's Payload::toRecord renders it.
        $a = new Record('TXT', 'dkim._domainkey.x.com', '"v=DKIM1; k=rsa; p=AAAA" "BBBB"', 3600);
        // CF-stored single continuous string, no quotes.
        $b = new Record('TXT', 'dkim._domainkey.x.com', 'v=DKIM1; k=rsa; p=AAAABBBB', 3600);

        self::assertTrue($a->sameValue($b));
        self::assertTrue($b->sameValue($a));
    }

    public function testTxtSingleStringQuotedSameAsUnquoted(): void
    {
        // Plesk's canonical quoted form vs CF's unquoted single string,
        // both under the 255-byte chunking threshold so neither side
        // contains the `" "` separator. This is the pre-v0.5.13 case
        // already covered by the v0.4.10 outer-quote strip.
        $a = new Record('TXT', 'example.com', '"v=spf1 a -all"', 3600);
        $b = new Record('TXT', 'example.com', 'v=spf1 a -all', 3600);

        self::assertTrue($a->sameValue($b));
        self::assertTrue($b->sameValue($a));
    }

    public function testTxtChunkedThreeWayConsistency(): void
    {
        // Three representations of the SAME 400-byte DKIM key:
        //   - chunked at 200 bytes (atypical split, but legal BIND syntax)
        //   - chunked at 250 bytes (closer to BIND's 255-byte default)
        //   - single continuous string (CF dashboard / CF API return form)
        $first200 = str_repeat('A', 200);
        $remainder200 = str_repeat('A', 200);
        $first250 = str_repeat('A', 250);
        $remainder250 = str_repeat('A', 150);
        $singleString = str_repeat('A', 400);

        $chunked200 = new Record('TXT', 'big.example.com', '"' . $first200 . '" "' . $remainder200 . '"', 3600);
        $chunked250 = new Record('TXT', 'big.example.com', '"' . $first250 . '" "' . $remainder250 . '"', 3600);
        $continuous = new Record('TXT', 'big.example.com', $singleString, 3600);

        // All three pairwise comparisons must hold both ways.
        self::assertTrue($chunked200->sameValue($chunked250));
        self::assertTrue($chunked250->sameValue($chunked200));
        self::assertTrue($chunked200->sameValue($continuous));
        self::assertTrue($continuous->sameValue($chunked200));
        self::assertTrue($chunked250->sameValue($continuous));
        self::assertTrue($continuous->sameValue($chunked250));
    }

    public function testTxtWithDifferentSemanticContentStillDiffers(): void
    {
        // Sanity guard: the collapse must not over-match. Two DKIM keys
        // with genuinely different `p=` payloads remain different after
        // normalisation.
        $a = new Record('TXT', 'dkim._domainkey.x.com', '"v=DKIM1; p=AAAA"', 3600);
        $b = new Record('TXT', 'dkim._domainkey.x.com', '"v=DKIM1; p=BBBB"', 3600);

        self::assertFalse($a->sameValue($b));
        self::assertFalse($b->sameValue($a));
    }

    public function testNonTxtTypesUnaffected(): void
    {
        // A records: different content still differs (collapse is TXT-only).
        $a1 = new Record('A', 'www.example.com', '1.2.3.4', 3600);
        $a2 = new Record('A', 'www.example.com', '1.2.3.5', 3600);
        self::assertFalse($a1->sameValue($a2));

        // CNAME records: trailing-dot canonicalisation is unchanged by this fix.
        $c1 = new Record('CNAME', 'www.example.com', 'target.example.com.', 3600);
        $c2 = new Record('CNAME', 'www.example.com', 'target.example.com', 3600);
        self::assertTrue($c1->sameValue($c2));
    }
}

<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\Ownership;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the comment-marker ownership rules. Pure — no Plesk, no network.
 */
final class OwnershipTest extends TestCase
{
    public function testIsManagedDetectsTheMarker(): void
    {
        self::assertTrue(Ownership::isManaged(Ownership::MARKER));
        self::assertTrue(Ownership::isManaged(Ownership::MARKER . ' a human note'));
        self::assertFalse(Ownership::isManaged('a human note'));
        self::assertFalse(Ownership::isManaged(''));
        self::assertFalse(Ownership::isManaged(null));
    }

    public function testStampAddsTheMarkerToAnEmptyComment(): void
    {
        self::assertSame(Ownership::MARKER, Ownership::stamp(null));
        self::assertSame(Ownership::MARKER, Ownership::stamp(''));
        self::assertSame(Ownership::MARKER, Ownership::stamp('   '));
    }

    public function testStampPreservesAnExistingHumanNote(): void
    {
        self::assertSame(Ownership::MARKER . ' keep me', Ownership::stamp('keep me'));
    }

    public function testStampIsIdempotentOnAnAlreadyMarkedComment(): void
    {
        $already = Ownership::MARKER . ' note';
        self::assertSame($already, Ownership::stamp($already));
    }

    public function testMarkerIsPanelNeutral(): void
    {
        // The marker must not name a single panel — the core is shared.
        self::assertSame('[noiz-dns-sync]', Ownership::MARKER);
    }

    public function testIsManagedStillDetectsTheLegacyPleskMarker(): void
    {
        // Records stamped before the panel-neutral rename must never be
        // orphaned (mis-read as foreign) on a later sync.
        self::assertTrue(Ownership::isManaged('[plesk-dns-sync]'));
        self::assertTrue(Ownership::isManaged('[plesk-dns-sync] a human note'));
    }

    public function testStampLeavesALegacyMarkedCommentUnchanged(): void
    {
        // A legacy-marked record is already "ours"; stamp() must not bolt a
        // second (new) marker onto it, so existing live comments never churn.
        $legacy = '[plesk-dns-sync] keep me';
        self::assertSame($legacy, Ownership::stamp($legacy));
    }
}

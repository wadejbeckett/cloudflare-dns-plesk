<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\PleskDns\AutoEnable;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the auto-enable enrolment predicate.
 *
 * Covers the N1 bug from the 2026-05-28 audit: with auto-enable on, the
 * old cron loop re-added any Plesk domain not present in the activation
 * list every cycle — fighting an operator who manually deactivated one.
 * The fix tracks "seen" domains separately so each is enrolled at most
 * once. Pure — no Plesk, no network.
 */
final class AutoEnableTest extends TestCase
{
    public function testBrandNewDomainIsReturnedAsNew(): void
    {
        $new = AutoEnable::computeNewDomains(
            ['example.com', 'new-site.test'],
            ['example.com']
        );
        self::assertSame(['new-site.test'], $new);
    }

    public function testAlreadySeenDomainIsNotReturned(): void
    {
        $new = AutoEnable::computeNewDomains(
            ['example.com'],
            ['example.com']
        );
        self::assertSame([], $new);
    }

    public function testSeenButNotEnabledDomainIsNotReturned(): void
    {
        // This is the bug being fixed: a domain the operator manually
        // deactivated stays in `seen_domains` and the cron must NOT
        // re-enrol it. The predicate only looks at the seen set; the
        // enabled list is intentionally not an input here.
        $new = AutoEnable::computeNewDomains(
            ['example.com'],
            ['example.com']
        );
        self::assertSame([], $new, 'seen-but-disabled domain must not appear');
    }

    public function testBootstrapEquivalentEmptySeenReturnsAllAsNew(): void
    {
        // The bootstrap path in sync-poll.php never calls this predicate
        // (it short-circuits to mark every domain seen with no enrolment).
        // But the predicate itself, with an empty `$seen`, must report
        // every domain as new — which is exactly what the second cycle
        // after bootstrap would see if a domain were added in between.
        $new = AutoEnable::computeNewDomains(
            ['a.test', 'b.test', 'c.test'],
            []
        );
        self::assertSame(['a.test', 'b.test', 'c.test'], $new);
    }

    public function testOrderMatchesAllMain(): void
    {
        $new = AutoEnable::computeNewDomains(
            ['z.test', 'a.test', 'm.test'],
            ['a.test']
        );
        self::assertSame(['z.test', 'm.test'], $new);
    }

    public function testDuplicatesInAllMainCollapse(): void
    {
        $new = AutoEnable::computeNewDomains(
            ['dup.test', 'dup.test', 'other.test'],
            []
        );
        self::assertSame(['dup.test', 'other.test'], $new);
    }

    public function testEmptyAllMainReturnsEmpty(): void
    {
        self::assertSame([], AutoEnable::computeNewDomains([], ['leftover.test']));
    }
}

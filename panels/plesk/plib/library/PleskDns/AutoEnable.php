<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

/**
 * Pure helpers for the scheduled poll's "Auto-enable new domains" path.
 *
 * Extracted so the enrolment predicate can be unit-tested without a Plesk
 * runtime. The orchestration (reading settings, writing back, logging)
 * stays in `scripts/sync-poll.php`.
 */
final class AutoEnable
{
    /**
     * Return the subset of `$allMain` that has never been seen by the
     * auto-enable path before — i.e. the domains the cron should enrol
     * this cycle. Order matches `$allMain`. Duplicates within `$allMain`
     * collapse to a single new entry.
     *
     * @param string[] $allMain Every main domain currently on this server.
     * @param string[] $seen    Domains previously observed by auto-enable.
     * @return string[]         Newly-seen domains (subset of $allMain).
     */
    public static function computeNewDomains(array $allMain, array $seen): array
    {
        $seenSet = array_fill_keys($seen, true);
        $new = [];
        foreach ($allMain as $name) {
            if (isset($seenSet[$name])) {
                continue;
            }
            $seenSet[$name] = true;
            $new[] = $name;
        }
        return $new;
    }
}

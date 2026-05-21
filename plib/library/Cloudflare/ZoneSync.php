<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * The diff engine — the heart of the project.
 *
 * Given the records a control panel wants ("desired", the whole zone) and the
 * records that currently exist in a Cloudflare zone ("existing"), it computes a
 * {@see SyncPlan} that makes Cloudflare reflect the panel WITHOUT clobbering
 * Cloudflare-owned state or records the panel does not own.
 *
 * Ownership model
 * ---------------
 * Plesk's DNS backend gives no stable per-record identifier. Instead, every
 * record this extension creates is stamped with an ownership marker in its
 * Cloudflare comment; each sync re-derives the set of managed record IDs
 * (`managedIds`) by reading those comments. Existing Cloudflare records are
 * partitioned:
 *
 *  - **managed** — id is in `managedIds`: eligible for update / delete;
 *  - **foreign** — created by another service or by hand: NEVER modified or
 *    deleted (e.g. an `_acme-challenge` TXT record belonging to a different
 *    ACME client must survive untouched);
 *  - **skipped** — `SOA` / `NS`: Cloudflare manages those itself.
 *
 * A desired record with no managed counterpart is normally created; but if an
 * identical record already exists as a foreign record it is *adopted* instead
 * (taken under management, no API call) so the zone never accumulates
 * duplicates.
 *
 * Whenever a managed record can be matched by type+name, a value change is
 * emitted as an UPDATE (PATCH) rather than delete-and-recreate — because
 * recreating a record resets its proxy state, and updating does not.
 *
 * {@see plan()} is a pure function: no I/O, fully unit-testable.
 */
final class ZoneSync
{
    /**
     * @param Record[] $desired  the whole desired zone, from the control panel
     * @param Record[] $existing every record currently in the Cloudflare zone
     * @param array{
     *     managedIds?: string[],
     *     skipTypes?: string[],
     *     prune?: bool,
     *     adopt?: bool
     * } $options
     *   - managedIds: Cloudflare record IDs this extension currently manages
     *                 (default []: nothing managed yet — a first sync);
     *   - skipTypes:  record types Cloudflare owns and we never touch
     *                 (default SOA + NS);
     *   - prune:      delete managed records the panel no longer has
     *                 (default true);
     *   - adopt:      adopt an identical foreign record instead of creating a
     *                 duplicate (default true).
     */
    public static function plan(array $desired, array $existing, array $options = []): SyncPlan
    {
        $managedIds = array_fill_keys($options['managedIds'] ?? [], true);
        $skipTypes = array_map('strtoupper', $options['skipTypes'] ?? ['SOA', 'NS']);
        $prune = $options['prune'] ?? true;
        $adopt = $options['adopt'] ?? true;

        // --- Partition the existing Cloudflare records ----------------------
        $managed = [];
        $foreign = [];
        $ignored = [];

        foreach ($existing as $record) {
            if (in_array($record->type, $skipTypes, true)) {
                $ignored[] = $record;            // Cloudflare manages SOA/NS
            } elseif ($record->id !== null && isset($managedIds[$record->id])) {
                $managed[] = $record;            // ours — eligible for the diff
            } else {
                $foreign[] = $record;            // someone else's — hands off
            }
        }

        $desiredByKey = self::groupByKey(self::reject($desired, $skipTypes));
        $managedByKey = self::groupByKey($managed);
        $foreignByKey = self::groupByKey($foreign);

        $creates = [];
        $updates = [];
        $deletes = [];
        $unchanged = [];
        $adopted = [];

        $keys = array_unique(array_merge(array_keys($desiredByKey), array_keys($managedByKey)));

        foreach ($keys as $key) {
            $want = $desiredByKey[$key] ?? [];
            $have = $managedByKey[$key] ?? [];

            // Pass 1 — exact value matches among our managed records:
            // unchanged, or a TTL-only update.
            foreach ($want as $wi => $wantRecord) {
                foreach ($have as $hi => $haveRecord) {
                    if (!$haveRecord->sameValue($wantRecord)) {
                        continue;
                    }
                    if ($haveRecord->matches($wantRecord)) {
                        $unchanged[] = $haveRecord;
                    } else {
                        $updates[] = new RecordUpdate($haveRecord, $wantRecord);
                    }
                    unset($want[$wi], $have[$hi]);
                    break;
                }
            }
            $want = array_values($want);
            $have = array_values($have);

            // Pass 2 — pair leftover want/have (both ours) as UPDATES. This is
            // what preserves proxy state: a changed value becomes a PATCH on
            // the existing record, never a delete + recreate.
            $pairs = min(count($want), count($have));
            for ($i = 0; $i < $pairs; $i++) {
                $updates[] = new RecordUpdate($have[$i], $want[$i]);
            }

            // Pass 3 — surplus desired records: adopt an identical foreign
            // record if one exists, otherwise create.
            for ($i = $pairs, $n = count($want); $i < $n; $i++) {
                $twin = $adopt ? self::takeForeignTwin($foreignByKey, $key, $want[$i]) : null;
                if ($twin !== null) {
                    $adopted[] = $twin;
                } else {
                    $creates[] = $want[$i];
                }
            }

            // Pass 4 — surplus managed records the panel no longer wants.
            for ($i = $pairs, $n = count($have); $i < $n; $i++) {
                if ($prune) {
                    $deletes[] = $have[$i];
                } else {
                    $ignored[] = $have[$i];
                }
            }
        }

        // Every foreign record not adopted is left completely untouched.
        foreach ($foreignByKey as $records) {
            foreach ($records as $record) {
                $ignored[] = $record;
            }
        }

        return new SyncPlan($creates, $updates, $deletes, $unchanged, $ignored, $adopted);
    }

    /**
     * Find and remove an exact-value foreign record eligible for adoption.
     *
     * @param array<string,Record[]> $foreignByKey mutated: the twin is removed
     */
    private static function takeForeignTwin(array &$foreignByKey, string $key, Record $wanted): ?Record
    {
        foreach ($foreignByKey[$key] ?? [] as $i => $candidate) {
            if ($candidate->sameValue($wanted)) {
                unset($foreignByKey[$key][$i]);
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param Record[] $records
     * @param string[] $skipTypes
     *
     * @return Record[]
     */
    private static function reject(array $records, array $skipTypes): array
    {
        return array_values(array_filter(
            $records,
            static fn (Record $record): bool => !in_array($record->type, $skipTypes, true)
        ));
    }

    /**
     * @param Record[] $records
     *
     * @return array<string,Record[]>
     */
    private static function groupByKey(array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->matchKey()][] = $record;
        }

        return $grouped;
    }
}

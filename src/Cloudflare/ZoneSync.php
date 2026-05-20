<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * The diff engine — the heart of the project.
 *
 * Given the records a control panel wants ("desired") and the records that
 * currently exist in a Cloudflare zone ("existing"), it computes a
 * {@see SyncPlan} that makes Cloudflare reflect the panel WITHOUT clobbering
 * Cloudflare-owned state (proxy/orange-cloud, comments, tags, page rules).
 *
 * The decisive rule: whenever a record can be matched by type + name, a value
 * change is emitted as an UPDATE (PATCH) rather than delete-and-recreate —
 * because recreating a record resets its proxy state, and updating does not.
 *
 * {@see plan()} is a pure function: no I/O, fully unit-testable.
 */
final class ZoneSync
{
    /**
     * @param Record[] $desired  records from the control panel
     * @param Record[] $existing records currently in the Cloudflare zone
     * @param array{
     *     prune?: bool,
     *     managedIds?: string[]|null,
     *     skipTypes?: string[]
     * } $options
     *   - prune:      delete Cloudflare records with no panel counterpart
     *                 (default true);
     *   - managedIds: when given, only Cloudflare records whose id is in this
     *                 list may be deleted — records created directly in
     *                 Cloudflare are then left untouched (default null =
     *                 manage everything);
     *   - skipTypes:  record types Cloudflare owns and we never touch
     *                 (default SOA + NS).
     */
    public static function plan(array $desired, array $existing, array $options = []): SyncPlan
    {
        $prune = $options['prune'] ?? true;
        $managedIds = $options['managedIds'] ?? null;
        $skipTypes = array_map('strtoupper', $options['skipTypes'] ?? ['SOA', 'NS']);

        $creates = [];
        $updates = [];
        $deletes = [];
        $unchanged = [];
        $ignored = [];

        // Records of a skipped type are always left exactly as they are.
        foreach ($existing as $record) {
            if (in_array($record->type, $skipTypes, true)) {
                $ignored[] = $record;
            }
        }

        $desiredByKey = self::groupByKey(self::reject($desired, $skipTypes));
        $existingByKey = self::groupByKey(self::reject($existing, $skipTypes));

        $allKeys = array_unique(array_merge(
            array_keys($desiredByKey),
            array_keys($existingByKey)
        ));

        foreach ($allKeys as $key) {
            $want = $desiredByKey[$key] ?? [];
            $have = $existingByKey[$key] ?? [];

            // Pass 1 — exact value matches: unchanged, or a TTL-only update.
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

            // Pass 2 — pair leftover want/have of the same key as UPDATES.
            // This is what preserves proxy state: a changed value becomes a
            // PATCH on the existing record, never a delete + recreate.
            $pairs = min(count($want), count($have));
            for ($i = 0; $i < $pairs; $i++) {
                $updates[] = new RecordUpdate($have[$i], $want[$i]);
            }

            // Pass 3 — surplus desired records: CREATE.
            for ($i = $pairs, $n = count($want); $i < $n; $i++) {
                $creates[] = $want[$i];
            }

            // Pass 4 — surplus existing records: DELETE, subject to prune rules.
            for ($i = $pairs, $n = count($have); $i < $n; $i++) {
                if (self::mayDelete($have[$i], $prune, $managedIds)) {
                    $deletes[] = $have[$i];
                } else {
                    $ignored[] = $have[$i];
                }
            }
        }

        return new SyncPlan($creates, $updates, $deletes, $unchanged, $ignored);
    }

    /**
     * @param string[]|null $managedIds
     */
    private static function mayDelete(Record $record, bool $prune, ?array $managedIds): bool
    {
        if (!$prune) {
            return false;
        }
        if ($managedIds === null) {
            return true; // full-mirror mode
        }

        // Only delete records we previously created/synced; leave anything
        // created directly in Cloudflare alone.
        return $record->id !== null && in_array($record->id, $managedIds, true);
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

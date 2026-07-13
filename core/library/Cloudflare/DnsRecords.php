<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * DNS record operations for a single Cloudflare zone, plus the executor that
 * applies a {@see SyncPlan}.
 */
final class DnsRecords
{
    private Client $client;
    private string $zoneId;

    public function __construct(Client $client, string $zoneId)
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '') {
            throw new ApiException('A Cloudflare zone id is required.');
        }

        $this->client = $client;
        $this->zoneId = self::assertId($zoneId, 'zone');
    }

    /**
     * Guard an id that gets interpolated into the request URL path against
     * path/query injection. Cloudflare ids are word characters; reject any
     * URL-structural character (`/ ? # :` whitespace, control bytes) and
     * dot-segments (`.` / `..` — the latter would retarget the request one
     * path level up, e.g. turning a record DELETE into a zone DELETE).
     * Defense in depth — ids originate from Cloudflare's own API responses,
     * but this keeps a crafted/compromised value from rewriting the path.
     * /D pins `$` to the true end of the string (PCRE otherwise lets a
     * trailing newline through).
     */
    private static function assertId(string $id, string $what): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)
            || strpos($id, '..') !== false
            || trim($id, '.') === ''
        ) {
            throw new ApiException(sprintf('Invalid Cloudflare %s id.', $what));
        }

        return $id;
    }

    /**
     * List every DNS record in the zone.
     *
     * @return Record[]
     */
    public function listAll(): array
    {
        $rows = $this->client->requestAll($this->base());

        $records = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $records[] = Record::fromCloudflare($row);
            }
        }

        return $records;
    }

    /**
     * Create a record (HTTP POST).
     *
     * Two deliberate choices:
     *  - `proxied` is NOT sent — the panel has no proxy concept, so a new
     *    record defaults to DNS-only (grey cloud); an operator can switch the
     *    orange cloud on in Cloudflare afterwards and later syncs preserve it.
     *  - `comment` is stamped with the ownership marker, so future syncs
     *    recognise the record as managed by this extension.
     */
    public function create(Record $record): Record
    {
        $payload = [
            'type' => $record->type,
            'name' => $record->name,
            'ttl' => $record->ttl,
            'comment' => Ownership::MARKER,
        ];
        if ($record->data !== null) {
            // SRV / CAA: Cloudflare represents the value as a structured object.
            $payload['data'] = $record->data;
        } else {
            $payload['content'] = $record->content;
            if ($record->priority !== null) {
                $payload['priority'] = $record->priority;
            }
        }

        $result = $this->client->request('POST', $this->base(), $payload);
        if (!is_array($result)) {
            throw new ApiException('Unexpected response creating record "' . $record->name . '".');
        }

        return Record::fromCloudflare($result);
    }

    /**
     * Update a record (HTTP PATCH — a partial update).
     *
     * PATCH only changes the fields supplied; every omitted field — crucially
     * `proxied`, plus `comment` and `tags` — keeps its current Cloudflare
     * value. This is what makes the sync non-destructive.
     */
    public function update(RecordUpdate $update): Record
    {
        $id = $update->existing->id;
        if ($id === null) {
            throw new ApiException('Cannot update a record without a Cloudflare id.');
        }
        self::assertId($id, 'record');

        $result = $this->client->request('PATCH', $this->base() . '/' . $id, $update->patchPayload());
        if (!is_array($result)) {
            throw new ApiException('Unexpected response updating record "' . $update->existing->name . '".');
        }

        return Record::fromCloudflare($result);
    }

    /**
     * Adopt a foreign record: stamp the ownership marker onto its comment so
     * future syncs treat it as managed. Only the `comment` is touched — any
     * human note already there is preserved.
     */
    public function claim(Record $record): void
    {
        if ($record->id === null) {
            throw new ApiException('Cannot adopt a record without a Cloudflare id.');
        }
        self::assertId($record->id, 'record');

        $this->client->request('PATCH', $this->base() . '/' . $record->id, [
            'comment' => Ownership::stamp($record->comment),
        ]);
    }

    /**
     * Delete a record (HTTP DELETE).
     */
    public function delete(Record $record): void
    {
        if ($record->id === null) {
            throw new ApiException('Cannot delete a record without a Cloudflare id.');
        }
        self::assertId($record->id, 'record');

        $this->client->request('DELETE', $this->base() . '/' . $record->id);
    }

    /**
     * Apply a sync plan.
     *
     * Order follows Cloudflare's own batch semantics — deletes, then updates,
     * then creates — which avoids transient "record will conflict" errors
     * (e.g. removing an A record so a CNAME can take its place). Adoptions run
     * last; they only stamp a comment.
     *
     * Each action is independent: a failure is recorded in the report and the
     * remaining actions still run.
     */
    public function apply(SyncPlan $plan): SyncReport
    {
        $report = new SyncReport();

        foreach ($plan->deletes as $record) {
            try {
                $this->delete($record);
                $report->deleted++;
            } catch (ApiException $e) {
                $report->addError('delete', $record->name, $e);
            }
        }

        foreach ($plan->updates as $update) {
            try {
                $this->update($update);
                $report->updated++;
            } catch (ApiException $e) {
                $report->addError('update', $update->existing->name, $e);
            }
        }

        foreach ($plan->creates as $record) {
            try {
                $report->createdRecords[] = $this->create($record);
                $report->created++;
            } catch (ApiException $e) {
                $report->addError('create', $record->name, $e);
            }
        }

        foreach ($plan->adopted as $record) {
            try {
                $this->claim($record);
                $report->adopted++;
            } catch (ApiException $e) {
                $report->addError('adopt', $record->name, $e);
            }
        }

        // $plan->conflicts is intentionally NOT iterated here: it carries
        // desired records suppressed at plan time because a foreign record
        // at the same name has an RFC 1034 §3.6.2-incompatible type. They
        // are informational only — surfaced in sync.log and the status row.

        return $report;
    }

    private function base(): string
    {
        return 'zones/' . $this->zoneId . '/dns_records';
    }
}

<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * The result of diffing the desired (panel) zone against the existing
 * (Cloudflare) zone. Pure data — produced by {@see ZoneSync::plan()},
 * consumed by {@see DnsRecords::apply()}.
 */
final class SyncPlan
{
    /** @var Record[] records to create in Cloudflare (HTTP POST) */
    public array $creates;

    /** @var RecordUpdate[] records to update in Cloudflare (HTTP PATCH) */
    public array $updates;

    /** @var Record[] managed records to delete from Cloudflare (HTTP DELETE) */
    public array $deletes;

    /** @var Record[] managed records already in the desired state — no API call */
    public array $unchanged;

    /** @var Record[] foreign / SOA / NS records left completely untouched */
    public array $ignored;

    /**
     * Foreign records that exactly match a desired record: taken under
     * management with no API call. The caller must add their ids to the
     * managed-id store.
     *
     * @var Record[]
     */
    public array $adopted;

    /**
     * @param Record[]       $creates
     * @param RecordUpdate[] $updates
     * @param Record[]       $deletes
     * @param Record[]       $unchanged
     * @param Record[]       $ignored
     * @param Record[]       $adopted
     */
    public function __construct(
        array $creates = [],
        array $updates = [],
        array $deletes = [],
        array $unchanged = [],
        array $ignored = [],
        array $adopted = []
    ) {
        $this->creates = $creates;
        $this->updates = $updates;
        $this->deletes = $deletes;
        $this->unchanged = $unchanged;
        $this->ignored = $ignored;
        $this->adopted = $adopted;
    }

    /** True when applying the plan would make no Cloudflare API calls. */
    public function isEmpty(): bool
    {
        return $this->creates === [] && $this->updates === [] && $this->deletes === [];
    }

    public function summary(): string
    {
        return sprintf(
            '%d to create, %d to update, %d to delete (%d unchanged, %d adopted, %d left untouched)',
            count($this->creates),
            count($this->updates),
            count($this->deletes),
            count($this->unchanged),
            count($this->adopted),
            count($this->ignored)
        );
    }
}

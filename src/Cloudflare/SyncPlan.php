<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * The result of diffing desired (panel) records against existing (Cloudflare)
 * records. Pure data — produced by {@see ZoneSync::plan()}, consumed by
 * {@see DnsRecords::apply()}.
 */
final class SyncPlan
{
    /** @var Record[] records to create in Cloudflare (HTTP POST) */
    public array $creates;

    /** @var RecordUpdate[] records to update in Cloudflare (HTTP PATCH) */
    public array $updates;

    /** @var Record[] records to delete from Cloudflare (HTTP DELETE) */
    public array $deletes;

    /** @var Record[] records already in the desired state — no API call */
    public array $unchanged;

    /** @var Record[] Cloudflare records left untouched (not panel-managed) */
    public array $ignored;

    /**
     * @param Record[]       $creates
     * @param RecordUpdate[] $updates
     * @param Record[]       $deletes
     * @param Record[]       $unchanged
     * @param Record[]       $ignored
     */
    public function __construct(
        array $creates = [],
        array $updates = [],
        array $deletes = [],
        array $unchanged = [],
        array $ignored = []
    ) {
        $this->creates = $creates;
        $this->updates = $updates;
        $this->deletes = $deletes;
        $this->unchanged = $unchanged;
        $this->ignored = $ignored;
    }

    /** True when applying the plan would make no API calls. */
    public function isEmpty(): bool
    {
        return $this->creates === [] && $this->updates === [] && $this->deletes === [];
    }

    public function summary(): string
    {
        return sprintf(
            '%d to create, %d to update, %d to delete (%d unchanged, %d left untouched)',
            count($this->creates),
            count($this->updates),
            count($this->deletes),
            count($this->unchanged),
            count($this->ignored)
        );
    }
}

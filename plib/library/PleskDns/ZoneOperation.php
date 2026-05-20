<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

use Noiz\CloudflareDns\Cloudflare\Record;

/**
 * One DNS operation parsed from Plesk's custom-DNS-backend payload.
 *
 * Plesk's `create` and `update` commands both deliver the complete desired
 * zone, so both normalise to {@see UPDATE} (a full reconcile). `delete` means
 * the zone was removed in Plesk.
 */
final class ZoneOperation
{
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /** Either self::UPDATE or self::DELETE. */
    public string $command;

    /** The zone (domain) name, lowercased, without a trailing dot. */
    public string $zoneName;

    /** @var Record[] the complete desired record set (empty for a DELETE) */
    public array $records;

    /**
     * @param Record[] $records
     */
    public function __construct(string $command, string $zoneName, array $records)
    {
        $this->command = $command;
        $this->zoneName = $zoneName;
        $this->records = $records;
    }
}

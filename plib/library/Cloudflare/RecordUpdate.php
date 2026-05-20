<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * A single planned update: change an existing Cloudflare record's value to
 * match what the control panel now wants.
 */
final class RecordUpdate
{
    /** The Cloudflare record — carries the record `id` and its proxy state. */
    public Record $existing;

    /** The desired record from the control panel — carries the new value. */
    public Record $desired;

    public function __construct(Record $existing, Record $desired)
    {
        $this->existing = $existing;
        $this->desired = $desired;
    }

    /**
     * The HTTP `PATCH` payload.
     *
     * This is a deliberately PARTIAL update — and that is the whole point of
     * the project:
     *
     *  - `content` (and `priority`, where relevant) carry the panel's change;
     *  - `ttl` is sent only when the record is NOT proxied — Cloudflare forces
     *    proxied records to TTL "auto" and rejects an explicit TTL;
     *  - `proxied` is NEVER sent. Omitting a field from a `PATCH` tells
     *    Cloudflare to leave it exactly as it is, so the orange-cloud state
     *    (and `comment`, `tags`, page-rule associations) survive untouched.
     *
     * @return array<string,mixed>
     */
    public function patchPayload(): array
    {
        $payload = ['content' => $this->desired->content];

        if ($this->desired->priority !== null) {
            $payload['priority'] = $this->desired->priority;
        }

        if ($this->existing->proxied !== true) {
            $payload['ttl'] = $this->desired->ttl;
        }

        return $payload;
    }
}

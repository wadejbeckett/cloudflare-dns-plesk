<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * An immutable DNS record value object.
 *
 * The same class represents both:
 *  - "desired" records, coming from the control panel — here `id`, `proxied`
 *    and `comment` are unknown (the panel has no Cloudflare concepts); and
 *  - "existing" records, coming from Cloudflare — fully populated.
 *
 * `SRV` and `CAA` records carry their structured fields in `data`
 * (`{priority,weight,port,target}` and `{flags,tag,value}`) — Cloudflare's API
 * represents those types as an object rather than a flat content string.
 */
final class Record
{
    /** Record types whose value is a structured `data` object, not `content`. */
    private const DATA_TYPES = ['SRV', 'CAA', 'TLSA'];

    public string $type;
    public string $name;
    public string $content;
    public int $ttl;
    public ?int $priority;
    public ?string $id;
    public ?bool $proxied;

    /** The Cloudflare record comment — carries the ownership marker. */
    public ?string $comment;

    /** Structured value for SRV/CAA records; null for every other type. */
    public ?array $data;

    public function __construct(
        string $type,
        string $name,
        string $content,
        int $ttl = 1,
        ?int $priority = null,
        ?string $id = null,
        ?bool $proxied = null,
        ?string $comment = null,
        ?array $data = null
    ) {
        $this->type = strtoupper(trim($type));
        $this->name = DnsName::normalise($name);
        $this->content = trim($content);
        $this->ttl = $ttl > 0 ? $ttl : 1;
        $this->priority = $priority;
        $this->id = $id;
        $this->proxied = $proxied;
        $this->comment = $comment;
        $this->data = $data;
    }

    /**
     * Build a Record from a Cloudflare API record object.
     *
     * @param array<string,mixed> $row
     */
    public static function fromCloudflare(array $row): self
    {
        $type = strtoupper(trim((string) ($row['type'] ?? '')));
        $isDataType = in_array($type, self::DATA_TYPES, true);

        return new self(
            $type,
            (string) ($row['name'] ?? ''),
            (string) ($row['content'] ?? ''),
            (int) ($row['ttl'] ?? 1),
            (!$isDataType && isset($row['priority'])) ? (int) $row['priority'] : null,
            isset($row['id']) ? (string) $row['id'] : null,
            array_key_exists('proxied', $row) ? (bool) $row['proxied'] : null,
            (isset($row['comment']) && $row['comment'] !== null) ? (string) $row['comment'] : null,
            ($isDataType && isset($row['data']) && is_array($row['data'])) ? $row['data'] : null
        );
    }

    /**
     * Identity key used to group and match records: type + name only.
     *
     * Content is deliberately excluded — a changed value must look like an
     * UPDATE of the existing record (which preserves proxy state), not a
     * delete-and-recreate (which would reset it).
     */
    public function matchKey(): string
    {
        return $this->type . '|' . $this->name;
    }

    /**
     * True when two records carry the same DNS value.
     *
     * For SRV/CAA the structured `data` is compared; for every other type it
     * is the content (plus priority for MX).
     */
    public function sameValue(self $other): bool
    {
        if ($this->usesData() || $other->usesData()) {
            return $this->normalisedData() === $other->normalisedData();
        }

        return $this->normalisedContent() === $other->normalisedContent()
            && $this->priority === $other->priority;
    }

    /**
     * True when this (a Cloudflare record) is already in the state the desired
     * record wants — i.e. no API call is needed at all.
     */
    public function matches(self $desired): bool
    {
        if (!$this->sameValue($desired)) {
            return false;
        }

        // Cloudflare forces proxied records to TTL "auto", so TTL is not a
        // meaningful difference for them.
        if ($this->proxied === true) {
            return true;
        }

        return $this->ttl === $desired->ttl;
    }

    /** True for SRV/CAA — types whose value is a structured `data` object. */
    private function usesData(): bool
    {
        return in_array($this->type, self::DATA_TYPES, true);
    }

    /**
     * Content normalised for comparison across Plesk and Cloudflare.
     */
    private function normalisedContent(): string
    {
        // TXT values are sometimes stored wrapped in double quotes.
        if ($this->type === 'TXT') {
            return trim($this->content, '"');
        }

        // Hostname targets are case-insensitive and may carry a trailing dot.
        if (in_array($this->type, ['CNAME', 'MX', 'NS', 'PTR'], true)) {
            return strtolower(rtrim($this->content, '.'));
        }

        return $this->content;
    }

    /**
     * The `data` object reduced to a canonical form, so two records can be
     * compared with `===` regardless of key order or formatting.
     *
     * @return array<string,int|string>
     */
    private function normalisedData(): array
    {
        $data = $this->data ?? [];

        if ($this->type === 'SRV') {
            $target = (string) ($data['target'] ?? '');

            return [
                'priority' => (int) ($data['priority'] ?? 0),
                'weight' => (int) ($data['weight'] ?? 0),
                'port' => (int) ($data['port'] ?? 0),
                // Keep an RFC 2782 "." target intact (see Payload::toRecord).
                'target' => $target === '.' ? '.' : DnsName::normalise($target),
            ];
        }

        if ($this->type === 'CAA') {
            return [
                'flags' => (int) ($data['flags'] ?? 0),
                'tag' => strtolower(trim((string) ($data['tag'] ?? ''))),
                'value' => trim((string) ($data['value'] ?? ''), " \t\""),
            ];
        }

        if ($this->type === 'TLSA') {
            return [
                'usage' => (int) ($data['usage'] ?? 0),
                'selector' => (int) ($data['selector'] ?? 0),
                'matching_type' => (int) ($data['matching_type'] ?? 0),
                'certificate' => strtolower(trim((string) ($data['certificate'] ?? ''))),
            ];
        }

        return [];
    }
}

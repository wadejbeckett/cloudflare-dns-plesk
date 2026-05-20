<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * An immutable DNS record value object.
 *
 * The same class represents both:
 *  - "desired" records, coming from the control panel — here `id`, `proxied`
 *    and `proxiable` are unknown (the panel has no Cloudflare concepts); and
 *  - "existing" records, coming from Cloudflare — fully populated.
 */
final class Record
{
    public string $type;
    public string $name;
    public string $content;
    public int $ttl;
    public ?int $priority;
    public ?string $id;
    public ?bool $proxied;
    public bool $proxiable;

    public function __construct(
        string $type,
        string $name,
        string $content,
        int $ttl = 1,
        ?int $priority = null,
        ?string $id = null,
        ?bool $proxied = null,
        bool $proxiable = false
    ) {
        $this->type = strtoupper(trim($type));
        $this->name = self::normaliseName($name);
        $this->content = trim($content);
        $this->ttl = $ttl > 0 ? $ttl : 1;
        $this->priority = $priority;
        $this->id = $id;
        $this->proxied = $proxied;
        $this->proxiable = $proxiable;
    }

    /**
     * Build a Record from a Cloudflare API record object.
     *
     * @param array<string,mixed> $row
     */
    public static function fromCloudflare(array $row): self
    {
        return new self(
            (string) ($row['type'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['content'] ?? ''),
            (int) ($row['ttl'] ?? 1),
            isset($row['priority']) ? (int) $row['priority'] : null,
            isset($row['id']) ? (string) $row['id'] : null,
            array_key_exists('proxied', $row) ? (bool) $row['proxied'] : null,
            (bool) ($row['proxiable'] ?? false)
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
     * True when two records carry the same DNS value (content + priority).
     */
    public function sameValue(self $other): bool
    {
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

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
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
        if (in_array($this->type, ['CNAME', 'MX', 'NS', 'SRV', 'PTR'], true)) {
            return strtolower(rtrim($this->content, '.'));
        }

        return $this->content;
    }

    private static function normaliseName(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }
}

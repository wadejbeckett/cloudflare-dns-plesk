<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Normalises a DNS name for consistent comparison.
 *
 * Lower-cased, trimmed, and with any trailing dot removed: Plesk delivers
 * fully-qualified names with a trailing dot, Cloudflare stores them without
 * one, and DNS names are case-insensitive.
 */
final class DnsName
{
    public static function normalise(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }
}

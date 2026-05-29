<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Cloudflare;

/**
 * Record ownership marker.
 *
 * Every record this extension creates in Cloudflare is stamped — in the
 * record's `comment` field — with a marker. A record counts as "managed" by
 * this extension when its comment carries the marker; anything else is
 * "foreign" and is never modified or deleted.
 *
 * Keeping ownership ON the Cloudflare record (rather than in a separate
 * Plesk-side store) means it survives an extension reinstall or a Plesk
 * migration, and is visible to anyone looking at the Cloudflare dashboard.
 */
final class Ownership
{
    /** The marker. Cloudflare record comments are free text (~100 char max). */
    public const MARKER = '[plesk-dns-sync]';

    /** True when a Cloudflare record's comment marks the record as ours. */
    public static function isManaged(?string $comment): bool
    {
        return $comment !== null && strpos($comment, self::MARKER) !== false;
    }

    /**
     * Return a comment that carries the marker, preserving any human-written
     * note already present.
     */
    public static function stamp(?string $comment): string
    {
        $comment = $comment !== null ? trim($comment) : '';

        if ($comment === '') {
            return self::MARKER;
        }
        if (self::isManaged($comment)) {
            return $comment;
        }

        return self::MARKER . ' ' . $comment;
    }
}

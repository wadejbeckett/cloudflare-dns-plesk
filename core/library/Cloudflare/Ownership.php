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
    /**
     * The marker written into every managed record's comment. Panel-neutral,
     * so the same core backs the Plesk, DirectAdmin and future panel adapters.
     * Cloudflare record comments are free text (~100 char max).
     */
    public const MARKER = '[noiz-dns-sync]';

    /**
     * Markers written by earlier versions. Still recognised as "ours" on read,
     * so records stamped before the rename are never orphaned (mis-read as
     * foreign). Detection only — writes always use {@see self::MARKER}, and
     * existing comments are left untouched, so no migration is required.
     *
     * @var string[]
     */
    private const LEGACY_MARKERS = ['[plesk-dns-sync]'];

    /** True when a Cloudflare record's comment marks the record as ours. */
    public static function isManaged(?string $comment): bool
    {
        if ($comment === null) {
            return false;
        }
        if (strpos($comment, self::MARKER) !== false) {
            return true;
        }
        foreach (self::LEGACY_MARKERS as $legacy) {
            if (strpos($comment, $legacy) !== false) {
                return true;
            }
        }
        return false;
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

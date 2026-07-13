<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\PleskDns;

/**
 * Open a per-domain advisory-lock file in a way that survives a cross-uid
 * race — when the file was previously created by one user (e.g. an admin
 * who manually ran the sync as root) and another (e.g. the scheduler
 * running as psaadm) needs to take the lock.
 *
 * flock() works on any open file descriptor on Linux, including a
 * read-only one, so a write-mode open is preferred but a read-mode
 * fallback gets the lock without requiring write access to the file.
 */
final class LockFile
{
    /**
     * Open `$path` and return a resource ready for flock().
     *
     * @throws \RuntimeException when the file cannot be opened at all
     *                           (var dir missing, permission denied on the
     *                           directory, filesystem full).
     *
     * @return resource
     */
    public static function open(string $path)
    {
        // Preferred path: write-mode 'c' creates the file if missing and
        // never truncates — exactly what we want on first sync. The umask
        // bracket makes a NEW file be born 0660 — without it the file exists
        // as 0644 (default umask 022) for the window before the chmod below,
        // and a local user who opens it in that window keeps a lockable fd
        // forever (flock works on read-only fds).
        $previousUmask = umask(0117);
        $fp = @fopen($path, 'c');
        umask($previousUmask);
        if ($fp === false && file_exists($path)) {
            // File exists but we lack write access (created under a
            // different uid). flock works on a read-only fd too.
            $fp = @fopen($path, 'r');
        }
        if ($fp === false) {
            throw new \RuntimeException(sprintf(
                "Unable to open lockfile at '%s'.",
                $path
            ));
        }

        // Best-effort hardening: 0660, NOT 0666 — a world-accessible lock on
        // a predictable path lets any local user open it and grab LOCK_EX,
        // silently wedging every sync (flock works even on a read-only fd).
        //
        // 0660 alone would break the cross-uid case this class exists for: a
        // root-created lock is group root, which psaadm cannot open at all.
        // Aligning the file's group with the var dir's group (psaadm on
        // Plesk) keeps the scheduler able to open it in write mode while
        // still excluding everyone else. Both calls silently no-op when we
        // don't own the file — fine, because we already hold an fd to flock.
        $dirGroup = @filegroup(dirname($path));
        if ($dirGroup !== false) {
            @chgrp($path, $dirGroup);
        }
        @chmod($path, 0660);

        return $fp;
    }
}

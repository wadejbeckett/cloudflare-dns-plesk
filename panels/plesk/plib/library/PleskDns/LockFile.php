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
        // never truncates — exactly what we want on first sync.
        $fp = @fopen($path, 'c');
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

        // Best-effort: future runs under any uid in the same group should
        // be able to take the lock without falling back to read mode.
        // chmod() silently fails if we don't own the file — which is fine
        // because we already have an fd we can flock.
        @chmod($path, 0666);

        return $fp;
    }
}

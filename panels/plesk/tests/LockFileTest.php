<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\PleskDns\LockFile;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cross-uid resilient lockfile opener.
 *
 * Background (v0.5.10 fix): a per-domain `sync-<domain>.lock` created on
 * dev-plesk under uid `root` (manual sync) blocked the psaadm-owned cron
 * because mode 'c' fopen requires write access. The fix falls back to
 * read-mode fopen on an existing file — flock() works on any fd on Linux.
 * Pure — no Plesk runtime needed.
 */
final class LockFileTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cfdns-lockfile-test-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            self::fail("Could not create temp dir '$base'");
        }
        $this->tmpDir = $base;
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $this->tmpDir . '/' . $entry;
            // Restore writeable mode so unlink() succeeds on files we
            // chmodded to 0 / 0444 during a test.
            @chmod($full, 0666);
            @unlink($full);
        }
        @rmdir($this->tmpDir);
    }

    public function testFreshOpenCreatesFileAndReturnsResource(): void
    {
        $path = $this->tmpDir . '/fresh.lock';
        self::assertFileDoesNotExist($path);

        $fp = LockFile::open($path);

        self::assertIsResource($fp);
        self::assertFileExists($path);

        // chmod 0660 ran (we own the freshly created file), so the group bit
        // is set and — crucially — the world bits are clear. A world-writable
        // lock on a predictable path is a local DoS vector (any user could
        // grab LOCK_EX and wedge every sync), which is what 0660 prevents.
        $mode = fileperms($path) & 0777;
        self::assertGreaterThan(0, $mode & 0060, 'lock file should be group-accessible');
        self::assertSame(0, $mode & 0007, 'lock file must not be world-accessible');

        fclose($fp);
    }

    public function testExistingReadOnlyFileFallsBackToReadMode(): void
    {
        $path = $this->tmpDir . '/readonly.lock';
        touch($path);
        chmod($path, 0444);

        $fp = LockFile::open($path);

        self::assertIsResource(
            $fp,
            'read-mode fallback must succeed on a file with no write bits'
        );

        fclose($fp);
    }

    public function testChmodZeroFileThrows(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root bypasses POSIX permission checks; chmod 0 is openable as root');
        }

        $path = $this->tmpDir . '/locked.lock';
        touch($path);
        chmod($path, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unable to open lockfile at '$path'.");

        try {
            LockFile::open($path);
        } finally {
            // Restore for tearDown.
            @chmod($path, 0666);
        }
    }

    public function testMissingParentDirectoryThrows(): void
    {
        $path = '/no/such/dir/foo.lock';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unable to open lockfile at '$path'.");

        LockFile::open($path);
    }

    public function testSubsequentOpensOnOwnedFileSucceed(): void
    {
        $path = $this->tmpDir . '/repeat.lock';

        $fp1 = LockFile::open($path);
        self::assertIsResource($fp1);
        fclose($fp1);

        $fp2 = LockFile::open($path);
        self::assertIsResource($fp2, 'second open under the same uid must succeed');
        fclose($fp2);

        $fp3 = LockFile::open($path);
        self::assertIsResource($fp3, 'third open under the same uid must succeed');
        fclose($fp3);
    }
}

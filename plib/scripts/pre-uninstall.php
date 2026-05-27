<?php

declare(strict_types=1);

/**
 * Schedules a deferred custom-DNS-backend disable.
 *
 * Plesk extensions have no separate upgrade hook — every in-place upgrade
 * re-uses pre-uninstall + post-install. Disabling the backend immediately
 * here would briefly unregister us on every upgrade and rely on the new
 * post-install to put it back. A failure in that window leaves the
 * extension installed but invisible to Plesk's DNS dispatch (the
 * 2026-05-25 → 2026-05-27 incident on neo, 49 hours of silent failure).
 *
 * Instead: drop a self-deleting shell script in /tmp that polls for our
 * handler's return. After pre-uninstall completes Plesk either swaps in
 * the new version's files (the handler reappears) or removes the
 * extension. The deferred script watches for up to 2 minutes:
 *   - handler reappears → upgrade completed, stand down
 *   - 2 min elapsed and handler still gone → true uninstall; disable the
 *     custom backend so Plesk falls back to its built-in DNS.
 *
 * The registered handler string (`extension --exec cloudflare-dns-sync
 * sync-backend.php`) is stable across versions, so the existing
 * registration keeps working through the file swap — there is no need to
 * unregister-then-re-register on every upgrade.
 */

$handlerPath = __DIR__ . '/sync-backend.php';
$checkScript = '/tmp/cfdns-defer-disable-' . posix_getpid() . '.sh';

$body = <<<SH
#!/bin/sh
HANDLER="$handlerPath"
DEADLINE=\$((\$(date +%s) + 120))
while [ \$(date +%s) -lt \$DEADLINE ]; do
    if [ -f "\$HANDLER" ]; then
        rm -f "$checkScript"
        exit 0
    fi
    sleep 2
done
plesk bin server_dns --disable-custom-backend > /dev/null 2>&1
rm -f "$checkScript"
SH;

if (@file_put_contents($checkScript, $body) === false) {
    echo "Cloudflare DNS Sync: could not schedule deferred backend cleanup.\n";
    echo "If you are uninstalling (not upgrading), run this once Plesk has\n";
    echo "removed the extension:\n";
    echo "    plesk bin server_dns --disable-custom-backend\n";
    return;
}

@chmod($checkScript, 0755);
@exec('nohup ' . escapeshellarg($checkScript) . ' > /dev/null 2>&1 &');

<?php

declare(strict_types=1);

/**
 * Registers this extension as Plesk's custom DNS backend, so Plesk routes
 * every DNS zone change through plib/scripts/sync-backend.php.
 *
 * Note: Plesk has a single custom-DNS-backend slot. Installing this extension
 * takes that slot; another DNS-backend extension (e.g. the official Cloudflare
 * extension) must not be registered at the same time.
 *
 * Side-effect handling: Plesk auto-disables the `slave-dns-manager` extension
 * when this extension's custom DNS backend is first registered — it assumes
 * DNS now lives at a third party and that BIND-slave management is no longer
 * applicable. But sync is *per-domain*: non-activated domains still rely on
 * local BIND and need to replicate to any configured secondaries (including
 * for Let's Encrypt DNS-01 challenges resolved against those slaves). So we
 * preserve the operator's prior intent: read slave-dns-manager's state BEFORE
 * we register, and restore it AFTER if our registration knocked it off. We
 * do NOT enable unconditionally — if the admin disabled it deliberately,
 * leaving it disabled is correct.
 */

// Capture slave-dns-manager state before our registration touches Plesk's
// DNS configuration. If the extension isn't installed at all, getById
// throws — treat that as "nothing to preserve".
$slaveDnsWasEnabled = false;
try {
    $slaveDnsWasEnabled = pm_Extension::getById('slave-dns-manager')->isActive();
} catch (pm_Exception $e) {
    // slave-dns-manager not installed on this Plesk — nothing to preserve.
}

$extensionBinary = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
    ? '"' . PRODUCT_ROOT . '\\bin\\extension.exe"'
    : '"' . PRODUCT_ROOT . '/bin/extension"';

$handler = $extensionBinary . ' --exec ' . pm_Context::getModuleId() . ' sync-backend.php';

try {
    pm_ApiCli::call('server_dns', ['--enable-custom-backend', $handler]);
} catch (pm_Exception $e) {
    echo 'Failed to register the Cloudflare DNS backend: ' . $e->getMessage() . "\n";
    exit(1);
}

// Restore slave-dns-manager if we knocked it offline. Only re-enable when
// it was on before — we never enable an extension the admin had off.
if ($slaveDnsWasEnabled) {
    try {
        $slave = pm_Extension::getById('slave-dns-manager');
        if (!$slave->isActive()) {
            pm_ApiCli::call('extension', ['--enable', 'slave-dns-manager']);
        }
    } catch (pm_Exception $e) {
        // slave-dns-manager has become unqueryable since we captured state.
        // Nothing more we can do here — fail open rather than abort install.
    }
}

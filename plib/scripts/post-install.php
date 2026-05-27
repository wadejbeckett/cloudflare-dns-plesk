<?php

declare(strict_types=1);

/**
 * Registers this extension as Plesk's custom DNS backend, so Plesk routes
 * every DNS zone change through plib/scripts/sync-backend.php.
 *
 * Plesk has a single exclusive custom-DNS-backend slot. Installing this
 * extension claims it. The most common collision is with `slave-dns-manager`,
 * which also wants the slot — they cannot both register, so taking it from
 * slave-dns-manager will mark it disabled in Plesk's Modules table.
 *
 * Earlier versions tried to re-enable slave-dns-manager here as a courtesy.
 * That created a registration cycle (slave-dns-manager re-enables → re-takes
 * the slot → evicts us → next event isn't routed to us). The right shape
 * is to leave slave-dns-manager's enable state alone, and let the admin
 * configure the optional pass-through handler (see Settings) to forward
 * each event to slave-dns-manager's handler too — so both keep functioning
 * regardless of which one Plesk marks "enabled".
 *
 * Long-term fix is the v0.5.0 polling backend that frees the slot
 * entirely — see _internal/AUDIT… and the v0.5.0 design doc.
 */

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

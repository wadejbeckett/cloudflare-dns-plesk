<?php

declare(strict_types=1);

/**
 * Registers this extension as Plesk's custom DNS backend, so Plesk routes
 * every DNS zone change through plib/scripts/sync-backend.php.
 *
 * Note: Plesk has a single custom-DNS-backend slot. Installing this extension
 * takes that slot; another DNS-backend extension (e.g. the official Cloudflare
 * extension) must not be registered at the same time.
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

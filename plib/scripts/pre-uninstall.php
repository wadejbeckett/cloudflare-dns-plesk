<?php

declare(strict_types=1);

/**
 * Deregisters the custom DNS backend, so Plesk returns to its normal DNS
 * behaviour when this extension is removed.
 */

try {
    pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
} catch (pm_Exception $e) {
    echo 'Failed to deregister the Cloudflare DNS backend: ' . $e->getMessage() . "\n";
    exit(1);
}

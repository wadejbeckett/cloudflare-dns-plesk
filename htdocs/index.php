<?php

/**
 * Web entry point for the Cloudflare DNS Sync extension.
 *
 * Plesk routes /modules/cloudflare-dns-sync/ to this file; pm_Application
 * dispatches the request to the controllers in plib/controllers/.
 */

pm_Context::init('cloudflare-dns-sync');

$application = new pm_Application();
$application->run();

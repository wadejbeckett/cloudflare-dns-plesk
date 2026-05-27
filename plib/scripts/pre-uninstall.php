<?php

declare(strict_types=1);

/**
 * Removes the recurring poll task installed by post-install.php and
 * releases the custom-DNS-backend slot if a previous (v0.4.x) install
 * had claimed it.
 *
 * On upgrade, post-install rewrites the scheduled task, so removing it
 * here is safe across the upgrade lifecycle (deletion → file swap →
 * post-install re-creates).
 */

$scheduler = pm_Scheduler::getInstance();
foreach ($scheduler->listTasks() as $task) {
    try {
        $scheduler->removeTask($task);
    } catch (\Throwable $e) {
        // Best-effort cleanup — continue.
    }
}

// Defensive: release the Plesk custom-DNS-backend slot if a previous
// v0.4.x version had claimed it. We never claim it in v0.5.0+, but a
// long-lived install upgrading from v0.4.x may still be holding it.
try {
    pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
} catch (pm_Exception $e) {
    // No backend registered — nothing to release.
}

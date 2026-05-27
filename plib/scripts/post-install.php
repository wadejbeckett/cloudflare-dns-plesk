<?php

declare(strict_types=1);

/**
 * Registers a recurring poll task via Plesk's task scheduler.
 *
 * v0.5.0 deliberately does NOT register as Plesk's custom DNS backend
 * (`server_dns --enable-custom-backend`). That slot is a single
 * exclusive resource; taking it evicts other DNS-backend extensions
 * — most importantly Plesk's `slave-dns-manager`, which uses the slot
 * to drive BIND slave replication. Earlier versions (v0.4.x) registered
 * for the slot and broke slave replication for every domain on the
 * server.
 *
 * This extension now polls Plesk's DNS state on a schedule via
 * `pm_Scheduler`. Default cadence: every 5 minutes. The slot stays
 * free for any other DNS-backend extension to use.
 */

$scheduler = pm_Scheduler::getInstance();

// Remove any prior scheduled tasks from this module before adding the new
// one — so an upgrade or reinstall doesn't accumulate duplicates.
foreach ($scheduler->listTasks() as $existing) {
    try {
        $scheduler->removeTask($existing);
    } catch (\Throwable $e) {
        // Best-effort cleanup — continue.
    }
}

// pm_Scheduler_Task::setCmd() resolves to the module's scripts/ directory,
// so pass just the script name — Plesk wraps it as
// `php -dauto_prepend_file=sdk.php scripts/sync-poll.php` which gives us
// the Plesk SDK already bootstrapped at runtime.
$task = new pm_Scheduler_Task();
$task->setCmd('sync-poll.php');
$task->setSchedule(pm_Scheduler::$EVERY_5_MIN);

try {
    $scheduler->putTask($task);
} catch (\Throwable $e) {
    echo "Failed to register the Cloudflare DNS Sync scheduled task: " . $e->getMessage() . "\n";
    echo "The extension is installed but will not poll automatically. ";
    echo "Use the resync buttons in the UI to trigger syncs manually.\n";
    exit(1);
}

// Defensive: release the Plesk custom-DNS-backend slot if a previous
// v0.4.x version had claimed it. Without this, an upgrade from v0.4.x
// would leave us holding the slot AND scheduled to poll — duplicating
// the work and (worse) keeping slave-dns-manager broken.
try {
    pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
} catch (pm_Exception $e) {
    // Already released or never claimed — fine.
}

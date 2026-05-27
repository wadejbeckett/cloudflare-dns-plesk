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
//
// EVERY_MIN is the smallest preset Plesk's scheduler exposes. The poll is
// cheap (one CF zone-read per activated domain per cycle), so per-minute
// gives near-real-time sync without hitting Cloudflare's API rate limits
// for any realistic activated-domain count.
$task = new pm_Scheduler_Task();
$task->setCmd('sync-poll.php');
$task->setSchedule(pm_Scheduler::$EVERY_MIN);

try {
    $scheduler->putTask($task);
} catch (\Throwable $e) {
    echo "Failed to register the Cloudflare DNS Sync scheduled task: " . $e->getMessage() . "\n";
    echo "The extension is installed but will not poll automatically. ";
    echo "Use the resync buttons in the UI to trigger syncs manually.\n";
    exit(1);
}

// We deliberately do NOT touch the custom-DNS-backend slot here. Earlier
// versions called `server_dns --disable-custom-backend` defensively to
// release the slot, but that turned out to be a hidden trap: when
// slave-dns-manager (or any other DNS-backend extension) currently
// holds the slot, our blanket "disable" silently evicts THEM and Plesk
// auto-disables their extension as a side effect — exactly the
// regression v0.5.x is supposed to prevent.
//
// If a previous v0.4.x install of this extension is still holding the
// slot at upgrade time, the admin can release it manually with one
// command — see README's "Upgrading from v0.4.x" section. We don't do
// it for them because we cannot distinguish "we still hold the slot"
// from "slave-dns-manager has the slot."

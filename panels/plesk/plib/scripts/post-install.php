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
 * `pm_Scheduler`. Default cadence: every minute (pm_Scheduler::$EVERY_MIN).
 * The slot stays free for any other DNS-backend extension to use.
 */

$scheduler = pm_Scheduler::getInstance();

// The scheduled tasks this module wants, keyed by the script they run:
//   sync-poll.php — the every-minute reconcile (cheap: one CF zone-read per
//     activated domain per cycle, so per-minute gives near-real-time sync
//     without straining Cloudflare's rate limits at any realistic domain count).
//   watchdog.php  — every 5 minutes; alerts if the poll heartbeat goes stale
//     (guards the silent-scheduler failure mode behind the 2026-05-25 outage).
// pm_Scheduler_Task::setCmd() resolves the name against the module's scripts/
// dir; Plesk wraps it as `php -dauto_prepend_file=sdk.php scripts/<cmd>` so the
// SDK is bootstrapped at runtime.
$wanted = [
    'sync-poll.php' => pm_Scheduler::$EVERY_MIN,
    'watchdog.php'  => pm_Scheduler::$EVERY_5_MIN,
];

// Reconcile idempotently rather than "remove everything then re-add". An
// upgrade/reinstall re-runs this with the previous tasks still registered;
// we KEEP each wanted task that already exists (so re-adding the poll never
// deletes the sibling watchdog), and remove only strays and duplicates. This
// converges to exactly one of each — no accumulation (the outage class) and no
// collateral deletion. NOTE: because matching tasks are left untouched, a
// future *schedule* change for an existing cmd must force-remove it first.
$have = [];
foreach ($scheduler->listTasks() as $existing) {
    $cmd = method_exists($existing, 'getCmd') ? (string) $existing->getCmd() : '';
    if (!isset($wanted[$cmd]) || isset($have[$cmd])) {
        try {
            $scheduler->removeTask($existing);
        } catch (\Throwable $e) {
            // Best-effort cleanup — continue. Log to STDERR so an operator can
            // see why a stale task wasn't removed (e.g. permissions).
            $taskId = method_exists($existing, 'getId') ? (string) $existing->getId() : 'unknown';
            fwrite(STDERR, "Failed to remove stale task '{$taskId}': {$e->getMessage()}\n");
        }
        continue;
    }
    $have[$cmd] = true;
}

foreach ($wanted as $cmd => $schedule) {
    if (isset($have[$cmd])) {
        continue; // already registered — leave it untouched
    }
    $task = new pm_Scheduler_Task();
    $task->setCmd($cmd);
    $task->setSchedule($schedule);
    try {
        $scheduler->putTask($task);
    } catch (\Throwable $e) {
        echo "Failed to register the Cloudflare DNS Sync '{$cmd}' task: " . $e->getMessage() . "\n";
        echo "The extension is installed but may not poll/monitor automatically. ";
        echo "Use the resync buttons in the UI to trigger syncs manually.\n";
        exit(1);
    }
}

// Seed the watchdog heartbeat so a fresh install has a baseline: the
// every-minute poll refreshes it within ~60s, and if the poll never runs the
// seeded value ages out and the watchdog fires (catching "poll never started").
// Only seed when absent, so an upgrade preserves the real last-run time.
if ((string) pm_Settings::get('last_poll_ts', '') === '') {
    pm_Settings::set('last_poll_ts', (string) time());
}

// One-off permission sweep on upgrade (v0.5.19 hardening): lock files and
// sync.log generations created by earlier versions are world-accessible
// (0666/0644), which lets any local user flock a lock and stall the sync,
// or read the log. New files are born 0660 group-aligned (see LockFile);
// this repairs the ones that already exist. post-install runs privileged,
// so it can fix root-owned leftovers the psaadm poll can never repair.
$varDir = rtrim(pm_Context::getVarDir(), '/');
$varDirGroup = @filegroup($varDir);
$legacyFiles = array_merge(
    glob($varDir . '/*.lock') ?: [],
    glob($varDir . '/sync.log*') ?: []
);
foreach ($legacyFiles as $legacyFile) {
    if ($varDirGroup !== false) {
        @chgrp($legacyFile, $varDirGroup);
    }
    @chmod($legacyFile, 0660);
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
// command. We don't do it for them because we cannot distinguish "we
// still hold the slot" from "slave-dns-manager has the slot."

<?php

declare(strict_types=1);

/**
 * Removes the recurring scheduled tasks (the every-minute poll and the
 * watchdog) installed by post-install.php — it clears every task this
 * module holds, so both go.
 *
 * On upgrade, post-install re-reconciles the scheduled tasks, so removing
 * them here is safe across the upgrade lifecycle (deletion → file swap →
 * post-install re-creates).
 *
 * Deliberately does NOT touch Plesk's custom-DNS-backend slot — see the
 * inline note below for why an upgrade from v0.4.x leaves the slot to
 * the admin.
 */

$scheduler = pm_Scheduler::getInstance();
foreach ($scheduler->listTasks() as $task) {
    try {
        $scheduler->removeTask($task);
    } catch (\Throwable $e) {
        // Best-effort cleanup — continue.
    }
}

// We do NOT touch the custom-DNS-backend slot. v0.5.0+ never claims it,
// so on uninstall there is nothing of OURS to release — but calling
// `--disable-custom-backend` blindly would evict whoever else is in the
// slot (notably slave-dns-manager) and Plesk would auto-disable that
// extension as a side effect. Admins upgrading from v0.4.x and then
// uninstalling can release the slot manually with one command — see
// README. The downside of leaving it alone: if v0.4.x had claimed it
// and we never released, the slot points at a now-deleted handler. The
// admin's fix: `plesk bin server_dns --disable-custom-backend`.

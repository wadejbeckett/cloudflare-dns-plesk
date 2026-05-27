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

// We do NOT touch the custom-DNS-backend slot. v0.5.0+ never claims it,
// so on uninstall there is nothing of OURS to release — but calling
// `--disable-custom-backend` blindly would evict whoever else is in the
// slot (notably slave-dns-manager) and Plesk would auto-disable that
// extension as a side effect. Admins upgrading from v0.4.x and then
// uninstalling can release the slot manually with one command — see
// README. The downside of leaving it alone: if v0.4.x had claimed it
// and we never released, the slot points at a now-deleted handler. The
// admin's fix: `plesk bin server_dns --disable-custom-backend`.

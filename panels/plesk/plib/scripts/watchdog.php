<?php

declare(strict_types=1);

/**
 * Watchdog — alerts when the scheduled poll has gone silent.
 *
 * Registered by post-install.php as its own pm_Scheduler task (every 5
 * minutes), independent of the every-minute poll. The poll writes a
 * `last_poll_ts` heartbeat at the end of each scheduled cycle; this script
 * checks how stale that heartbeat is. If the extension is configured (token +
 * at least one activated domain) and the heartbeat is older than the staleness
 * threshold, the scheduler task has stopped running — exactly the silent
 * failure mode behind the 2026-05-25 49-hour outage — so we raise a flag for
 * the settings page and email the administrator.
 *
 * Alerts are debounced (at most one email per re-alert window) but DO repeat
 * while the poll stays down, because the failure it guards against is one that
 * previously went unnoticed for days.
 */

pm_Loader::registerAutoload();
pm_Context::init('cloudflare-dns-sync');

const CFDNS_WD_STALE_SECONDS   = 600;    // 10 min ≈ 10 missed every-minute cycles
const CFDNS_WD_REALERT_SECONDS = 21600;  // re-email at most every 6 h while still down

// Only meaningful once configured. An unconfigured or fully-deactivated
// extension has nothing to poll, so silence is correct, not a fault.
$token = trim((string) pm_Settings::get('api_token'));
$enabled = json_decode((string) pm_Settings::get('enabled_domains', '[]'), true);
if ($token === '' || !is_array($enabled) || $enabled === []) {
    exit(0);
}

$lastPoll = (int) pm_Settings::get('last_poll_ts', '0');
$age = time() - $lastPoll;

if ($age <= CFDNS_WD_STALE_SECONDS) {
    // Healthy. Clear any prior stall state so the settings-page banner and the
    // alert debounce reset for next time.
    if ((string) pm_Settings::get('sync_stalled', '') !== '') {
        pm_Settings::set('sync_stalled', '');
        pm_Settings::set('last_alert_ts', '');
    }
    exit(0);
}

// Stale: the poll is not running. Record it for the settings-page banner
// (store when the last good poll was, so the UI can show "silent since …").
pm_Settings::set('sync_stalled', (string) $lastPoll);

// Debounce the email so a multi-hour outage doesn't mail every 5 minutes.
$lastAlert = (int) pm_Settings::get('last_alert_ts', '0');
if (time() - $lastAlert < CFDNS_WD_REALERT_SECONDS) {
    exit(0);
}

$recipient = trim((string) pm_Settings::get('alert_email', ''));
if ($recipient === '') {
    try {
        $recipient = trim((string) pm_Client::getAdmin()->getProperty('email'));
    } catch (\Throwable $e) {
        $recipient = '';
    }
}

if ($recipient !== '') {
    $host = gethostname() ?: 'this server';
    $mins = (int) round($age / 60);
    $lastStr = $lastPoll > 0 ? date('Y-m-d H:i:s', $lastPoll) : 'never recorded';
    $subject = 'Cloudflare DNS Sync: scheduled sync appears stalled on ' . $host;
    $body = "The Cloudflare DNS Sync poll on {$host} has not run for about {$mins} minutes.\n\n"
        . "Last completed poll: {$lastStr}\n"
        . "Activated domains: " . count($enabled) . "\n\n"
        . "While this persists, activated domains are NOT being reconciled with Cloudflare.\n\n"
        . "Check on the server:\n"
        . "  crontab -l -u psaadm | grep sync-poll      # expect exactly one entry\n"
        . "  tail -n 50 " . rtrim(pm_Context::getVarDir(), '/') . "/sync.log\n\n"
        . "This is an automated message from the Cloudflare DNS Sync extension.\n";
    // Defang header-injection via the host string; mail() From header only.
    $from = 'cloudflare-dns-sync@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);
    @mail($recipient, $subject, $body, 'From: ' . $from);
}

// Record the attempt regardless of whether a recipient was resolved, so the
// debounce holds even when no admin email is configured.
pm_Settings::set('last_alert_ts', (string) time());

exit(0);

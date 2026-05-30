<?php

declare(strict_types=1);

/**
 * Poll-mode DNS reconciler — the heart of this extension's sync path.
 *
 * Runs on Plesk's task scheduler (post-install registers a pm_Scheduler
 * task that invokes this script every minute). Reads each activated
 * domain's current DNS state from Plesk via the SDK, runs the diff
 * engine against Cloudflare, and applies the plan.
 *
 * The on-demand Resync buttons in the admin UI also invoke this script
 * directly (with the domain as argv[1]) for an immediate single-zone
 * sync without waiting for the next scheduled cycle.
 *
 * This extension does NOT register as Plesk's custom DNS backend —
 * that slot is exclusive and we leave it free for other DNS-backend
 * extensions (e.g. slave-dns-manager) to use.
 */

use Noiz\CloudflareDns\Cloudflare\ApiException;
use Noiz\CloudflareDns\Cloudflare\Client;
use Noiz\CloudflareDns\Cloudflare\DnsRecords;
use Noiz\CloudflareDns\Cloudflare\Ownership;
use Noiz\CloudflareDns\Cloudflare\ZoneSync;
use Noiz\CloudflareDns\Cloudflare\Zones;
use Noiz\CloudflareDns\PleskDns\AutoEnable;
use Noiz\CloudflareDns\PleskDns\LockFile;
use Noiz\CloudflareDns\PleskDns\SyncableDomain;
use Noiz\CloudflareDns\PleskDns\ZoneReader;

pm_Loader::registerAutoload();
pm_Context::init('cloudflare-dns-sync');

require_once __DIR__ . '/../library/autoload.php';

function cfdns_poll_log(string $message): void
{
    // Defang CR/LF/NUL so a hostile or faulty upstream string (e.g. a
    // Cloudflare error message with embedded newlines) cannot forge log lines.
    $message = strtr($message, ["\r" => ' ', "\n" => ' ', "\0" => '']);
    fwrite(STDOUT, 'cloudflare-dns-sync-poll: ' . $message . "\n");
    @file_put_contents(
        rtrim(pm_Context::getVarDir(), '/') . '/sync.log',
        '[' . date('Y-m-d H:i:s') . '] [poll] ' . $message . "\n",
        FILE_APPEND
    );
}

/**
 * Roll sync.log when it grows past a size cap, keeping two prior generations
 * (sync.log.1, sync.log.2). Self-contained in the extension's var dir — no
 * /etc/logrotate.d file to leave behind on uninstall, and it ports to any
 * future panel adapter. Called once per scheduled cycle under the run lock,
 * so generations never race with a concurrent cycle.
 */
function cfdns_poll_rotate_log(): void
{
    $log = rtrim(pm_Context::getVarDir(), '/') . '/sync.log';
    if (!is_file($log) || (int) @filesize($log) < 5 * 1024 * 1024) {
        return;
    }
    // Shift generations oldest-first, then the current log becomes .1. The
    // next cfdns_poll_log() append recreates a fresh sync.log.
    @rename($log . '.1', $log . '.2');
    @rename($log, $log . '.1');
}

function cfdns_poll_enabled_domains(): array
{
    $list = json_decode((string) pm_Settings::get('enabled_domains', '[]'), true);
    return is_array($list) ? $list : [];
}

function cfdns_poll_save_enabled_domains(array $domains): void
{
    pm_Settings::set('enabled_domains', json_encode(array_values($domains)));
}

function cfdns_poll_seen_domains(): array
{
    $raw = (string) pm_Settings::get('seen_domains', '');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_unique($decoded)) : [];
}

function cfdns_poll_save_seen_domains(array $names): void
{
    pm_Settings::set('seen_domains', json_encode(array_values(array_unique($names))));
}

function cfdns_poll_set_status(string $zone, array $status): void
{
    $status['ts'] = time();
    pm_Settings::set('status_' . $zone, json_encode($status));
}

/**
 * Acquire a non-blocking exclusive flock on a per-domain lock file.
 *
 * Returns the file pointer on success (caller MUST keep it alive and
 * close it to release), or null when another instance is currently
 * processing this domain. The lock file lives under the extension's
 * /var/ dir; flock state is per-process so a stale file is harmless.
 *
 * @return resource|null
 */
function cfdns_poll_lock(string $domain)
{
    $safe = preg_replace('/[^a-z0-9.-]/i', '_', $domain) ?? $domain;
    $path = rtrim(pm_Context::getVarDir(), '/') . '/sync-' . $safe . '.lock';

    $fp = LockFile::open($path);

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

/**
 * Acquire the process-wide, non-blocking run lock for a scheduled poll.
 *
 * A single slow domain (Cloudflare latency plus the API client's retry
 * budget) can stretch one cycle past the every-minute cron interval. Without
 * a whole-run guard the next cron then wakes, finds each domain's per-domain
 * lock held by the still-running cycle, and logs a "another sync in progress"
 * line per domain — a burst of noise plus duplicated work. Taking one run
 * lock at the top of a scheduled cycle means an overlapping cycle exits at
 * once and silently instead. flock is released automatically on process exit,
 * so a crashed cycle never wedges the lock (the 0-byte file is harmless).
 *
 * @return resource|null  fp held for the run, or null when one already runs.
 */
function cfdns_poll_run_lock()
{
    $path = rtrim(pm_Context::getVarDir(), '/') . '/sync-poll-all.lock';

    $fp = LockFile::open($path);

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    return $fp;
}

/** Look up a Cloudflare zone ID, using a pm_Settings cache to avoid
 *  one CF API call per domain per poll cycle. Falls back to the live
 *  CF API on cache miss; auto-creates the zone if $accountId is set. */
function cfdns_poll_zone_id(Zones $zones, string $zoneName, string $accountId): ?string
{
    $cacheKey = 'cf_zone_id_' . $zoneName;
    $cached = trim((string) pm_Settings::get($cacheKey, ''));
    if ($cached !== '') {
        return $cached;
    }

    $zoneId = ($accountId !== '')
        ? $zones->ensure($zoneName, $accountId)
        : $zones->findId($zoneName);

    if ($zoneId !== null && $zoneId !== '') {
        pm_Settings::set($cacheKey, $zoneId);
    }
    return $zoneId;
}

$token = trim((string) pm_Settings::get('api_token'));
if ($token === '') {
    // Silent exit — the empty-token state is already visible from the UI's
    // connection-status banner. Logging every minute would just be noise.
    exit(0);
}
$accountId = trim((string) pm_Settings::get('account_id'));

$enabled = cfdns_poll_enabled_domains();

// Optional CLI argument restricts the poll to a single domain. Used by the
// "Resync this domain" UI action so only that row is reconciled.
$onlyDomain = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($onlyDomain !== '') {
    $enabled = in_array($onlyDomain, $enabled, true) ? [$onlyDomain] : [];
    if (empty($enabled)) {
        cfdns_poll_log("$onlyDomain is not activated — refusing to poll.");
        exit(0);
    }
} else {
    // Whole-run guard: serialise scheduled cycles. If a previous scheduled
    // poll is still running (a slow domain stretched it past the cron
    // interval), skip this entire cycle at once rather than emitting a
    // per-domain "another sync in progress" burst. The Resync-UI path
    // ($onlyDomain) deliberately does NOT take this lock, so a manual resync
    // is never blocked by a long scheduled cycle — its per-domain lock is
    // enough. The fp is held for the whole run and released on process exit.
    $runFp = cfdns_poll_run_lock();
    if ($runFp === null) {
        exit(0);
    }

    // Bound the log before this cycle writes to it (serialised by the run
    // lock above, so rotation never races with a concurrent scheduled cycle).
    cfdns_poll_rotate_log();

    // Scheduled poll. Honour "Auto-enable new domains": enrol each Plesk
    // main domain EXACTLY ONCE — the first time the cron sees it. After
    // that the domain's enable/disable state is operator-controlled. A
    // domain deactivated via the UI stays in `seen_domains` and the cron
    // never re-enrols it (fixes N1 from the 2026-05-28 audit).
    $autoEnable = ((string) pm_Settings::get('auto_enable_new_domains', '')) !== '';
    if ($autoEnable) {
        $seen = cfdns_poll_seen_domains();
        $bootstrapped = ((string) pm_Settings::get('seen_domains_bootstrapped', '')) === '1';
        // Mirror the settings-page filter so auto-enable does not enrol the
        // server's own hostname or a slave/secondary zone via the back door.
        $allMain = [];
        foreach (\pm_Domain::getAllDomains(true) as $d) {
            $name = $d->getName();
            try {
                if (!SyncableDomain::isSyncable($name)) {
                    continue;
                }
            } catch (\Throwable $e) {
                cfdns_poll_log("SyncableDomain::isSyncable threw for '$name': " . $e->getMessage());
            }
            $allMain[] = $name;
        }

        if (!$bootstrapped) {
            // First auto-enable run after upgrade. Mark every existing
            // domain as already-seen so we don't auto-enrol the whole
            // server. The next poll cycle picks up the "new" path.
            cfdns_poll_save_seen_domains($allMain);
            pm_Settings::set('seen_domains_bootstrapped', '1');
            cfdns_poll_log('seen-domains bootstrap: ' . count($allMain) . ' domains marked as seen, no auto-enrollment this cycle.');
        } else {
            $newDomains = AutoEnable::computeNewDomains($allMain, $seen);
            if ($newDomains !== []) {
                $enabledSet = array_fill_keys($enabled, true);
                foreach ($newDomains as $name) {
                    $seen[] = $name;
                    if (!isset($enabledSet[$name])) {
                        $enabled[] = $name;
                        $enabledSet[$name] = true;
                        cfdns_poll_log("$name auto-enabled for sync.");
                    }
                }
                cfdns_poll_save_seen_domains($seen);
                cfdns_poll_save_enabled_domains($enabled);
            }
        }
    }
}

if (empty($enabled)) {
    // Quiet exit when nothing is activated.
    exit(0);
}

$client = new Client($token);
$zones = new Zones($client);
$hadError = false;

foreach ($enabled as $zoneName) {
    // Per-domain lock: skip cleanly when another instance is processing
    // this zone (e.g. a Resync-UI trigger overlapping with a scheduled
    // poll, or two scheduled polls when the previous one ran long).
    try {
        $lockFp = cfdns_poll_lock($zoneName);
    } catch (\RuntimeException $e) {
        cfdns_poll_log("$zoneName — lock acquisition failed: " . $e->getMessage());
        cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => 'lock acquisition failed: ' . $e->getMessage()]);
        $hadError = true;
        continue;
    }
    if ($lockFp === null) {
        cfdns_poll_log("$zoneName — another sync in progress, skipping this cycle.");
        continue;
    }

    try {
        $read = ZoneReader::read($zoneName);
        $desired = $read['records'];
        $skipped = $read['skipped'];
        foreach ($skipped as $skip) {
            cfdns_poll_log("$zoneName — skipped $skip");
        }

        $zoneId = cfdns_poll_zone_id($zones, $zoneName, $accountId);

        if ($zoneId === null) {
            cfdns_poll_log("$zoneName is not in Cloudflare and no account ID is set — skipping.");
            cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => 'not in Cloudflare and no account ID set']);
            $hadError = true;
            continue;
        }

        $dns = new DnsRecords($client, $zoneId);
        try {
            $existing = $dns->listAll();
        } catch (ApiException $e) {
            // 404 on a cached zone ID means the zone was deleted in
            // Cloudflare since we last looked. Drop the cache and let the
            // next cycle re-resolve.
            if ($e->httpStatus() === 404) {
                pm_Settings::set('cf_zone_id_' . $zoneName, '');
            }
            throw $e;
        }

        $managedIds = [];
        foreach ($existing as $record) {
            if ($record->id !== null && Ownership::isManaged($record->comment)) {
                $managedIds[] = $record->id;
            }
        }

        $plan = ZoneSync::plan($desired, $existing, ['managedIds' => $managedIds]);
        foreach ($plan->conflicts as $c) {
            if (($c['reason'] ?? 'type') === 'content') {
                cfdns_poll_log("$zoneName — content conflict at {$c['name']} ({$c['type']}): foreign record with different content exists, refusing to duplicate");
            } else {
                cfdns_poll_log("$zoneName — type conflict at {$c['name']}: foreign {$c['foreign_type']} record exists, cannot create {$c['type']}");
            }
        }
        $report = $dns->apply($plan);

        // Only log when something actually changed — keeps sync.log signal-rich
        // on a polling cadence that may run every minute.
        if ($plan->creates !== [] || $plan->updates !== [] || $plan->deletes !== [] || $plan->adopted !== []) {
            cfdns_poll_log("$zoneName — " . $report->summary());
        }
        foreach ($report->errors as $error) {
            cfdns_poll_log("$zoneName — {$error['action']} {$error['record']}: {$error['error']}");
        }
        if ($report->hasErrors()) {
            cfdns_poll_set_status($zoneName, [
                'ok' => false,
                'error' => count($report->errors) . ' record(s) failed to sync',
                'skipped_count' => count($skipped),
                // Cap the verbatim list at 5 entries — pm_Settings rows are
                // small key/value blobs and we only need a hint for the UI.
                'skipped' => array_slice($skipped, 0, 5),
                'conflicts_count' => count($plan->conflicts),
                'conflicts' => array_map(
                    fn (array $c): string => "{$c['type']}@{$c['name']} ({$c['reason']} vs {$c['foreign_type']})",
                    array_slice($plan->conflicts, 0, 5)
                ),
            ]);
            $hadError = true;
        } else {
            cfdns_poll_set_status($zoneName, [
                'ok' => true,
                'records' => count($desired),
                'skipped_count' => count($skipped),
                // Cap the verbatim list at 5 entries — pm_Settings rows are
                // small key/value blobs and we only need a hint for the UI.
                'skipped' => array_slice($skipped, 0, 5),
                'conflicts_count' => count($plan->conflicts),
                'conflicts' => array_map(
                    fn (array $c): string => "{$c['type']}@{$c['name']} ({$c['reason']} vs {$c['foreign_type']})",
                    array_slice($plan->conflicts, 0, 5)
                ),
            ]);
        }
    } catch (ApiException $e) {
        cfdns_poll_log("$zoneName — Cloudflare API error: " . $e->getMessage());
        cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    } catch (\Throwable $e) {
        cfdns_poll_log("$zoneName — unexpected error: " . $e->getMessage());
        cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

// Heartbeat: record that a full SCHEDULED cycle ran to completion (even if
// some domains errored — the watchdog cares whether the poll is *running*, not
// whether every record synced; per-domain failures surface via status_<zone>).
// The Resync-UI path ($onlyDomain) is excluded so a single-domain resync never
// masks a stalled scheduler. watchdog.php compares this against time().
if ($onlyDomain === '') {
    pm_Settings::set('last_poll_ts', (string) time());
}

exit($hadError ? 255 : 0);

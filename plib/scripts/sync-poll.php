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
use Noiz\CloudflareDns\PleskDns\ZoneReader;

pm_Loader::registerAutoload();
pm_Context::init('cloudflare-dns-sync');

require_once __DIR__ . '/../library/autoload.php';

function cfdns_poll_log(string $message): void
{
    fwrite(STDOUT, 'cloudflare-dns-sync-poll: ' . $message . "\n");
    @file_put_contents(
        rtrim(pm_Context::getVarDir(), '/') . '/sync.log',
        '[' . date('Y-m-d H:i:s') . '] [poll] ' . $message . "\n",
        FILE_APPEND
    );
}

function cfdns_poll_enabled_domains(): array
{
    $list = json_decode((string) pm_Settings::get('enabled_domains', '[]'), true);
    return is_array($list) ? $list : [];
}

function cfdns_poll_set_status(string $zone, array $status): void
{
    $status['ts'] = time();
    pm_Settings::set('status_' . $zone, json_encode($status));
}

$token = trim((string) pm_Settings::get('api_token'));
if ($token === '') {
    cfdns_poll_log('no Cloudflare API token configured — nothing to do.');
    exit(0);
}
$accountId = trim((string) pm_Settings::get('account_id'));

$enabled = cfdns_poll_enabled_domains();
if (empty($enabled)) {
    // Quiet exit when nothing is activated. We don't even log per-cycle to
    // avoid sync.log noise — operators see the toggle list in the UI.
    exit(0);
}

// Optional CLI argument restricts the poll to a single domain. Used by the
// "Resync this domain" UI action so only that row is reconciled.
$onlyDomain = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($onlyDomain !== '') {
    $enabled = in_array($onlyDomain, $enabled, true) ? [$onlyDomain] : [];
    if (empty($enabled)) {
        cfdns_poll_log("$onlyDomain is not activated — refusing to poll.");
        exit(0);
    }
}

$client = new Client($token);
$zones = new Zones($client);
$hadError = false;

foreach ($enabled as $zoneName) {
    try {
        $desired = ZoneReader::read($zoneName);

        $zoneId = ($accountId !== '')
            ? $zones->ensure($zoneName, $accountId)
            : $zones->findId($zoneName);

        if ($zoneId === null) {
            cfdns_poll_log("$zoneName is not in Cloudflare and no account ID is set — skipping.");
            cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => 'not in Cloudflare and no account ID set']);
            $hadError = true;
            continue;
        }

        $dns = new DnsRecords($client, $zoneId);
        $existing = $dns->listAll();

        $managedIds = [];
        foreach ($existing as $record) {
            if ($record->id !== null && Ownership::isManaged($record->comment)) {
                $managedIds[] = $record->id;
            }
        }

        $plan = ZoneSync::plan($desired, $existing, ['managedIds' => $managedIds]);
        $report = $dns->apply($plan);

        // Only log when something actually changed — keeps sync.log signal-rich
        // on a polling cadence that might run every minute.
        if ($plan->creates !== [] || $plan->updates !== [] || $plan->deletes !== [] || $plan->adopted !== []) {
            cfdns_poll_log("$zoneName — " . $report->summary());
        }
        foreach ($report->errors as $error) {
            cfdns_poll_log("$zoneName — {$error['action']} {$error['record']}: {$error['error']}");
        }
        if ($report->hasErrors()) {
            cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => count($report->errors) . ' record(s) failed to sync']);
            $hadError = true;
        } else {
            cfdns_poll_set_status($zoneName, ['ok' => true, 'records' => count($desired)]);
        }
    } catch (ApiException $e) {
        cfdns_poll_log("$zoneName — Cloudflare API error: " . $e->getMessage());
        cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    } catch (\Throwable $e) {
        cfdns_poll_log("$zoneName — unexpected error: " . $e->getMessage());
        cfdns_poll_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    }
}

exit($hadError ? 255 : 0);

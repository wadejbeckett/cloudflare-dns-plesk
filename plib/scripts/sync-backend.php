<?php

declare(strict_types=1);

/**
 * Custom DNS backend handler for the "Cloudflare DNS Sync" extension.
 *
 * Plesk invokes this script on every DNS zone change, passing the affected
 * zone(s) as a JSON array on stdin. It is registered with Plesk by
 * plib/scripts/post-install.php via `server_dns --enable-custom-backend`.
 *
 * Each zone is reconciled into Cloudflare non-destructively. The script exits
 * 0 on success and 255 if any zone failed; all output goes to stdout, which
 * Plesk captures into its logs.
 */

use Noiz\CloudflareDns\Cloudflare\ApiException;
use Noiz\CloudflareDns\Cloudflare\Client;
use Noiz\CloudflareDns\Cloudflare\DnsRecords;
use Noiz\CloudflareDns\Cloudflare\Ownership;
use Noiz\CloudflareDns\Cloudflare\ZoneSync;
use Noiz\CloudflareDns\Cloudflare\Zones;
use Noiz\CloudflareDns\PleskDns\Payload;
use Noiz\CloudflareDns\PleskDns\ZoneOperation;

pm_Loader::registerAutoload();
pm_Context::init('cloudflare-dns-sync');

require_once __DIR__ . '/../library/autoload.php';

/** Write a line to stdout — Plesk records it in the panel log. */
function cfdns_log(string $message): void
{
    fwrite(STDOUT, 'cloudflare-dns-sync: ' . $message . "\n");
}

$token = trim((string) pm_Settings::get('api_token'));
if ($token === '') {
    // Not configured yet — do nothing and let Plesk's own DNS proceed.
    cfdns_log('no Cloudflare API token configured — skipping.');
    exit(0);
}
$accountId = trim((string) pm_Settings::get('account_id'));

// "Auto-create zones for new domains" — when off, only zones that already
// exist in Cloudflare are synced; a brand-new domain is not created there.
$autoCreate = ((string) pm_Settings::get('autosync_new_domains', '1')) !== '';

$input = (string) file_get_contents('php://stdin');
if (trim($input) === '') {
    exit(0);
}

try {
    $parsed = Payload::parse($input);
} catch (\Throwable $e) {
    cfdns_log('cannot parse the Plesk DNS payload: ' . $e->getMessage());
    exit(255);
}

foreach ($parsed['skipped'] as $skipped) {
    cfdns_log('skipped unsupported record (' . $skipped . ')');
}

$client = new Client($token);
$zones = new Zones($client);
$hadError = false;

foreach ($parsed['operations'] as $operation) {
    $zoneName = $operation->zoneName;

    try {
        if ($operation->command === ZoneOperation::DELETE) {
            // Deliberately conservative: a domain removed in Plesk does NOT
            // delete the Cloudflare zone. It is left intact.
            cfdns_log("$zoneName removed in Plesk — Cloudflare zone left intact.");
            continue;
        }

        $zoneId = ($autoCreate && $accountId !== '')
            ? $zones->ensure($zoneName, $accountId)
            : $zones->findId($zoneName);

        if ($zoneId === null) {
            cfdns_log("$zoneName is not in Cloudflare (auto-create off or no account id) — skipping.");
            $hadError = true;
            continue;
        }

        $dns = new DnsRecords($client, $zoneId);
        $existing = $dns->listAll();

        // Ownership is read straight off the Cloudflare records' comments.
        $managedIds = [];
        foreach ($existing as $record) {
            if ($record->id !== null && Ownership::isManaged($record->comment)) {
                $managedIds[] = $record->id;
            }
        }

        $plan = ZoneSync::plan($operation->records, $existing, ['managedIds' => $managedIds]);
        $report = $dns->apply($plan);

        cfdns_log("$zoneName — " . $report->summary());
        foreach ($report->errors as $error) {
            cfdns_log("$zoneName — {$error['action']} {$error['record']}: {$error['error']}");
        }
        if ($report->hasErrors()) {
            $hadError = true;
        }
    } catch (ApiException $e) {
        cfdns_log("$zoneName — Cloudflare API error: " . $e->getMessage());
        $hadError = true;
    } catch (\Throwable $e) {
        cfdns_log("$zoneName — unexpected error: " . $e->getMessage());
        $hadError = true;
    }
}

exit($hadError ? 255 : 0);

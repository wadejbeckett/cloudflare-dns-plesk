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

/** Write a line to stdout (Plesk's log) and the extension's own log file. */
function cfdns_log(string $message): void
{
    fwrite(STDOUT, 'cloudflare-dns-sync: ' . $message . "\n");
    @file_put_contents(
        rtrim(pm_Context::getVarDir(), '/') . '/sync.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND
    );
}

/** Domains the administrator has activated for syncing. */
function cfdns_enabled_domains(): array
{
    $list = json_decode((string) pm_Settings::get('enabled_domains', '[]'), true);
    return is_array($list) ? $list : [];
}

/** Persist the activation list. */
function cfdns_save_enabled_domains(array $domains): void
{
    pm_Settings::set('enabled_domains', json_encode(array_values($domains)));
}

/** Record a zone's sync outcome for the settings page to display. */
function cfdns_set_status(string $zone, array $status): void
{
    $status['ts'] = time();
    pm_Settings::set('status_' . $zone, json_encode($status));
}

$token = trim((string) pm_Settings::get('api_token'));
if ($token === '') {
    // Not configured yet — do nothing and let Plesk's own DNS proceed.
    cfdns_log('no Cloudflare API token configured — skipping.');
    exit(0);
}
$accountId = trim((string) pm_Settings::get('account_id'));

// "Auto-enable new domains" — when on, a domain that is not on the activation
// list is added to it (and synced) the first time Plesk reports a change for
// it. When off (default), only already-activated domains are ever synced.
$autoEnable = ((string) pm_Settings::get('auto_enable_new_domains', '')) !== '';
$enabled = cfdns_enabled_domains();

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
    $isEnabled = in_array($zoneName, $enabled, true);

    // Per-domain activation gate: only domains the administrator has activated
    // are synced. Every other domain on the server is left entirely alone.
    if (!$isEnabled) {
        if ($operation->command === ZoneOperation::DELETE) {
            continue; // Not synced — nothing to undo.
        }
        if (!$autoEnable) {
            cfdns_log("$zoneName is not activated for sync — skipping.");
            continue;
        }
        $enabled[] = $zoneName;
        cfdns_save_enabled_domains($enabled);
        cfdns_log("$zoneName auto-enabled for sync.");
    }

    try {
        if ($operation->command === ZoneOperation::DELETE) {
            // Deliberately conservative: a domain removed in Plesk does NOT
            // delete the Cloudflare zone. It is left intact. Drop it from the
            // activation list — if re-added it follows the auto-enable setting.
            cfdns_log("$zoneName removed in Plesk — Cloudflare zone left intact.");
            $enabled = array_values(array_diff($enabled, [$zoneName]));
            cfdns_save_enabled_domains($enabled);
            pm_Settings::set('status_' . $zoneName, '');
            continue;
        }

        $zoneId = ($accountId !== '')
            ? $zones->ensure($zoneName, $accountId)
            : $zones->findId($zoneName);

        if ($zoneId === null) {
            cfdns_log("$zoneName is not in Cloudflare and no account ID is set — skipping.");
            cfdns_set_status($zoneName, ['ok' => false, 'error' => 'not in Cloudflare and no account ID set']);
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
            cfdns_set_status($zoneName, ['ok' => false, 'error' => count($report->errors) . ' record(s) failed to sync']);
            $hadError = true;
        } else {
            cfdns_set_status($zoneName, ['ok' => true, 'records' => count($operation->records)]);
        }
    } catch (ApiException $e) {
        cfdns_log("$zoneName — Cloudflare API error: " . $e->getMessage());
        cfdns_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    } catch (\Throwable $e) {
        cfdns_log("$zoneName — unexpected error: " . $e->getMessage());
        cfdns_set_status($zoneName, ['ok' => false, 'error' => $e->getMessage()]);
        $hadError = true;
    }
}

// ---------------------------------------------------------------------------
// Optional pass-through to another custom DNS backend handler.
//
// Plesk has a single exclusive "custom DNS backend" slot (see
// `server_dns --enable-custom-backend`). Only one extension can register
// at a time — the most common collision is Plesk's `slave-dns-manager`,
// which uses the slot to drive BIND slave replication. To let both
// extensions function alongside each other, this extension exposes an
// admin-configurable pass-through: after our processing, the SAME stdin
// payload is forwarded to whatever handler the admin has nominated.
//
// The setting is generic — it accepts any command-line string. For
// slave-dns-manager the admin pastes:
//   /usr/local/psa/bin/extension --exec slave-dns-manager slave-dns.php
// (post-install auto-suggests this when the extension is detected).
// Empty → no forwarding.
//
// Note: this is the v0.4.x interim. The proper architectural fix is the
// v0.5.0 polling backend, which frees the slot entirely — see
// _internal/AUDIT… and the v0.5.0 design doc.
// ---------------------------------------------------------------------------
$passThrough = trim((string) pm_Settings::get('pass_through_handler', ''));
if ($passThrough !== '') {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($passThrough, $descriptors, $pipes);
    if (is_resource($process)) {
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $forwardedOut = stream_get_contents($pipes[1]) ?: '';
        $forwardedErr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $forwardedCode = proc_close($process);
        if ($forwardedCode !== 0) {
            cfdns_log("pass-through handler exited $forwardedCode: " . trim($forwardedErr));
        } elseif (trim($forwardedOut . $forwardedErr) !== '') {
            cfdns_log("pass-through handler output: " . trim($forwardedOut . $forwardedErr));
        }
    } else {
        cfdns_log("could not start pass-through handler: $passThrough");
    }
}

exit($hadError ? 255 : 0);

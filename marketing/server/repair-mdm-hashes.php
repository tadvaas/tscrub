<?php
declare(strict_types=1);

/**
 * One-time repair for the cross-account MDM "no hash" bug.
 *
 * Bug: mdm_staged_hash had a GLOBAL unique key (serial, uuid), so when a
 * second account staged a hash for a device another account already owned, the
 * ON DUPLICATE KEY UPDATE folded it into the first account's row (keeping the
 * first owner and clobbering its hash). The second account then had no hash of
 * its own and MDM reported "no hash" (---).
 *
 * This script:
 *   1. applies the fixed schema — mdm_ensure_schema() now uses a per-user
 *      unique key (owner_key, serial, uuid) with owner_key = COALESCE(user_id, 0);
 *   2. for every account that has a diagnostics report for a device but no
 *      staged hash of its own, regenerates the OAv3 hash from that report and
 *      stages it (mdm_stage_hash). Idempotent — safe to re-run; a hash already
 *      owned by the account (e.g. a WinPE-authoritative hash) is never touched.
 *
 * Where the first account's authoritative hash was already clobbered by the
 * bug, it cannot be recovered from the DB — it is regenerated from the
 * diagnostics report payload (the only remaining source).
 *
 * Usage:
 *   php repair-mdm-hashes.php            # dry run — report only
 *   php repair-mdm-hashes.php --apply    # write missing hashes
 */

require __DIR__ . '/db.php';
require __DIR__ . '/mdm.php';

$apply = in_array('--apply', $argv, true);

mdm_ensure_schema();

// Latest diagnostics report per (user, serial, uuid) is the canonical
// hardware source for regenerating a hash.
$rows = db()->query(
    "SELECT id, user_id, payload
     FROM reports
     WHERE report_type = 'diagnostics'
     ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$latest = [];
foreach ($rows as $r) {
    $p = json_decode((string)($r['payload'] ?? ''), true);
    if (!is_array($p)) {
        continue;
    }
    $serial = trim((string)($p['sysserial'] ?? ''));
    $uuid   = trim((string)($p['systemuuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        continue;
    }
    $key = ((int)$r['user_id']) . "\x1f" . $serial . "\x1f" . $uuid;
    if (!isset($latest[$key])) {
        $latest[$key] = $r;
    }
}

$missing  = 0;
$repaired = 0;
$skipped  = 0;

foreach ($latest as $r) {
    $userId = (int)$r['user_id'];
    $p      = json_decode((string)$r['payload'], true);
    $serial = trim((string)($p['sysserial'] ?? ''));
    $uuid   = trim((string)($p['systemuuid'] ?? ''));
    $model  = trim((string)($p['product'] ?? ''));

    // Already owns a hash (WinPE-authoritative or regenerated) — leave it.
    if (mdm_staged_hash($userId, $serial, $uuid) !== null) {
        continue;
    }

    $missing++;

    // mdm_report_hash() expects the raw body's 'serial'/'uuid'; the stored
    // payload uses 'sysserial'/'systemuuid'. Everything else (bios_vendor,
    // manufacturer, product, sku, family, board_*, system_version, tpm_*, macs,
    // drives, product_key_id) is stored under the same keys as the raw body.
    $d = $p;
    $d['serial'] = $serial;
    $d['uuid']   = $uuid;
    if (!isset($d['product_key_id'])) {
        $d['product_key_id'] = '';
    }

    $hash = mdm_report_hash($d);
    if ($hash === '') {
        fwrite(STDOUT, "SKIP    user={$userId} serial={$serial}: no hash can be generated\n");
        $skipped++;
        continue;
    }

    fwrite(STDOUT, ($apply ? "REPAIR  " : "MISSING ") . "user={$userId} serial={$serial} uuid={$uuid}\n");
    if ($apply) {
        mdm_stage_hash($userId, $serial, $uuid, $model, $hash);
        $repaired++;
    }
}

fwrite(STDOUT, sprintf(
    "Done: %d account/device pair(s) missing a hash, %d %s, %d skipped.\n",
    $missing,
    $repaired,
    $apply ? 'repaired' : 'would repair (re-run with --apply)',
    $skipped
));

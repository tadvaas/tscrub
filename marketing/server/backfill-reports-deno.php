<?php
declare(strict_types=1);

/**
 * Backfill the denormalized `device_key` / `summary_json` / `drive_serials`
 * columns on existing reports rows (added for the two-phase Devices and Drives
 * pagination). Idempotent: rows with a non-empty device_key are skipped.
 *
 * Run from the form dir:  php backfill-reports-deno.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/grading.php';
require_once __DIR__ . '/reports_lib.php';

reports_ensure_schema();

$stmt = db()->query('SELECT id, report_type, payload FROM reports');
$upd = db()->prepare('UPDATE reports SET device_key = ?, summary_json = ?, drive_serials = ? WHERE id = ?');
$n = 0;
while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    $g = json_decode((string)$r['payload'], true);
    if (!is_array($g)) continue;
    $den = reports_denormalize($g, (string)$r['report_type'] === 'diagnostics');
    // Erasure reports with no system identity still carry drive serials the
    // Drives tab pages over — always index them, even when device_key is ''.
    if ($den['device_key'] === '' && $den['summary'] === null && $den['drive_serials'] === []) continue;
    $upd->execute([
        $den['device_key'],
        $den['summary'] === null ? null : json_encode($den['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($den['drive_serials'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)$r['id'],
    ]);
    $n++;
}
echo "backfilled $n reports\n";

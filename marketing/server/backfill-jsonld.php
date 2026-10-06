<?php
declare(strict_types=1);

/**
 * One-off backfill: generate machine-readable JSON-LD for certificates issued
 * before the feature shipped. Idempotent — safe to re-run.
 *
 * Usage:
 *   php backfill-jsonld.php                 # dry-run (report only)
 *   php backfill-jsonld.php --apply         # generate + persist
 *   php backfill-jsonld.php --apply --limit 100
 *
 * Requires the production config.json + vendor.key (same as the web server),
 * because generation signs with the vendor Ed25519 key.
 */

require_once __DIR__ . '/jsonld.php';

$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $i => $a) {
    if ($a === '--limit' && isset($argv[$i + 1]) && ctype_digit((string)$argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
    }
}

certs_ensure_schema();

$sql = "SELECT * FROM certificates WHERE json_path = '' ORDER BY id";
if ($limit !== null) { $sql .= " LIMIT {$limit}"; }
$rows = db()->query($sql)->fetchAll();

$total = count($rows);
fwrite(STDOUT, ($apply ? 'APPLY' : 'DRY-RUN') . " — {$total} certificate(s) without JSON-LD\n");

$done = 0;
$failed = 0;
foreach ($rows as $row) {
    if (!$apply) {
        fwrite(STDOUT, "  would generate  {$row['cert_id']}  (cocid={$row['cocid']})\n");
        $done++;
        continue;
    }
    $r = jsonld_ensure_cert($row);
    if ($r === null) {
        fwrite(STDOUT, "  FAILED          {$row['cert_id']}  (signing unavailable)\n");
        $failed++;
    } else {
        fwrite(STDOUT, "  generated       {$row['cert_id']}  (ts={$r['ts_state']})\n");
        $done++;
    }
}

fwrite(STDOUT, "Done: {$done} generated, {$failed} failed\n");
exit($failed > 0 ? 1 : 0);

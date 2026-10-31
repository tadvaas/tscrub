<?php
/**
 * Unit tests for media_provenance.php — the asset → medium → host relation.
 * No database and no TCPDF: this covers the rules the certificate, the JSON-LD
 * twin, the public verifier and the dashboard all depend on.
 *
 * Run: php marketing/tests/media_provenance_test.php
 */

require_once __DIR__ . '/../server/media_provenance.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label\n";
    }
}

function t_eq(string $label, $expected, $actual): void
{
    global $count, $fails;
    $count++;
    if ($expected === $actual) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label\n";
        echo '       expected: ' . var_export($expected, true) . "\n";
        echo '       actual:   ' . var_export($actual, true) . "\n";
    }
}

/** One boot snapshot. */
function mp_diag(string $serial, array $media, array $extra = []): array
{
    return array_merge([
        'sysserial'     => $serial,
        'system'        => 'Dell Latitude 5420',
        'asset_tag'     => 'AT-1',
        'serial_source' => 'dmi',
        'uploaded_at'   => '2026-10-30 14:02',
        'media_state'   => $media === [] ? 'none' : 'ok',
        'drives'        => array_map(static fn($s) => ['serial' => $s], $media),
    ], $extra);
}

/** One erasure report group. */
function mp_group(string $host, array $drives): array
{
    return ['sysserial' => $host, 'system' => 'Host ' . $host, 'drives' => $drives];
}

function mp_drive(string $serial, string $status, string $host): array
{
    return [
        'serial' => $serial, 'status' => $status, 'sysserial' => $host,
        'model' => 'Samsung PM981a', 'bus' => 'NVMe', 'type' => 'SSD',
        'size' => '512 GB', 'method' => 'NVMe Crypto Purge', 'cls' => 'PURGE',
    ];
}

function mp_asset_by(array $assets, string $serial): ?array
{
    foreach ($assets as $a) {
        if (strcasecmp((string)($a['asset_serial'] ?? ''), $serial) === 0) return $a;
    }
    return null;
}

// ---- 1. Ordinary in-place job: nothing to account for -----------------------
$payloads = [mp_group('ASSET1', [mp_drive('D1', 'COMPLETED', 'ASSET1')])];
$diags    = [mp_diag('ASSET1', ['D1'])];
$drive    = [mp_drive('D1', 'COMPLETED', 'ASSET1')];

$c = mp_collect($payloads, $diags, [], []);
$r = mp_apply_evidence($c, $drive);
t_eq('in place: one asset', 1, count($r['assets']));
t_eq('in place: disposition', 'sanitised_in_place', $r['assets'][0]['disposition']);
t_eq('in place: no drive annotation', [], $r['drives']);
t_check('in place: accounting hidden', !mp_asset_accounting_visible($r['assets']));
t_check('in place: block rows empty', mp_asset_block_rows($r['assets']) === []);

// ---- 2. Failed on the asset, completed on a bench ---------------------------
$payloads = [
    mp_group('ASSET1', [mp_drive('D1', 'FAILED', 'ASSET1')]),
    mp_group('BENCH',  [mp_drive('D1', 'COMPLETED', 'BENCH')]),
];
$diags = [mp_diag('ASSET1', ['D1'])];
$merged = [mp_drive('D1', 'COMPLETED', 'BENCH')];

$c = mp_collect($payloads, $diags, [], ['bench' => true]);
$r = mp_apply_evidence($c, $merged);
t_eq('removed: bench is not an asset', 1, count($r['assets']));
$asset = mp_asset_by($r['assets'], 'ASSET1');
t_eq('removed: disposition', 'media_removed_sanitised', $asset['disposition'] ?? null);
t_eq('removed: drive annotated with origin', 'ASSET1', $r['drives']['d1']['asset_serial'] ?? null);
t_eq('removed: provenance is the failed attempt', 'observed_failed', $r['drives']['d1']['origin_provenance'] ?? null);
$pj = json_decode($r['drives']['d1']['provenance_json'] ?? '{}', true);
t_eq('removed: JSON records the host', 'BENCH', $pj['host_serial'] ?? null);
t_eq('removed: JSON records the failure', 'FAILED', $pj['failed_status'] ?? null);
t_check('removed: accounting visible', mp_asset_accounting_visible($r['assets']));
t_eq('removed: block has one row', 1, count(mp_asset_block_rows($r['assets'])));

// ---- 3. Declared origin, bench evidence ------------------------------------
$payloads = [mp_group('BENCH', [mp_drive('D1', 'COMPLETED', 'BENCH')])];
$links = [[
    'asset_serial' => 'ASSET1', 'media_serial' => 'D1',
    'reason' => 'media_removed', 'note' => 'pulled at intake',
]];
$c = mp_collect($payloads, [], $links, ['bench' => true]);
$r = mp_apply_evidence($c, $merged);
$asset = mp_asset_by($r['assets'], 'ASSET1');
t_eq('declared: disposition', 'media_removed_sanitised', $asset['disposition'] ?? null);
t_eq('declared: provenance', 'declared', $r['drives']['d1']['origin_provenance'] ?? null);
t_eq('declared: origin', 'ASSET1', $r['drives']['d1']['asset_serial'] ?? null);

// ---- 4. A declaration never overrides an observation on another host -------
$payloads = [mp_group('ASSET-A', [mp_drive('D1', 'COMPLETED', 'ASSET-A')])];
$links = [['asset_serial' => 'ASSET-B', 'media_serial' => 'D1', 'reason' => '', 'note' => '']];
$c = mp_collect($payloads, [], $links, []);
$r = mp_apply_evidence($c, [mp_drive('D1', 'COMPLETED', 'ASSET-A')]);
t_eq('declaration loses: only the observed asset remains', 1, count($r['assets']));
t_eq('declaration loses: observed asset in place', 'sanitised_in_place', $r['assets'][0]['disposition']);
t_eq('declaration loses: no annotation', [], $r['drives']);

// ---- 5. No data-bearing media at intake ------------------------------------
$c = mp_collect([], [mp_diag('ASSET1', [])], [], []);
$r = mp_apply_evidence($c, []);
$asset = mp_asset_by($r['assets'], 'ASSET1');
t_eq('no media: disposition', 'no_data_bearing_media', $asset['disposition'] ?? null);
t_eq('no media: intake time kept', '2026-10-30 14:02', $asset['intake_ref'] ?? null);
t_check('no media: accounting visible', mp_asset_accounting_visible($r['assets']));
t_eq('no media: block row', 1, count(mp_asset_block_rows($r['assets'])));

// ---- 6. Media seen at intake, never erased ---------------------------------
$payloads = [];
$diags = [mp_diag('ASSET1', ['D1'])];
$c = mp_collect($payloads, $diags, [], []);
$r = mp_apply_evidence($c, []);
$asset = mp_asset_by($r['assets'], 'ASSET1');
t_eq('intake only: disposition is incomplete', 'incomplete', $asset['disposition'] ?? null);
t_eq('intake only: medium listed', 'd1', (string)($asset['media']['d1']['serial'] ?? ''));

// ---- 7. Two assets claim one medium: refuse to pick ------------------------
$payloads = [
    mp_group('ASSET-A', [mp_drive('D1', 'COMPLETED', 'ASSET-A')]),
    mp_group('ASSET-B', [mp_drive('D1', 'COMPLETED', 'ASSET-B')]),
];
$c = mp_collect($payloads, [], [], []);
t_check('ambiguous: conflict recorded', isset($c['conflicts']['d1']));
t_eq('ambiguous: both claimants recorded', 2, count($c['conflicts']['d1']));
$r = mp_apply_evidence($c, [mp_drive('D1', 'COMPLETED', 'ASSET-A')]);
t_eq('ambiguous: no drive annotation', [], $r['drives']);
$assetA = mp_asset_by($r['assets'], 'ASSET-A');
t_eq('ambiguous: asset A is not "no media"', 'incomplete', $assetA['disposition'] ?? null);
t_check('ambiguous: conflict marked on the medium', !empty($assetA['media']['d1']['conflict']));

// ---- 8. Placeholder / unusable serials are never linked --------------------
$payloads = [mp_group('N/A', [mp_drive('N/A', 'COMPLETED', 'N/A')])];
$diags = [mp_diag('N/A', ['N/A'])];
$c = mp_collect($payloads, $diags, [], []);
t_eq('placeholder: no assets', [], $c['assets']);
t_eq('placeholder: no media', [], $c['media']);

// ---- 9. Re-installed drive erased twice: successful row wins ---------------
$payloads = [
    mp_group('ASSET1', [mp_drive('D1', 'FAILED', 'ASSET1')]),
    mp_group('ASSET1', [mp_drive('D1', 'COMPLETED', 'ASSET1')]),
];
$c = mp_collect($payloads, [], [], []);
$r = mp_apply_evidence($c, [mp_drive('D1', 'COMPLETED', 'ASSET1')]);
$asset = mp_asset_by($r['assets'], 'ASSET1');
t_eq('retry: in place', 'sanitised_in_place', $asset['disposition'] ?? null);
t_eq('retry: provenance upgraded to the report', 'observed_host', $asset['media']['d1']['provenance'] ?? null);

// ---- 10. Destructive wording ------------------------------------------------
$line = mp_asset_line([
    'asset_serial' => 'CZC1234ABC', 'system_name' => 'Dell Latitude 5420',
    'disposition' => 'media_removed_sanitised',
]);
t_check('line names the asset', strpos($line, 'CZC1234ABC') !== false);
t_check('line says removed', strpos($line, 'removed') !== false);
t_check('line never says in place', strpos($line, 'in place') === false);
t_check('qualifier separates provenance from evidence',
    strpos(mp_provenance_qualifier(), 'declared by the operator') !== false
    && strpos(mp_provenance_qualifier(), 'instrument-verified') !== false);

echo "\n$count checks, $fails failure(s)\n";
exit($fails === 0 ? 0 : 1);

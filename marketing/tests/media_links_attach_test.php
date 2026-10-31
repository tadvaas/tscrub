<?php
declare(strict_types=1);

/**
 * Declared origins must be attached to the report-history rows as well as to the
 * consolidated media row, and each history row must be resolved against its OWN
 * COCID.
 *
 * Why: a medium can appear in reports from several jobs. The record view is
 * opened from one of those reports, so resolving only the consolidated row (the
 * latest job) shows the latest job's origin, or none, when an older report is
 * opened. That is the kind of silent wrong answer a database test against the
 * demo data would not notice.
 *
 * Pure: media_links_apply() takes the (cocid -> media key -> asset serial) map
 * directly, so no database is needed.
 *
 * Run: php marketing/tests/media_links_attach_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/reports_lib.php';

$count = 0;
$fails = 0;
function t_check(string $label, bool $ok, $detail = null): void
{
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label\n";
        if ($detail !== null) {
            echo '       got: ' . var_export($detail, true) . "\n";
        }
    }
}
function t_eq(string $label, $expected, $actual): void
{
    t_check($label, $expected === $actual, $actual);
}

$map = [
    'A' => ['d1' => 'ASSET-A'],
    'B' => ['d1' => 'ASSET-B'],
];
$rows = [[
    'cocid'   => 'A',
    'serial'  => 'D1',
    'history' => [
        ['cocid' => 'A', 'serial' => 'D1', 'report_id' => 11],
        ['cocid' => 'B', 'serial' => 'D1', 'report_id' => 12],
        ['cocid' => 'A', 'serial' => 'D2', 'report_id' => 13],
        ['cocid' => 'B', 'serial' => 'd2', 'report_id' => 14],
        'not-a-row',
    ],
]];

$out = media_links_apply($rows, $map);
$r = $out[0];

t_eq('parent: origin from its own COCID', 'ASSET-A', $r['origin_asset'] ?? null);
t_eq('parent: provenance is declared', 'declared', $r['origin_provenance'] ?? null);
t_eq('history A/D1: origin', 'ASSET-A', $r['history'][0]['origin_asset'] ?? null);
t_eq('history B/D1: resolved against the OTHER job', 'ASSET-B', $r['history'][1]['origin_asset'] ?? null);
t_check('history A/D2: no declaration is not invented', !isset($r['history'][2]['origin_asset']));
t_check('history B/d2: no declaration is not invented', !isset($r['history'][3]['origin_asset']));
t_eq('history rows keep their identity', 11, $r['history'][0]['report_id'] ?? null);
t_eq('history rows keep their COCID', 'B', $r['history'][1]['cocid'] ?? null);
t_check('a malformed history entry is skipped', $r['history'][4] === 'not-a-row');

// An attested row has no erasure report and must never be given a declared origin.
$attested = [[
    'cocid'    => 'A',
    'serial'   => 'D1',
    'attested' => true,
    'history'  => [['cocid' => 'A', 'serial' => 'D1']],
]];
$out2 = media_links_apply($attested, $map);
t_check('attested row untouched', !isset($out2[0]['origin_asset']));
t_check('attested history untouched', !isset($out2[0]['history'][0]['origin_asset']));

// No declarations at all: the rows must come back exactly as they were.
$same = media_links_apply($rows, []);
t_eq('empty map leaves the rows exactly as they were', $rows, $same);

// A parent without history is still resolved.
$flat = media_links_apply([['cocid' => 'B', 'serial' => 'D1']], $map);
t_eq('row without history: origin', 'ASSET-B', $flat[0]['origin_asset'] ?? null);

// host_is_processing(): the Media record view uses this to assume an ordinary
// host is the origin asset, and only caveat a medium that ran on the bench.
$g = ['sysserial' => 'PC1QGQNK'];
$set = [mp_key(device_key($g)) => true];
t_check('host_is_processing: matched serial', host_is_processing($g, $set));
t_check('host_is_processing: another host is not the bench', !host_is_processing(['sysserial' => 'OTHER'], $set));
t_check('host_is_processing: no processing hosts', !host_is_processing($g, []));
$ph = ['sysserial' => 'N/A', 'bbserial' => 'BOARD123'];
$set2 = [mp_key(device_key($ph)) => true];
t_check('host_is_processing: placeholder serial falls back to the board serial', host_is_processing($ph, $set2));
t_check('host_is_processing: empty identity', !host_is_processing([], $set));

echo "\n" . ($fails ? "$fails of $count checks FAILED" : "$count checks passed") . "\n";
exit($fails ? 1 : 0);

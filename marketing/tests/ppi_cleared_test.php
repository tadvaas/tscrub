<?php
/**
 * Unit test for the cross-report PPICleared derivation in merge_group().
 *
 * Pure function (no database), so it runs anywhere PHP is available:
 *   php ppi_cleared_test.php /path/to/server
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/reports_lib.php';

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

function drive(string $serial, string $status, string $blockSid = 'no'): array
{
    return ['serial' => $serial, 'status' => $status, 'block_sid' => $blockSid];
}

$emptyCert = ['first_ts' => null, 'last_ts' => null, 'sha_state' => 'unverified', 'sig_state' => 'none'];

// 1. BLOCKED in a prior report, COMPLETED now -> ppi_cleared=yes.
$g = ['cocid' => 'C1', 'drives' => [drive('S1', 'COMPLETED')], 'reports' => []];
$db = [drive('S1', 'BLOCKED', 'yes')];
$m = merge_group($g, $db, [], $emptyCert);
t_check('BLOCKED -> COMPLETED marks ppi_cleared=yes', ($m['drives'][0]['ppi_cleared'] ?? '') === 'yes');

// 2. BLOCKED via status only (no block_sid field) -> still yes.
$g = ['cocid' => 'C1', 'drives' => [drive('S2', 'COMPLETED')], 'reports' => []];
$db = [drive('S2', 'BLOCKED', '')];
$m = merge_group($g, $db, [], $emptyCert);
t_check('status-only BLOCKED still marks ppi_cleared=yes', ($m['drives'][0]['ppi_cleared'] ?? '') === 'yes');

// 3. COMPLETED with no prior BLOCKED -> no.
$g = ['cocid' => 'C1', 'drives' => [drive('S3', 'COMPLETED')], 'reports' => []];
$db = [];
$m = merge_group($g, $db, [], $emptyCert);
t_check('COMPLETED without prior BLOCKED -> ppi_cleared=no', ($m['drives'][0]['ppi_cleared'] ?? '') === 'no');

// 4. Still BLOCKED -> no (never completed).
$g = ['cocid' => 'C1', 'drives' => [drive('S4', 'BLOCKED', 'yes')], 'reports' => []];
$db = [];
$m = merge_group($g, $db, [], $emptyCert);
t_check('still BLOCKED -> ppi_cleared=no', ($m['drives'][0]['ppi_cleared'] ?? '') === 'no');

// 5. Same serial stays BLOCKED across both -> no.
$g = ['cocid' => 'C1', 'drives' => [drive('S5', 'BLOCKED', 'yes')], 'reports' => []];
$db = [drive('S5', 'BLOCKED', 'yes')];
$m = merge_group($g, $db, [], $emptyCert);
t_check('BLOCKED -> BLOCKED -> ppi_cleared=no', ($m['drives'][0]['ppi_cleared'] ?? '') === 'no');

// 6. Different serials don't cross-contaminate.
$g = ['cocid' => 'C1', 'drives' => [drive('S6', 'COMPLETED'), drive('S7', 'BLOCKED', 'yes')], 'reports' => []];
$db = [drive('S6', 'BLOCKED', 'yes')];
$m = merge_group($g, $db, [], $emptyCert);
$bySerial = [];
foreach ($m['drives'] as $d) { $bySerial[$d['serial']] = $d['ppi_cleared'] ?? ''; }
t_check('S6 (prior BLOCKED, now COMPLETED) -> yes', ($bySerial['S6'] ?? '') === 'yes');
t_check('S7 (still BLOCKED) -> no', ($bySerial['S7'] ?? '') === 'no');

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);

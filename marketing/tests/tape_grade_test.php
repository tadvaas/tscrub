<?php
/**
 * Tape cartridge grading: the TapeAlert media-life verdict drives the resale
 * grade (the tape analogue of SMART), and MAM wear counters are surfaced as
 * reasons, never as hard fails. Pure — requires only grading.php.
 *
 *   php tape_grade_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/grading.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; } else { $fails++; echo "FAIL - $label\n"; }
}

function t_eq(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    t_check($label, $ok);
    if (!$ok) {
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
    }
}

// --- native capacity table ---------------------------------------------------
t_eq('LTO-4 native capacity', 762939, tape_native_capacity_mib('LTO-4'));
t_eq('LTO-4 WORM shares the capacity', 762939, tape_native_capacity_mib('LTO-4 WORM'));
t_eq('LTO-9 native capacity', 17166137, tape_native_capacity_mib('LTO-9'));
t_eq('non-tape type has no capacity', null, tape_native_capacity_mib('SATA SSD'));
t_eq('empty type has no capacity', null, tape_native_capacity_mib(''));

// --- the verdict drives the grade -------------------------------------------
$ok = drive_grade(['bus' => 'TAPE', 'type' => 'LTO-4', 'tapemedialife' => 'ok',
    'tapeloadcount' => '108', 'tapewrittenmib' => '45962838', 'tapemanufactured' => '2010-06-02']);
t_eq('ok tape grades A', 'A', $ok['grade']);
t_check('ok tape is graded', $ok['graded'] === true);
t_check('ok tape reasons carry the full-pass estimate',
    in_array('~60 full passes written', $ok['reasons'], true));
t_check('ok tape reasons carry the load count',
    in_array('load count 108', $ok['reasons'], true));
t_check('ok tape reasons carry the manufacture date',
    in_array('manufactured 2010-06-02', $ok['reasons'], true));

$near = drive_grade(['bus' => 'TAPE', 'type' => 'LTO-4', 'tapemedialife' => 'nearing']);
t_eq('nearing tape grades C', 'C', $near['grade']);
t_check('nearing reason is named',
    in_array('tape media life nearing end', $near['reasons'], true));

$dead = drive_grade(['bus' => 'TAPE', 'type' => 'LTO-4', 'tapemedialife' => 'expired']);
t_eq('expired tape grades D', 'D', $dead['grade']);
t_check('expired reason is named',
    in_array('tape media life expired', $dead['reasons'], true));

$none = drive_grade(['bus' => 'TAPE', 'type' => 'LTO-4']);
t_eq('tape with no health reads ungraded', '?', $none['grade']);
t_check('ungraded tape is not graded', $none['graded'] === false);
t_check('ungraded tape reason is tape-specific, not the disk reason',
    $none['reasons'] === ['tape health unavailable — check the drive and cartridge']);

// --- the tape branch is reached BEFORE the SMART-unavailable fallback --------
$tapeNoSmart = drive_grade(['bus' => 'TAPE', 'type' => 'LTO-4', 'tapemedialife' => 'ok']);
t_eq('a tape without SMART still grades from the verdict', 'A', $tapeNoSmart['grade']);
t_check('a tape never gets the disk "reconnect via SATA/NVMe" reason',
    strpos(implode('; ', $tapeNoSmart['reasons']), 'SATA/NVMe') === false);

// --- disks are untouched -----------------------------------------------------
$disk = drive_grade(['smart' => 'PASS', 'reallocsectors' => '0', 'poweronhours' => '1000']);
t_eq('a healthy disk still grades A', 'A', $disk['grade']);

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

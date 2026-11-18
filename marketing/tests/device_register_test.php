<?php
declare(strict_types=1);

/**
 * Devices are created in one place.
 *
 * The defect: "Reassign origin" took a free-text serial and could record the
 * device inline, so the account grew register rows that were counted as
 * tScrub-verified, were missing from the Attested view, and exported as scans
 * with a "First report" that was really the declaration date. Devices are now
 * made on the Devices tab; media are made on the Media tab.
 *
 * Pure checks over the source and over the row shapes — no database, no PDF.
 *
 *   php tests/device_register_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/reports_lib.php';

$count = 0;
$fails = 0;
function t_check(string $label, bool $ok, $detail = null): void {
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; }
    else {
        $fails++;
        echo "FAIL - $label" . ($detail !== null ? ' (got: ' . json_encode($detail) . ')' : '') . "\n";
    }
}

echo "== 1. a declared device is its own source class ==\n";
t_check('list_source accepts declared', list_source('declared') === 'declared');
t_check('list_source still accepts verified and attested',
    list_source('verified') === 'verified' && list_source('attested') === 'attested');
t_check('list_source ignores junk', list_source('declar') === 'all' && list_source('') === 'all');

echo "\n== 2. provenance names what a row is ==\n";
t_check('an observed machine', device_provenance(['sysserial' => 'X']) === 'observed');
t_check('an attested device', device_provenance(['attested' => true]) === 'attested');
t_check('a declared register entry', device_provenance(['declared' => true]) === 'declared');
t_check('a processing host', device_provenance(['bench' => true]) === 'bench');
t_check('declared wins over attested', device_provenance(['declared' => true, 'attested' => true]) === 'declared');

echo "\n== 3. a declared row claims no report dates ==\n";
$row = declared_device_row(['serial' => '5CG91256SC', 'model' => 'HP EliteBook 830 G5',
    'note' => 'no boot', 'created_at' => '2026-11-02 10:00:00']);
t_check('first report is blank', ($row['first'] ?? 'x') === '', $row['first'] ?? null);
t_check('last report is blank', ($row['last'] ?? 'x') === '', $row['last'] ?? null);
t_check('the declaration time is still recorded', ($row['declared_at'] ?? '') === '2026-11-02 10:00:00', $row['declared_at'] ?? null);
t_check('activity_at keeps the sort key', ($row['activity_at'] ?? '') === '2026-11-02 10:00:00', $row['activity_at'] ?? null);
t_check('the row is marked declared', ($row['declared'] ?? false) === true);
t_check('and reads as declared to the provenance helper', device_provenance($row) === 'declared');

echo "\n== 4. the media flow cannot create a device ==\n";
$lib = (string)file_get_contents($serverDir . '/reports_lib.php');
$start = strpos($lib, 'function media_link_declare(');
$end = $start === false ? false : strpos($lib, "\nfunction ", $start + 10);
$declare = $start === false ? '' : substr($lib, $start, ($end === false ? strlen($lib) : $end) - $start);
t_check('media_link_declare has no create-device parameters',
    $declare !== '' && strpos($declare, 'createDevice') === false && strpos($declare, 'deviceModel') === false);
t_check('it never writes the register',
    $declare !== '' && strpos($declare, 'declared_device_set(') === false);
t_check('it gates on the device existing',
    (bool)preg_match('/if \(!device_serial_known\(\$userId, \$assetSerial\)\)/', $declare));
t_check('the refusal tells the operator where to fix it',
    (bool)preg_match('/Record it on the Devices tab first/', $declare));

$api = (string)file_get_contents($serverDir . '/api.php');
t_check('the link route forwards no create-device flag', strpos($api, 'create_device') === false);
t_check('the export has a Provenance column', (bool)preg_match("/'provenance' => 'Provenance'/", $api));
t_check('the export prints provenance, not a raw field',
    (bool)preg_match("/if \(\\\$key === 'provenance'\) return device_provenance\(\\\$d\);/", $api));

echo "\n== 5. a device may be recorded with no media ==\n";
// The media-count gate moved from "at least one" to "at most 64": a unit with no
// data-bearing storage is a real record, and requiring a medium forced operators
// to invent one (or to use the device form for media).
t_check('the attested-device route no longer demands a medium',
    (bool)preg_match("/Record at least one medium/", $api) === false);
t_check('it still caps the media count',
    (bool)preg_match("/Too many media on one device \(64 maximum\)/", $api));

$manual = (string)file_get_contents(__DIR__ . '/../site/public/assets/manual-assets.js');
t_check('a new device form has no media block',
    (bool)preg_match('/var showMedia = \(isEdit \|\| !isDevice\);/', $manual));
t_check('the "media required" rule only fires when a media form is shown',
    (bool)preg_match('/if \(mediaBoxEl && !media\.length\)/', $manual));

echo "\n== 6. the origin form picks an existing device ==\n";
$drives = (string)file_get_contents(__DIR__ . '/../site/dashboard/drives.html');
t_check('the serial field is a picker over Devices',
    strpos($drives, 'origin-device-options') !== false && strpos($drives, "'/api/devices?q='") !== false);
t_check('the create-and-link panel is gone', strpos($drives, 'Record device and link') === false);
t_check('an unknown serial links to the Devices tab', strpos($drives, "'Open Devices'") !== false);

$devices = (string)file_get_contents(__DIR__ . '/../site/dashboard/devices.html');
t_check('the Devices switch has a Declared class',
    (bool)preg_match('/data-source="declared"/', $devices));
t_check('the switch still offers All, tScrub and Attested',
    substr_count($devices, 'data-source="all"') >= 1
    && strpos($devices, 'data-source="verified"') !== false
    && strpos($devices, 'data-source="attested"') !== false);

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);

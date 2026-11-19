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
t_check('only the media form builds a media box — the device modal has none',
    (bool)preg_match('/if \(isDevice\) \{/', $manual)
    && preg_match('/var mediaBox = el\(\'div\', \{ id: \'manual-modal-media\'/', $manual) === 1);
t_check('the "media required" rule fires only when a media form is shown and it is not an edit',
    (bool)preg_match('/if \(mediaBoxEl && !media\.length && !editing\)/', $manual));

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

echo "\n== 7. a declared device belongs to the jobs it is the origin for ==\n";
// Reported: 5CG4374ZPJ came up by serial but not when filtering by COCID.
// A register entry has no report, so the COCID facet had nothing to match; the
// jobs it is named in are the ones whose media it is the declared origin of.
$originRow = declared_device_row([
    'serial' => '5CG4374ZPJ', 'model' => 'HP EliteBook 830 G11',
    'created_at' => '2026-11-04 10:00:00',
], "33219\n33767");
t_check('the row carries the jobs it is the origin for',
    device_facet_values($originRow, 'cocid') === ['33219', '33767'],
    device_facet_values($originRow, 'cocid'));
t_check('and matches a COCID filter for one of them',
    device_filters_match($originRow, device_filters_parse('cocid:33219')));
t_check('but not for a job it is not in',
    !device_filters_match($originRow, device_filters_parse('cocid:99999')));
t_check('a declared row with no origin link matches no COCID',
    device_facet_values(declared_device_row(['serial' => 'X1']), 'cocid') === []);

$lib = (string)file_get_contents($serverDir . '/reports_lib.php');
$start = strpos($lib, 'function declared_devices_rows(');
$end = $start === false ? false : strpos($lib, "\nfunction ", $start + 10);
$rowsBody = $start === false ? '' : substr($lib, $start, ($end === false ? strlen($lib) : $end) - $start);
t_check('the loader reads the origin jobs from media_links',
    $rowsBody !== '' && strpos($rowsBody, 'FROM media_links') !== false);
t_check('and keeps a numeric COCID a string through the array key',
    $rowsBody !== '' && strpos($rowsBody, "'k' . \$c") !== false);

echo "\n== 8. a device with no media stays editable with no media ==\n";
// The same defect as the modal: a device recorded without any media must remain
// editable, while one that HAS media must never be silently emptied by an edit.
$start = strpos($lib, 'function lookup_attested_device(');
$end = $start === false ? false : strpos($lib, "\nfunction ", $start + 10);
$lookupBody = $start === false ? '' : substr($lib, $start, ($end === false ? strlen($lib) : $end) - $start);
t_check('the lookup counts the record\'s own manual media rows',
    $lookupBody !== '' && strpos($lookupBody, 'media_count') !== false
        && strpos($lookupBody, "m.source = 'manual'") !== false);

$api = (string)file_get_contents($serverDir . '/api.php');
$putStart = strpos($api, "if (\$method === 'PUT' && count(\$seg) === 3 && \$seg[0] === 'certificates' && \$seg[1] === 'devices')");
$putBody = $putStart === false ? '' : substr($api, $putStart, 2200);
t_check('the update route looks the record up before the media gate',
    $putBody !== '' && strpos($putBody, 'lookup_attested_device') !== false
        && strpos($putBody, 'lookup_attested_device') < strpos($putBody, 'count($rawMedia) < 1'));
t_check('an empty media list is only refused when the record has media',
    $putBody !== '' && strpos($putBody, "count(\$rawMedia) < 1 && \$own['media_count'] > 0") !== false);
t_check('the 404 still fires before any media validation',
    $putBody !== '' && strpos($putBody, "fail(404, 'Record not found.')") !== false
        && strpos($putBody, "fail(404, 'Record not found.')") < strpos($putBody, 'md_validate_device'));
t_check('an edit that omits media leaves the record\'s media untouched',
    strpos($putBody, "\$media = null") !== false
    && strpos($putBody, "\$d['media'] ?? null") !== false);
t_check('the update helper skips the media rewrite when none was sent',
    strpos($lib, '?array $media') !== false
    && strpos($lib, 'if ($media !== null)') !== false);

echo "\n== 9. Devices → Add is register-only; the attestation lives on edits ==\n";
// Add puts the device on file under its job — identity only. Destruction is
// claimed on the Media tab; the attestation fields exist solely for editing an
// existing attested record, where they ARE the record.
t_check('there is no mode switch and no disclosure',
    strpos($manual, 'Record device only') === false
    && strpos($manual, 'Attest destruction / factory reset') === false
    && strpos($manual, 'attest the outcome') === false);
t_check('the form collects the job and identity fields',
    strpos($manual, "input('Chain of Custody ID (the job this belongs to)', 'cocid', 'COC-…')") !== false
    && strpos($manual, "input('Serial / IMEI / asset tag', 'serial'") !== false
    && strpos($manual, "input('Model', 'model'") !== false
    && strpos($manual, "input('Note', 'note'") !== false);
t_check('the attestation section is built only for edits',
    strpos($manual, "id: 'manual-attest-section'") !== false
    && preg_match('/if \(isEdit\) \{[\s\S]*?manual-attest-section/', $manual) === 1);
t_check('a new device posts a register entry to the declared endpoint',
    strpos($manual, "'/api/devices/declared'") !== false
    && strpos($manual, 'payload = { cocid: cocid.trim(), serial: serial, model: model, note: note };') !== false);
t_check('an attested edit posts the device with no media key',
    strpos($manual, "'/api/certificates/devices'") !== false
    && preg_match('/payload = \{ cocid: cocid\.trim\(\), device: dev \};/', $manual) === 1);
t_check('the COCID is required for every device record',
    strpos($manual, 'if (!cocid.trim()) { return show(\'A Chain of Custody ID is required.\'); }') !== false);

echo "\n== 10. a register device stores the job it was added under ==\n";
$schemaSql = (string)file_get_contents($serverDir . '/schema.sql');
t_check('the schema stores the COCID column',
    strpos($schemaSql, 'cocid       VARCHAR(64)') !== false);
t_check('declared_device_set inserts the COCID',
    strpos($lib, 'note, cocid, created_by') !== false);
t_check('the update helper corrects the COCID too',
    strpos($lib, 'note = ?, cocid = ?') !== false);
t_check('the list merges the stored COCID with the media-link jobs',
    strpos($lib, "'k' . \$stored") !== false);
t_check('the POST route validates the COCID',
    strpos($api, "'A Chain of Custody ID is required (letters, digits, dashes or underscores).'") !== false);

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);

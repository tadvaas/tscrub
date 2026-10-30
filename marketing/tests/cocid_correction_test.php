<?php
declare(strict_types=1);

/**
 * Correcting a mistyped COCID (research/cocid-correction/README.md).
 *
 * Two things are pinned here, and the second is the one that matters:
 *
 *   1. The pure parts — the 5-digit shape the appliance can produce, the file
 *      name rewrite, and the payload rewrite (the COCID lives in THREE places:
 *      the column, the JSON payload the certificate generator reads, and the
 *      uploaded file name).
 *
 *   2. The SURFACE GUARD. Hiding a report only works if every query that feeds a
 *      human view applies reports_visible_sql(). A correction applied to eleven
 *      lists and not the twelfth leaves the mistake visible exactly where nobody
 *      looked — so any `FROM reports` query that neither carries the clause nor
 *      says `visible-ok:` (with a reason) fails this test.
 *
 *   php tests/cocid_correction_test.php
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

echo "== the shape the appliance can produce ==";
t_check('five digits is valid', report_cocid_valid('12345'));
t_check('leading zeros are fine', report_cocid_valid('00001'));
t_check('four digits is not', !report_cocid_valid('1234'));
t_check('six digits is not', !report_cocid_valid('123456'));
t_check('letters are not', !report_cocid_valid('1234A'));
t_check('blank is not', !report_cocid_valid(''));
t_check('untrimmed is not (callers trim first)', !report_cocid_valid(' 12345'));

echo "\n";
echo "== the file name carries the COCID too ==";
t_check('an upload name is rewritten',
    report_correction_rename_file('tscrub_12354_20261030-140230.csv', '12354', '12345') === 'tscrub_12345_20261030-140230.csv');
t_check('a sidecar in the same list is rewritten too',
    report_correction_rename_file('tscrub_12354_1.csv, tscrub_12354_1.json', '12354', '12345')
        === 'tscrub_12345_1.csv, tscrub_12345_1.json');
t_check('an upper-case name is rewritten as well',
    report_correction_rename_file('TSCRUB_12354_1.csv', '12354', '12345') === 'TSCRUB_12345_1.csv');
t_check('an unrelated name is left alone',
    report_correction_rename_file('hand-uploaded.csv', '12354', '12345') === 'hand-uploaded.csv');
t_check('a name containing the new id is not touched',
    report_correction_rename_file('tscrub_12345_1.csv', '12354', '12345') === 'tscrub_12345_1.csv');

echo "\n";
echo "== the rewrite covers column, payload and file name ==";
$row = [
    'cocid'    => '12354',
    'filename' => 'tscrub_12354_20261030-140230.csv, tscrub_12354.json',
    'payload'  => json_encode([
        'cocid'   => '12354',
        'system'  => 'OptiPlex 7070',
        'drives'  => [['serial' => 'WD-ABC123', 'status' => 'COMPLETED']],
        'reports' => [['name' => 'tscrub_12354_20261030-140230.csv', 'sha' => str_repeat('a', 64), 'cocid' => '12354']],
    ]),
];
[$json, $filename] = report_rewrite_cocid($row, '12345');
$out = json_decode($json, true);
t_check('the payload COCID is the new one', ($out['cocid'] ?? '') === '12345', $out['cocid'] ?? null);
t_check('a file record COCID is rewritten', ($out['reports'][0]['cocid'] ?? '') === '12345');
t_check('a file record NAME is rewritten', ($out['reports'][0]['name'] ?? '') === 'tscrub_12345_20261030-140230.csv');
t_check('the filename column is rewritten', $filename === 'tscrub_12345_20261030-140230.csv, tscrub_12345.json');
t_check('the evidence itself is untouched', ($out['drives'][0]['serial'] ?? '') === 'WD-ABC123');
t_check('the file sha is untouched', ($out['reports'][0]['sha'] ?? '') === str_repeat('a', 64));
t_check('nothing else in the payload changes', ($out['system'] ?? '') === 'OptiPlex 7070');

$broken = ['cocid' => '12354', 'filename' => '', 'payload' => 'not json'];
[$json2, $filename2] = report_rewrite_cocid($broken, '12345');
t_check('an undecodable payload is passed through rather than emptied', $json2 === 'not json');

echo "\n";
echo "== SURFACE GUARD: every report query obeys one visibility rule ==";
$files = [$serverDir . '/reports_lib.php', $serverDir . '/api.php'];
$violations = [];
$checked = 0;
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    // Match SQL, not prose: a docblock that says "COCIDs come from reports" is
    // not a query. Every SELECT ... FROM reports [r] statement is one.
    preg_match_all('/select\b[^;]{0,600}?from\s+reports\b/i', $src, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$sql, $pos]) {
        $checked++;
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        // The clause must be part of the statement, or the statement must be
        // explicitly exempted just above it with a reason.
        $behind = substr($src, max(0, $pos - 700), 700);
        $statement = substr($src, $pos, 900);
        $ok = strpos($statement, 'reports_visible_sql') !== false
            || strpos($behind, 'visible-ok:') !== false;
        if (!$ok) {
            $violations[] = basename($file) . ':' . $line;
        }
    }
}
t_check('the scan found the report queries at all (' . $checked . ')', $checked >= 15, $checked);
t_check('no report query bypasses the visibility rule', $violations === [], $violations);

// The exempted ones must say WHY, not just 'visible-ok:'.
$lib = (string)file_get_contents($serverDir . '/reports_lib.php');
t_check('exemptions carry a reason',
    substr_count($lib, 'visible-ok:') >= 6
    && !preg_match('/visible-ok:\s*$/m', $lib));

echo "\n";
echo "== the list's own WHERE carries the clause (Show removed is the exception) ==";
$listFn = (string)file_get_contents($serverDir . '/reports_lib.php');
$start = strpos($listFn, 'function load_user_reports');
$end = $start === false ? false : strpos($listFn, 'function distinct_cocids');
$body = ($start !== false && $end !== false) ? substr($listFn, $start, $end - $start) : '';
t_check('load_user_reports applies the rule when voided rows are not requested',
    $body !== '' && strpos($body, '$where .= \' AND \' . reports_visible_sql();') !== false);
t_check('and it only skips it for the show-voided view',
    $body !== '' && strpos($body, 'if (!$showVoided)') !== false);

echo "\n";
echo "== the dedupe deliberately DOES see hidden uploads ==";
$dedupeStart = strpos($lib, 'function user_ingested_shas');
$dedupeEnd = $dedupeStart === false ? false : strpos($lib, 'function store_reports');
$dedupe = ($dedupeStart !== false && $dedupeEnd !== false) ? substr($lib, $dedupeStart, $dedupeEnd - $dedupeStart) : '';
t_check('user_ingested_shas does not filter, so a hidden file cannot be re-charged',
    $dedupe !== '' && strpos($dedupe, 'reports_visible_sql') === false);

echo "\n== the fix is offered where the record is, not only in the uploads panel ==";
// A diagnostics report has no row in the Media uploads panel (that list is
// erasure-only), so the correction must be reachable from the record views.
// The action lives in the VIEW modal only: a row of View/PDF is a list control,
// and repeating the correction beside every row was noise once the modal had it.
$siteDir = dirname($serverDir) . '/site';
$asset = @file_get_contents($siteDir . '/public/assets/report-corrections.js');
$drivesPage = @file_get_contents($siteDir . '/dashboard/drives.html');
$devicesPage = @file_get_contents($siteDir . '/dashboard/devices.html');
t_check('the corrections module exists', is_string($asset) && $asset !== '');
t_check('it exposes a per-report correction for any page',
    is_string($asset) && strpos($asset, 'correctReport: correctReport') !== false
    && strpos($asset, 'function correctReport(') !== false);
t_check('and a removal for any page',
    is_string($asset) && strpos($asset, 'removeReport: removeReport') !== false);
// The Media page's "Recent uploads" panel is gone: the record views carry the
// correction, so the fold was a second, noisier way to do the same thing.
t_check('the Media uploads panel is gone',
    is_string($drivesPage) && strpos($drivesPage, 'recent-uploads') === false
    && is_string($asset) && strpos($asset, "getElementById('recent-uploads')") === false);
// Rows are list controls: View only. The PDF lives in the record view.
t_check('report rows carry no PDF button (Media)',
    is_string($drivesPage) && substr_count($drivesPage, "'PDF'") === 0);
t_check('report rows carry no PDF link (Devices log)',
    is_string($devicesPage) && substr_count($devicesPage, "'PDF'") === 0);
// The rows keep View / PDF; the correction lives one level in, in the record.
t_check('the Media report rows no longer carry it',
    is_string($drivesPage) && substr_count($drivesPage, 'ReportCorrections.correctReport({ id: h.report_id') === 0);
t_check('nor do the Devices log rows',
    is_string($devicesPage) && substr_count($devicesPage, 'ReportCorrections.correctReport({ id: h.id') === 1);
// The report VIEW (the "Rep" modal) is where an operator reads the diagnostics
// report, and it is the third place they look. Offer it there as well.
t_check('the diagnostics report view offers it',
    is_string($devicesPage) && strpos($devicesPage, 'ReportCorrections.correctReport({ id: dev.pdf_id') !== false);
// The ERASURE record is viewed in two places — its own modal on the Devices
// side and the medium's record view on the Media page — and both must offer it.
t_check('the erasure report view on Devices offers it',
    is_string($devicesPage) && substr_count($devicesPage, 'ReportCorrections.correctReport({ id: h.id') === 1
    && strpos($devicesPage, 'id="erasure-modal-footer"') !== false);
t_check('the medium record view on Media offers it',
    is_string($drivesPage) && strpos($drivesPage, 'ReportCorrections.correctReport({ id: d.report_id') !== false);
t_check('and both record views close after a correction (their heading is stale)',
    is_string($drivesPage) && strpos($drivesPage, 'closeDrive();') !== false
    && is_string($devicesPage) && strpos($devicesPage, 'closeErasure();') !== false);
// A row that cannot say which COCID it belongs to cannot prefill the dialog.
$libRow = is_string($lib) ? $lib : '';
t_check('a device row carries the COCID of the report it was built from',
    strpos($libRow, "'cocid'         => (string)\$r['cocid'],") !== false);
t_check('both pages load the module',
    is_string($drivesPage) && strpos($drivesPage, 'report-corrections.js') !== false
    && is_string($devicesPage) && strpos($devicesPage, 'report-corrections.js') !== false);
t_check('both pages refetch after a correction',
    is_string($drivesPage) && strpos($drivesPage, 'window.onReportCorrected') !== false
    && is_string($devicesPage) && strpos($devicesPage, 'window.onReportCorrected') !== false);
t_check('a page with neither the panel nor the hook reloads rather than going stale',
    is_string($asset) && strpos($asset, 'window.location.reload()') !== false);
// Escape must close the correction dialog, not the modal it was opened over.
// The host pages close their own modals on a document-level Escape, so ours has
// to run in the capture phase and stop the event. Regression: pressed from the
// diagnostics report view, Escape used to dismiss the report underneath.
t_check('Escape is handled in the capture phase',
    is_string($asset) && strpos($asset, "addEventListener('keydown', onKeyDown, true)") !== false);
t_check('and it stops the event reaching the host page',
    is_string($asset) && strpos($asset, 'stopImmediatePropagation') !== false);
t_check('the handler is removed when the dialog closes',
    is_string($asset) && strpos($asset, "removeEventListener('keydown', onKeyDown, true)") !== false);

echo "\n";
if ($fails === 0) {
    echo "ALL TESTS PASSED ($count checks)\n";
    exit(0);
}
echo "$fails of $count checks FAILED\n";
exit(1);

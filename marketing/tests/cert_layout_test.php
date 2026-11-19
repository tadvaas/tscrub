<?php
declare(strict_types=1);

/**
 * Annex A is one page per section.
 *
 * The defect this pins down: the asset block, the destruction method summary and
 * the per-drive table all shared the page after the summary, so the table began
 * wherever the summary happened to end, a long accounting block pushed the
 * summary onto its own page, and a short one left three sections stacked on one.
 * A reader citing "the accounting on page 3" could not rely on it, and a section
 * could be split by the next one starting mid-page.
 *
 * The rule now: the annex title opens page 1, the first section follows it, and
 * every later section opens a fresh page. The per-drive table always gets its own
 * page unless it is the only section (then a title page standing alone would be
 * wasted).
 *
 * Asserted both ways: the rendered PDF's page count for each job shape, and the
 * order of the section calls in the renderer (a page count alone cannot say which
 * section landed where).
 *
 *   php tests/cert_layout_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/jsonld.php';

// TCPDF's QR encoder trips PHP 8.4 deprecations; the PDF is unaffected and the
// server runs 8.3. Silence them so a real warning is visible in the output.
error_reporting(E_ALL & ~E_DEPRECATED);

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

/** One drive row, complete enough for the table, the claims and the counters. */
function drive_row(int $i): array {
    return [
        'ts' => '2026-10-0' . (($i % 9) + 1) . ' 10:0' . ($i % 9) . ':00',
        'device' => 'HP EliteBook 820 G3', 'type' => 'SSD', 'model' => 'SAMSUNG MZNLN512HMJP',
        'serial' => 'S' . str_pad((string)$i, 6, '0', STR_PAD_LEFT), 'size' => '512 GB',
        'bus' => 'SATA', 'cls' => 'PURGE', 'method' => 'ATA Enhanced Secure Erase',
        'status' => 'COMPLETED', 'passes' => '', 'erase_engine' => 'hdparm',
        'verify_technique' => 'Sentinel', 'verify_coverage_pct' => '',
        'tool_version' => 'v1.11.87', 'system' => 'HP EliteBook 820 G3',
        'sysserial' => 'CZC5242GFK', 'duration_secs' => '120',
        'format_result' => '', 'format_fs' => '', 'psid_revert' => '',
        'ppi_requested' => '', 'ppi_cleared' => '',
    ];
}

function cert_group(int $n): array {
    $drives = [];
    for ($i = 1; $i <= $n; $i++) { $drives[] = drive_row($i); }
    return [
        'cocid' => '90001', 'drives' => $drives,
        'reports' => [['name' => 'erasure_1.csv', 'sha' => str_repeat('a', 64), 'state' => 'verified']],
        'devices' => $n, 'methods' => 1, 'runs' => 1,
        'first' => '2026-10-01T10:00:00Z', 'last' => '2026-10-01T10:05:00Z',
        'shaState' => 'verified', 'sigState' => 'attributed', 'tool_version' => 'v1.11.87',
    ];
}

/** An asset whose media were sanitised elsewhere — the DEVICE ACCOUNTING block. */
function asset_row(): array {
    return [
        'asset_serial' => 'PF3BCNVE', 'system_name' => 'LENOVO 20YRS1G00',
        'disposition' => 'media_removed_sanitised',
        'media' => [[
            'serial' => '214432EB1815',
            'disposition' => 'media_removed_sanitised',
            'host_serial' => 'CZC5242GFK',
            'provenance' => 'observed_host',
        ]],
    ];
}

function render_pdf(array $g, array $assets = []): string {
    $issuer = ['name' => 'Test Org', 'reg' => '', 'addr' => '', 'phone' => ''];
    $out = render_certificate_pdf($g, 'COD-TEST-000000-AA-AAA-0000-ZZZZ', true, $issuer, [], [], '', $assets);
    return (string)$out['data'];
}

/** Pages in a TCPDF document (/Type /Page but not /Type /Pages). */
function page_count(string $pdf): int {
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}

echo "== 1. the rendered shapes put each section on its own page ==\n";
// 4 drives, one method, no device accounting:
//   page 1 summary, page 2 title + method summary, page 3 table, page 4 manifest
$plain = render_pdf(cert_group(4));
t_check('no-asset job renders 4 pages', page_count($plain) === 4, page_count($plain));

// Same job with device accounting: the accounting takes page 2 (with the title),
// pushing the method summary and the table onto pages of their own.
$withAssets = render_pdf(cert_group(4), [asset_row()]);
t_check('device accounting adds a page of its own', page_count($withAssets) === 5, page_count($withAssets));

// A single drive is still one table page — the sections, not the rows, set the floor.
t_check('a one-drive job keeps the same floor', page_count(render_pdf(cert_group(1))) === 4, page_count(render_pdf(cert_group(1))));

echo "\n== 2. a job with no drives at all still renders ==\n";
$empty = cert_group(0);
$empty['drives'] = [];
$empty['devices'] = 0;
// The annex page carries the 'no appliance-verified media' line; the manifest
// still gets its own page because that is what a certificate's manifest does.
t_check('an empty job renders the annex page plus the manifest and does not throw',
    page_count(render_pdf($empty)) === 2, page_count(render_pdf($empty)));

echo "\n== 3. the renderer opens a page per section, in this order ==\n";
$src = (string)file_get_contents($serverDir . '/render_cert.php');
$annexStart = strpos($src, 'function cert_render_annex');
$annex = $annexStart === false ? '' : substr($src, $annexStart, strpos($src, 'function cert_render_manifest') - $annexStart);
t_check('cert_render_annex found', $annex !== '');
$posAsset = strpos($annex, "\$section('DEVICE ACCOUNTING')");
$posMethod = strpos($annex, "\$section('DESTRUCTION METHOD SUMMARY')");
$posTable = strpos($annex, "\$section('DEVICE DETAILS')");
t_check('device accounting opens a section', $posAsset !== false);
t_check('the method summary opens a section', $posMethod !== false);
t_check('the device table opens a section', $posTable !== false);
t_check('sections are in document order', $posAsset < $posMethod && $posMethod < $posTable);
t_check('the table is rendered after its own section opener',
    $posTable !== false && strpos($annex, 'cert_render_table(') > $posTable);
// The section opener is the only thing allowed to decide a page break: a stray
// AddPage() inside a section would leave a half-empty page behind.
$opener = '/\$section = function \(string \$title\).*?\n    \};/s';
t_check('one section opener owns the AddPage decision',
    preg_match($opener, $annex, $m) === 1 && substr_count($m[0], 'AddPage()') === 1);
t_check('the method summary no longer draws its own heading inline',
    strpos($annex, "\$pdf->SetXY(\$x, \$y);\n        \$pdf->Cell(\$W - 2 * \$x, 5, 'DESTRUCTION METHOD SUMMARY'") === false);

echo "\n== 4. every section title is drawn by one primitive, at one spacing ==\n";
// The reported defect: DESTRUCTION METHOD SUMMARY sat 1.16 mm under its rule
// while DEVICE ACCOUNTING sat 2.66 mm under its own. The cause was a MultiCell
// continuing from the cursor the heading's Cell left on the rule instead of from
// the Y the heading returns.
t_check('one heading primitive exists (cert_heading)', preg_match('/function cert_heading\(/', $src) === 1);
t_check('its geometry is defined once (cert_head_metrics)', preg_match('/function cert_head_metrics\(/', $src) === 1);
t_check('cert_heading is the only place that measures a title',
    preg_match('/\$m = cert_head_metrics\(\);\s*\$pdf->SetFont\(\'helvetica\', \'B\', \$m\[\'size\'\]\);/', $src) === 1);

$titles = [
    // title => the wrapper that must receive it
    'ERASURE INFORMATION'        => '/\$sectionHeader\([^;]*\'ERASURE INFORMATION\'/',
    'PERSONNEL & AUTHORITY'      => '/\$sectionHeader\([^;]*\'PERSONNEL & AUTHORITY\'/',
    'ATTESTATION'                => '/cert_heading\([^;]*\'ATTESTATION\'/',
    'DEVICE ACCOUNTING'          => '/\$section\(\'DEVICE ACCOUNTING\'\)/',
    'DESTRUCTION METHOD SUMMARY' => '/\$section\(\'DESTRUCTION METHOD SUMMARY\'\)/',
    'DEVICE DETAILS'             => '/\$section\(\'DEVICE DETAILS\'\)/',
    'REPORT MANIFEST'            => '/cert_heading\([^;]*\'REPORT MANIFEST\'/',
];
foreach ($titles as $title => $pattern) {
    t_check('"' . $title . '" is drawn through the heading primitive', preg_match($pattern, $src) === 1);
}
// …and every wrapper delegates, so there is one geometry, not one per page.
t_check('the page-1 section wrapper delegates to cert_heading',
    preg_match('/\$sectionHeader = function[^{]*\{[^}]*cert_heading\(/', $src) === 1);
t_check('the annex section opener delegates to the heading',
    preg_match('/\$heading = function[^{]*\{[^}]*cert_heading\(/', $src) === 1);
t_check('the section opener uses that heading',
    preg_match('/\$section = function[^{]*\{.*?\$heading\(/s', $src) === 1);
t_check('the attested page heading is drawn by cert_heading()',
    preg_match('/cert_heading\(\$pdf, \$x, \$y, \$w, \$heading\)/', $src) === 1);

// Sections whose first row is a MultiCell must place it at the returned Y;
// inheriting the cursor is what produced the uneven gap. Slice from the opener
// to that FIRST MultiCell — a later SetXY further down the page would make the
// check pass while the visible gap stayed wrong.
$until = function (string $from) use ($src): string {
    return preg_match('/' . preg_quote($from, '/') . '.*?MultiCell/s', $src, $m) === 1 ? $m[0] : '';
};
$msi = $until("\$section('DESTRUCTION METHOD SUMMARY');");
t_check('the method summary starts its list at the heading\'s Y',
    $msi !== '' && strpos($msi, 'SetXY($x, $y);') !== false);
$acc = $until("\$section('DEVICE ACCOUNTING');");
t_check('device accounting starts at the heading\'s Y',
    $acc !== '' && strpos($acc, 'SetXY($x, $y);') !== false);
t_check('the manifest starts its list at the heading\'s Y',
    preg_match('/SetY\(cert_heading\([^;]*\'REPORT MANIFEST\'/', $src) === 1);

// No page may reintroduce its own heading weight or a hard-coded offset.
t_check('no page draws a section title with its own font',
    substr_count($src, "SetFont('helvetica', 'B', 10)") === 0
    && substr_count($src, "SetFont('helvetica', 'B', 12)") === 0);

echo "\n== 5. the device table cannot wrap fixed tokens ==\n";
$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
$cols = cert_device_cols();
t_check('the Erased column is gone — the issued-at line covers timing',
    !isset($cols['ts']), implode(',', array_keys($cols)));
t_check('Type, Bus and Size are one Media column',
    isset($cols['media']) && !isset($cols['type']) && !isset($cols['bus']) && !isset($cols['size']));
t_check('media cell stacks type, bus and size on three lines',
    cert_media_text(['type' => 'hdd', 'bus' => 'SATA', 'size' => '320 GB']) === "HDD\nSATA\n320 GB",
    cert_media_text(['type' => 'hdd', 'bus' => 'SATA', 'size' => '320 GB']));
t_check('media cell skips empty parts',
    cert_media_text(['bus' => 'NVMe', 'size' => '512 GB']) === "NVMe\n512 GB");
t_check('media cell empty when all parts are empty', cert_media_text([]) === '');
$jsonld = (string)file_get_contents($serverDir . '/jsonld.php');
t_check('per-drive timestamps stay in the JSON for the customer',
    strpos($jsonld, "'ts'") !== false);

$samples = [
    '#' => '99', 'media' => ['HDD', 'SATA', '1000 GB'], 'cls' => 'SANITISATION',
    'method' => 'Blocked (Block SID)', 'passes' => '99', 'eraser' => 'nwipe 0.41',
    'verify' => 'Sampled 100%', 'tool' => 'tScrub v1.11.15', 'serial' => 'WD-WCC2E1ETKXU6',
    'sysserial' => 'CZC5242GFK', 'asset' => 'CN0WY5HT4294547F', 'status' => 'COMPLETED',
    'format' => 'EXFAT', 'psid' => 'failed', 'ppi' => 'yes', 'ppi_cleared' => 'yes',
];
[$lc, $lk] = cert_table_layout($pdf, $cols, 269.0);
$lsum = 0.0;
foreach ($lc as $c) { $lsum += $c['w']; }
t_check('the laid-out widths never exceed the page', $lsum <= 269.5, $lsum);
foreach ($lc as $k => $c) {
    if (in_array($k, ['model', 'system'], true)) continue;
    $sw = 0.0;
    foreach ((array)($samples[$k] ?? $c['label']) as $line) {
        $sw = max($sw, $pdf->getStringWidth((string)$line, 'helvetica', '', 7.5));
    }
    $want = max($pdf->getStringWidth($c['label'], 'helvetica', 'B', 7.5), $sw) + 3.2;
    t_check("column '$k' is wide enough for its tokens", $c['w'] + 0.01 >= $want, round($c['w'], 2) . ' vs ' . round($want, 2));
}

// A cramped page drops columns in the declared order instead of wrapping.
[$tc, $tk] = cert_table_layout($pdf, $cols, 150.0);
t_check('a cramped page drops verify and origin first',
    !isset($tc['verify']) && !isset($tc['asset']), implode(',', array_keys($tc)));
t_check('the core columns are never dropped',
    isset($tc['#']) && isset($tc['media']) && isset($tc['method']) && isset($tc['serial']),
    implode(',', array_keys($tc)));
// 150 mm is deliberately unachievable (the fixed columns alone need ~189 mm):
// the fallback keeps them at minimum width rather than letting tokens wrap.
$order = ['verify', 'asset', 'format', 'passes', 'tool', 'eraser'];
$prefix = true;
foreach ($order as $i => $a) {
    for ($j = $i + 1; $j < count($order); $j++) {
        if (isset($tc[$order[$j]]) && !isset($tc[$order[$i]])) { $prefix = false; }
    }
}
t_check('drops respect the priority order (prefix property)', $prefix, implode(',', array_keys($tc)));

// 215 mm is the tightest page that can still hold the minimal column set:
// every claim-critical column beyond it must have gone, and the result fits.
[$tc2, $tk2] = cert_table_layout($pdf, $cols, 215.0);
$t2sum = 0.0;
foreach ($tc2 as $c) { $t2sum += $c['w']; }
t_check('a tight-but-achievable page still fits with only the core columns',
    $t2sum <= 215.5 && !isset($tc2['verify']) && !isset($tc2['asset'])
    && isset($tc2['method']) && isset($tc2['serial']),
    round($t2sum, 2) . ' ' . implode(',', array_keys($tc2)));

t_check('wrap: a short fixed token never splits', cert_wrap_text($pdf, 'SATA', 20.0) === 'SATA');
t_check('wrap: words flow onto the next line',
    strpos(cert_wrap_text($pdf, 'HP Z840 Workstation', 20.0), "\n") !== false);
$w1 = cert_wrap_text($pdf, 'ST1000NM0033-9ZM173', 22.0);
$w1ok = true;
foreach (explode("\n", $w1) as $wl) {
    if ($pdf->getStringWidth($wl, 'helvetica', '', 7.5) > 22.0) { $w1ok = false; }
}
t_check('wrap: a long token breaks at its hyphen, every line fits', $w1ok, $w1);
t_check('wrap: empty stays empty', cert_wrap_text($pdf, '   ', 10.0) === '');

t_check('label: two-word headers break only at the space',
    cert_wrap_label($pdf, 'Origin device', 12.0) === "Origin\ndevice",
    cert_wrap_label($pdf, 'Origin device', 12.0));
t_check('label: single words stay whole',
    cert_wrap_label($pdf, 'Verification', 12.0) === 'Verification');

// The table cells carry 1.1 mm of horizontal padding: TCPDF's MultiCell draws
// text at w - 2.2, so wrapping at w - 1.2 left packed lines 1.0 mm too wide
// and MultiCell re-wrapped them mid-token (the orphan "B"/"0" fragments in the
// 33767 Origin device column). Wrap + height must use the same inner width.
$pdf->SetFont('helvetica', '', 7.5); // the renderer measures at the body size
$pdf->setCellPaddings(cert_table_pad_lr(), 0.6, cert_table_pad_lr(), 0.6);
$cellW = 24.6;
t_check('the inner width subtracts both horizontal pads',
    cert_table_inner_w($cellW) === 22.4, cert_table_inner_w($cellW));
$wrapped = cert_wrap_text($pdf, 'CN0WY5HT4294547F005AA00', cert_table_inner_w($cellW));
$wantLines = substr_count($wrapped, "\n") + 1;
t_check('a pre-wrapped cell never re-wraps inside MultiCell',
    $pdf->getNumLines($wrapped, $cellW, false, true) === $wantLines,
    $wrapped . ' -> ' . $pdf->getNumLines($wrapped, $cellW, false, true) . ' lines');
$wlab = cert_wrap_label($pdf, 'Origin device', cert_table_inner_w($cellW));
t_check('a pre-wrapped header never re-wraps inside MultiCell',
    $pdf->getNumLines($wlab, $cellW, false, true) === substr_count($wlab, "\n") + 1);
$trStart = strpos($src, 'function cert_render_table');
$tr = $trStart === false ? '' : substr($src, $trStart, strpos($src, 'function cert_render_annex') - $trStart);
t_check('the table wraps cells at the padded inner width',
    $tr !== '' && substr_count($tr, 'cert_table_inner_w(') >= 2);
t_check('no legacy unpadded wrap remains in the table',
    $tr !== '' && strpos($tr, '- 1.2') === false);

echo "\n== 6. bench-erased media is stated without naming the bench ==\n";
$benchKeys = ['bench01' => true];
$benchDrive = drive_row(1);
$benchDrive['system'] = 'Secret Bench 9000';
$benchDrive['sysserial'] = 'BENCH01';
$benchDrive['bbserial'] = 'SECRETBB01';
$benchDrive['asset_serial'] = 'CUSTOMERPC01';
$benchDrive['origin_provenance'] = 'declared';
// The renderer tags rows before drawing; the redaction helpers only honour the tag.
$benchDrive['host_processing'] = 1;

$normal = drive_row(2);
t_check('a bench host is tagged on its drive row',
    cert_tag_bench_drives([$benchDrive], $benchKeys)[0]['host_processing'] === 1);
t_check('an ordinary host is not tagged',
    cert_tag_bench_drives([$normal], $benchKeys)[0]['host_processing'] === 0);
$pretag = drive_row(3);
$pretag['host_processing'] = 1;
t_check('an existing upstream tag is preserved',
    cert_tag_bench_drives([$pretag], [])[0]['host_processing'] === 1);
t_check('the Machine cell names an ordinary host',
    cert_machine_cell($normal) === 'HP EliteBook 820 G3');
t_check('the Machine cell never names a bench unit',
    cert_machine_cell($benchDrive) === 'Bench unit', cert_machine_cell($benchDrive));
t_check('the Machine SN cell withholds a bench serial',
    cert_machine_sn_cell($benchDrive) === '—', cert_machine_sn_cell($benchDrive));
t_check('the asset-block phrase names an ordinary host',
    cert_host_phrase('CZC5242GFK', $benchKeys) === 'CZC5242GFK');
t_check('the asset-block phrase withholds a bench host',
    cert_host_phrase('BENCH01', $benchKeys) === 'a tScrub bench unit');
t_check('the asset-block phrase covers an unknown host',
    cert_host_phrase('', []) === 'a different host');

$jd = jsonld_drive(jsonld_normalize_drive($benchDrive));
t_check('the JSON-LD keeps the customer origin for a bench erase',
    ($jd['originAsset'] ?? '') === 'CUSTOMERPC01'
    && ($jd['originProvenance'] ?? '') === 'declared');
t_check('the JSON-LD names no bench host or serial',
    !isset($jd['hostSerial'])
    && !isset($jd['properties']['system'])
    && !isset($jd['properties']['system_serial'])
    && !isset($jd['properties']['baseboard_serial']),
    json_encode($jd['properties'] ?? []));
t_check('the JSON-LD states the bench class instead',
    ($jd['hostClass'] ?? '') === 'bench processing unit');
$normalOrig = drive_row(2);
$normalOrig['asset_serial'] = 'CUSTOMERPC02';
$jdN = jsonld_drive(jsonld_normalize_drive($normalOrig));
t_check('an ordinary drive still names its host in the JSON-LD',
    ($jdN['hostSerial'] ?? '') === 'CZC5242GFK'
    && ($jdN['properties']['system_serial'] ?? '') === 'CZC5242GFK'
    && !isset($jdN['hostClass']));

t_check('the renderer and annex both accept bench host keys',
    substr_count($src, 'array $benchHostKeys = []') >= 2);
t_check('the renderer tags drives before the annex renders',
    strpos($src, 'cert_tag_bench_drives($drives, $benchHostKeys)') !== false);
t_check('the annex receives the bench keys',
    strpos($src, "cert_render_annex(\$pdf, 10.0, \$W, \$H, \$certId, \$cocid, \$drives, \$g['reports'], \$assets, \$benchHostKeys);") !== false);
t_check('the table prints the bench footnote',
    strpos($src, 'cert_bench_footnote()') !== false);
t_check('header cells use each column\'s own alignment, like its rows',
    strpos($src, "\$cols[\$key]['w'], \$headerH, \$labels[\$key], 0, \$cols[\$key]['align']") !== false);

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);
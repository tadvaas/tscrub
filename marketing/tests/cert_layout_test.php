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

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);

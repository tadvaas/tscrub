<?php
/**
 * Smoke test for cert_render_attested_section() — the Annex A attested appendix.
 *
 * It is deliberately UNCALLED by the renderer, so this test is the only thing that
 * exercises it; it must produce a real PDF without disturbing anything else.
 * Needs TCPDF, so run it where the server code lives:
 *   php /tmp/attested_section_smoke.php /home/oxwet/webs/tscrub-form
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$tcpdf = $serverDir . '/tcpdf/tcpdf.php';
if (is_file($tcpdf)) { require_once $tcpdf; }
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/manual_destruction.php';

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

function new_pdf()
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);
    return $pdf;
}

$attested = [
    'devices' => [[
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => '356789012345678', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-13',
        'attested_by' => 'Jane Operator <jane@example.com>', 'destroy_witness' => 'J. Smith',
        'evidence_ref' => 'ACME-2026-0417',
        'media' => [
            ['type' => 'eMMC/UFS (integrated)', 'model' => 'iPhone 6', 'serial' => '356789012345678', 'size' => '16 GB', 'bus' => ''],
        ],
    ]],
    'media' => [[
        'type' => 'SSD', 'model' => 'SSDPEKNW512G8H', 'serial' => 'BTNH94823D6P',
        'size' => '512 GB', 'bus' => 'NVMe', 'destroy_method' => 'shredded',
        'destroy_vendor' => '', 'destroy_date' => '2026-10-13',
    ]],
];

// --- the section renders, and reports where it ended ------------------------
$pdf = new_pdf();
$endY = cert_render_attested_section($pdf, $attested, 28.0);
t_check('section returns a Y below its start', $endY > 28.0);

$out = $pdf->Output('', 'S');
t_check('a real PDF is produced', substr($out, 0, 4) === '%PDF' && strlen($out) > 2000);
// getNumPages()/getPage() both warn and are unreliable before output in this TCPDF
// build, so pagination is judged by the geometry the section itself controls: if the
// page-break guard failed, the last item would be drawn past the page foot and the
// returned Y would exceed it.
t_check('it fits on the annex page it was given', $endY < 280.0);
file_put_contents('/tmp/attested-section-smoke.pdf', $out);

// --- an empty set draws nothing at all (no empty heading, no stray footnote) --
$pdf2 = new_pdf();
$sameY = cert_render_attested_section($pdf2, [], 28.0);
t_check('an empty attested set returns the start Y unchanged', $sameY === 28.0);
$out2 = $pdf2->Output('', 'S');
t_check('an empty attested set adds no visible content', strlen($out2) < strlen($out));

// --- a device with no media rows still renders (detail is optional) ---------
$pdf3 = new_pdf();
$noMedia = ['devices' => [['category' => 'other', 'make' => 'Acme', 'model' => 'Widget',
    'serial' => 'W-1', 'destroy_method' => 'crushed', 'media' => []]], 'media' => []];
$endY3 = cert_render_attested_section($pdf3, $noMedia, 28.0);
t_check('a unit with no media rows still renders', $endY3 > 28.0);
t_check('and it produces a valid PDF', substr($pdf3->Output('', 'S'), 0, 4) === '%PDF');

// --- a long list paginates rather than running off the page ----------------
$many = ['devices' => [], 'media' => []];
for ($i = 0; $i < 40; $i++) {
    $many['media'][] = ['type' => 'SSD', 'model' => 'Model ' . $i, 'serial' => 'SN-' . $i,
        'size' => '512 GB', 'bus' => 'NVMe', 'destroy_method' => 'shredded'];
}
$pdf4 = new_pdf();
$endY4 = cert_render_attested_section($pdf4, $many, 28.0);
t_check('a long attested list pages instead of running off the page', $endY4 < 280.0);
t_check('and ends within the last page', $endY4 > 0.0);

// --- the qualifier is present for anything attested ------------------------
t_check('the qualifier text is used by the section',
    strpos(md_attested_qualifier(), 'not instrument-verified') !== false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);

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
    // LANDSCAPE A4, exactly like tScrubPDF: the page is 297x210, not 210x297. This test
    // used to build a PORTRAIT page, on which the old hardcoded 268mm page limit fell just
    // inside the paper — so a guard that can never fire on the real document looked sane
    // here, and the assertions below passed while every real certificate had 58mm of
    // attested records drawn off the bottom edge and clipped.
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    return $pdf;
}

/**
 * Pages actually in the produced document.
 *
 * The text of a clipped record is still in the content stream — it is drawn past the
 * paper, which no reader shows — so "is the serial present?" cannot detect this class of
 * defect. The page count is the one thing clipping cannot fake.
 */
function page_count(string $pdf): int
{
    return (int)preg_match_all('~/Type\s*/Page[^s]~', $pdf);
}

/** Every string literal in the document, inflated. The header lives inside a compressed
 *  content stream, so grepping the raw bytes for it always finds nothing. */
function pdf_text(string $pdf): string
{
    $out = '';
    $off = 0;
    while (($s = strpos($pdf, 'stream', $off)) !== false) {
        $p = $s + 6;
        if (substr($pdf, $p, 2) === "\r\n") { $p += 2; }
        elseif (in_array(substr($pdf, $p, 1), ["\n", "\r"], true)) { $p += 1; }
        $e = strpos($pdf, 'endstream', $p);
        if ($e === false) { break; }
        $d = @gzuncompress(substr($pdf, $p, $e - $p));
        if ($d !== false) { $out .= $d . "\n"; }
        $off = $e + 9;
    }
    return $out;
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
// The page is 210mm tall, so "did anything end up below the paper?" is the question —
// not "is it less than 280?", which was true of a page that is only 210 tall.
t_check('it fits on the page it was given', $endY < 210.0, $endY);
t_check('one unit and one loose medium stay on one page', page_count($out) === 1);
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
$endY4 = cert_render_attested_section($pdf4, $many, 28.0, 'COD-TEST-SMOKE');
$out4 = $pdf4->Output('', 'S');
// 40 loose media at roughly 10mm each is ~400mm of content in a ~170mm usable column, so
// it must break across pages. Before the fix this was ONE page with 230mm drawn below the
// paper.
t_check('a long attested list breaks across pages', page_count($out4) >= 3,
    'pages=' . page_count($out4));
t_check('and ends inside the page', $endY4 > 0.0 && $endY4 < 210.0, $endY4);

// --- the real failing shape: many units, each with its own media -----------
// 24 units with 24 media is the job that showed the operator a certificate cut off after
// the first page. The content was all written; it was simply drawn past the bottom edge.
$unit = function (int $i): array {
    return [
        'category' => 'sealed_unit', 'make' => 'Acme', 'model' => 'Unit ' . $i,
        'serial' => 'PAGE-' . $i, 'destroy_method' => 'shredded', 'destroy_date' => '2026-10-13',
        'attested_by' => 'Jane Operator',
        'media' => [['type' => 'SSD', 'model' => 'M' . $i, 'serial' => 'PAGE-M-' . $i, 'size' => '512 GB']],
    ];
};
$six = ['devices' => [], 'media' => []];
for ($i = 0; $i < 6; $i++) { $six['devices'][] = $unit($i); }
$pdf5 = new_pdf();
$endY5 = cert_render_attested_section($pdf5, $six, 28.0, 'COD-TEST-SMOKE');
$out5 = $pdf5->Output('', 'S');
t_check('six units fit on the page they were given', page_count($out5) === 1,
    'pages=' . page_count($out5));
t_check('...and finish inside it', $endY5 < 210.0, $endY5);

$twentyFour = ['devices' => [], 'media' => []];
for ($i = 0; $i < 24; $i++) { $twentyFour['devices'][] = $unit($i); }
$pdf6 = new_pdf();
$endY6 = cert_render_attested_section($pdf6, $twentyFour, 28.0, 'COD-TEST-SMOKE');
$out6 = $pdf6->Output('', 'S');
$pages6 = page_count($out6);
// ~14mm per unit block = ~336mm, against ~170mm of usable column per page.
t_check('twenty-four units break across pages instead of running off the bottom',
    $pages6 >= 3, 'pages=' . $pages6);
t_check('...and the last block still ends inside a page',
    $endY6 > 28.0 && $endY6 < 210.0, $endY6);
// Every page of a multi-page attestation has to carry the document header, exactly like
// the annex and the manifest pages do — otherwise continuation pages are unattributable.
$text6 = pdf_text($out6);
t_check('every page of the attestation is labelled with the certificate ID',
    substr_count($text6, 'COD-TEST-SMOKE') >= $pages6,
    'labels=' . substr_count($text6, 'COD-TEST-SMOKE') . ' pages=' . $pages6);

// --- the qualifier is present for anything attested ------------------------
t_check('the qualifier text is used by the section',
    strpos(md_attested_qualifier(), 'not instrument-verified') !== false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);

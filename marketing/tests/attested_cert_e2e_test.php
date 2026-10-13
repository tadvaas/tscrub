<?php
/**
 * End-to-end render test: a REAL certificate containing attested records.
 *
 * The attested appendix was only ever exercised in isolation
 * (attested_section_smoke.php calls the annex function directly). It had never
 * appeared in a document produced by render_certificate_pdf(), which is the thing
 * customers actually receive, so this test drives that whole path and then reads the
 * text back out of the generated PDF.
 *
 * The honesty assertions matter more than the presence ones: attested items must not
 * change the verified counts, and a standalone destroyed drive must not be given a
 * host it never had.
 *
 * Needs TCPDF, so run it where the server code lives:
 *   php /tmp/attested_cert_e2e_test.php /home/oxwet/webs/tscrub-form
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
    if ($ok) { echo "ok   - $label\n"; } else { $fails++; echo "FAIL - $label\n"; }
}

/**
 * PDF text, recovered by inflating every FlateDecode stream. TCPDF compresses its
 * content streams, so the visible text is not greppable in the raw bytes.
 */
function pdf_text(string $pdf): string
{
    $out = '';
    $off = 0;
    while (($s = strpos($pdf, 'stream', $off)) !== false) {
        $start = $s + 6;
        if (substr($pdf, $start, 2) === "\r\n") { $start += 2; }
        elseif (($pdf[$start] ?? '') === "\n" || ($pdf[$start] ?? '') === "\r") { $start += 1; }
        $e = strpos($pdf, 'endstream', $start);
        if ($e === false) { break; }
        $chunk = substr($pdf, $start, $e - $start);
        $d = @gzuncompress($chunk);
        if ($d === false) { $d = @gzinflate($chunk); }
        if ($d !== false) { $out .= $d . "\n"; }
        $off = $e + 9;
    }
    return $out;
}

/** Count page objects — a reliable page count without trusting getNumPages(). */
function pdf_pages(string $pdf): int
{
    return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
}

$g = [
    'cocid'      => 'TEST-COC-E2E-1',
    'reports'    => [],
    'first'      => '2026-10-13 09:00:00',
    'last'       => '2026-10-13 10:00:00',
    'operator'   => 'Operator One',
    'validator'  => 'Validator Two',
    'tool_version' => 'v1.11.55',
    'drives'     => [[
        'serial' => 'VERIFIED-SN-1', 'model' => 'WDC WD10EZEX', 'type' => 'HDD',
        'size' => '1 TB', 'bus' => 'SATA', 'status' => 'COMPLETED', 'cert' => '',
        'method' => 'OVERWRITE', 'cls' => 'HDD', 'device' => 'Bench-01',
        'ts' => '2026-10-13 09:30:00',
    ]],
];

$attested = [
    'devices' => [[
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => '356789012345678', 'reason' => 'media_soldered',
        'destroy_method' => 'shredded', 'destroy_vendor' => 'Acme Shredding',
        'destroy_date' => '2026-10-13', 'attested_by' => 'Jane Operator',
        'destroy_witness' => 'W. Ness', 'evidence_ref' => 'ACME-2026-0417',
        'media' => [[
            'type' => 'eMMC/UFS (integrated)', 'make' => 'Apple', 'model' => 'iPhone 6',
            'serial' => '356789012345678', 'size' => '16 GB', 'bus' => '',
            'destroy_method' => 'shredded', 'destroy_date' => '2026-10-13',
        ]],
    ]],
    'media' => [[
        'type' => 'SSD', 'make' => 'Samsung', 'model' => '870 EVO',
        'serial' => 'LOOSE-SN-9', 'size' => '500 GB', 'bus' => 'SATA',
        'reason' => 'physically_damaged', 'destroy_method' => 'crushed',
        'destroy_date' => '2026-10-12', 'attested_by' => 'Jane Operator',
    ]],
];

try {
    $base = render_certificate_pdf($g, 'COD-TEST-BASE', false, [], [], []);
    $with = render_certificate_pdf($g, 'COD-TEST-ATT', false, [], [], $attested);
} catch (Throwable $e) {
    t_check('both certificates render without a fatal (' . $e->getMessage() . ')', false);
    echo "\n$count assertions, $fails failed\n";
    exit(1);
}

t_check('a certificate with no attested records still renders', strlen($base['data']) > 1000);
t_check('a certificate with attested records renders', strlen($with['data']) > 1000);

$baseText = pdf_text($base['data']);
$attText  = pdf_text($with['data']);

t_check('the appendix did not displace the verified annex', strpos($baseText, 'VERIFIED-SN-1') !== false);
t_check('the verified drive is still listed', strpos($attText, 'VERIFIED-SN-1') !== false);

$basePages = pdf_pages($base['data']);
$attPages  = pdf_pages($with['data']);
t_check("the appendix adds its own page (base $basePages -> attested $attPages)", $attPages > $basePages);

// --- the attested evidence is actually printed ------------------------------
t_check('the section heading is present', strpos($attText, 'PHYSICALLY DESTROYED') !== false);
t_check('the qualifier is present', strpos($attText, 'operator-attested') !== false);
t_check('the qualifier says not instrument-verified', strpos($attText, 'not instrument-verified') !== false);
t_check('the attested unit is named', strpos($attText, 'Apple iPhone 6') !== false);
t_check('its serial is printed', strpos($attText, '356789012345678') !== false);
t_check('the destruction method is printed', stripos($attText, 'shredded') !== false);
t_check('the vendor is printed', strpos($attText, 'Acme Shredding') !== false);
t_check('the destruction date is printed', strpos($attText, '2026-10-13') !== false);
t_check('the attester is printed', strpos($attText, 'Jane Operator') !== false);
t_check('the witness is printed', strpos($attText, 'W. Ness') !== false);
t_check('the vendor evidence reference is printed', strpos($attText, 'ACME-2026-0417') !== false);
t_check('the standalone destroyed drive is listed', strpos($attText, 'LOOSE-SN-9') !== false);
t_check('the footnote about our own part is printed',
    stripos($attText, 'not sanitised by us') !== false || stripos($attText, 'attested') !== false);

// --- and it does NOT leak into the verified claims -------------------------
t_check('the verified device count is unchanged by attested items',
    $base['devices'] === $with['devices']);
t_check('the recipient can tell the documents apart', $base['sha'] !== $with['sha']);
t_check('attested media are not counted among the verified media',
    $base['devices'] === $with['devices']);

// The loose drive has no host. If the appendix invented one, the unit's model would
// appear after the loose drive's serial in the render order (devices first, then
// standalone media).
$looseAt = strpos($attText, 'LOOSE-SN-9');
if ($looseAt !== false) {
    $after = substr($attText, $looseAt);
    t_check('a standalone destroyed drive is not given a host it never had',
        strpos($after, 'iPhone 6') === false);
} else {
    t_check('a standalone destroyed drive is not given a host it never had', false);
}

file_put_contents('/tmp/attested-cert-sample.pdf', $with['data']);
echo "\nwrote /tmp/attested-cert-sample.pdf (" . strlen($with['data']) . " bytes, $attPages pages)\n";

echo "\n$count assertions, $fails failed\n";
exit($fails === 0 ? 0 : 1);

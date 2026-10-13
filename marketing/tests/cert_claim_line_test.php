<?php
/**
 * The certificate's claim line, for the three kinds of job it can describe.
 *
 * Page 1 is the only page most recipients read, and it is the page that makes the
 * legal claim, so its wording is asserted here rather than eyeballed. The three
 * cases are genuinely different documents:
 *
 *   1. verified only  — everything was sanitised by the appliance (the long-standing
 *                       wording, which must NOT change);
 *   2. mixed          — verified items plus operator-attested destruction;
 *   3. attested only  — nothing ran through the appliance at all.
 *
 * Case 3 is why this test exists. Such a job has zero verified media, so the old
 * banner read 'DATA DESTROYED ON ALL 0 MEDIA ITEMS' and the attestation still
 * claimed 'tScrub was used as the sanitisation tool' — a signed statement that the
 * tool sanitised data it never touched. It also printed 'CERTIFICATION DATE N/A'
 * even though the operator had recorded exactly when each item was destroyed.
 *
 * Needs TCPDF, so run it where the server code lives:
 *   php cert_claim_line_test.php /home/oxwet/webs/tscrub-form
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

/** PDF text, recovered by inflating every FlateDecode stream (TCPDF compresses). */
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

/**
 * The visible text as one continuous string.
 *
 * Two things make naively searching the raw streams fail, and both bit this test:
 * a literal '(' in the certificate is written as '\(' in the PDF, and a wrapped
 * paragraph is emitted as one string per visual line, so a sentence can be split
 * across two of them. Extracting the string literals in order, undoing the escapes
 * and joining them with a single space reassembles the sentence that was written.
 */
function pdf_lines(string $pdf): string
{
    $raw = pdf_text($pdf);
    $len = strlen($raw);
    $parts = [];
    $i = 0;
    while ($i < $len) {
        if ($raw[$i] !== '(') { $i++; continue; }
        $i++;
        $buf = '';
        $depth = 1;
        while ($i < $len && $depth > 0) {
            $c = $raw[$i];
            if ($c === '\\') {
                $nxt = $raw[$i + 1] ?? '';
                if (preg_match('/[0-7]/', $nxt)) {
                    $oct = '';
                    $j = $i + 1;
                    while (strlen($oct) < 3 && $j < $len && preg_match('/[0-7]/', $raw[$j])) {
                        $oct .= $raw[$j];
                        $j++;
                    }
                    $buf .= chr((int)octdec($oct));
                    $i = $j;
                    continue;
                }
                $buf .= $nxt;
                $i += 2;
                continue;
            }
            if ($c === '(') { $depth++; }
            if ($c === ')') {
                $depth--;
                $i++;
                if ($depth === 0) { break; }
                $buf .= ')';
                continue;
            }
            $buf .= $c;
            $i++;
        }
        $parts[] = $buf;
    }
    // No /u flag: the streams carry non-UTF-8 bytes (fonts, metadata), and the unicode
    // modifier makes preg_replace return null on invalid input — which silently emptied
    // this whole extraction the first time it was written.
    $joined = implode(' ', $parts);
    $flat = preg_replace('/\s+/', ' ', $joined);
    return trim($flat === null ? $joined : $flat);
}

function drive(string $serial, string $status): array
{
    return [
        'serial' => $serial, 'model' => 'WDC WD10EZEX', 'type' => 'HDD',
        'size' => '1 TB', 'bus' => 'SATA', 'status' => $status, 'cert' => '',
        'method' => 'OVERWRITE', 'cls' => 'HDD', 'device' => 'Bench-01',
        'ts' => '2026-10-13 09:30:00',
    ];
}

function group(array $drives, ?string $first = '2026-10-13 09:00:00', ?string $last = '2026-10-13 10:00:00'): array
{
    return [
        'cocid'        => 'TEST-COC-CLAIM',
        'reports'      => [],
        'first'        => $first,
        'last'         => $last,
        'operator'     => 'Operator One',
        'validator'    => '',
        'tool_version' => 'v1.11.55',
        'shaState'     => 'verified',
        'sigState'     => 'none',
        'drives'       => $drives,
    ];
}

function attested_pair(): array
{
    return [
        'devices' => [
            [
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
            ],
            [
                'category' => 'tablet', 'make' => 'Samsung', 'model' => 'Galaxy Tab A',
                'serial' => 'ATTTAB-0002', 'reason' => 'battery_unsafe',
                'destroy_method' => 'crushed', 'destroy_vendor' => 'OnSite Destruction Co',
                'destroy_date' => '2026-10-14', 'attested_by' => 'Jane Operator',
                'destroy_witness' => 'A. Patel', 'evidence_ref' => 'OSD-4471',
                'media' => [[
                    'type' => 'eMMC/UFS (integrated)', 'make' => 'Samsung', 'model' => 'Galaxy Tab A',
                    'serial' => 'ATTTAB-0002', 'size' => '32 GB', 'bus' => '',
                    'destroy_method' => 'crushed', 'destroy_date' => '2026-10-14',
                ]],
            ],
        ],
        'media' => [],
    ];
}

try {
    $verifiedOnly = pdf_lines(render_certificate_pdf(
        group([drive('VER-A', 'COMPLETED'), drive('VER-B', 'COMPLETED')]),
        'COD-CLAIM-VER', false, [], [], []
    )['data']);

    $mixed = pdf_lines(render_certificate_pdf(
        group([drive('VER-C', 'COMPLETED')]),
        'COD-CLAIM-MIX', false, [], [], attested_pair()
    )['data']);

    // A pure-attestation job has no appliance run at all, so the route supplies no run
    // timestamps. Passing the timestamps here would test a document that cannot occur.
    $attestedOnly = pdf_lines(render_certificate_pdf(
        group([], null, null),
        'COD-CLAIM-ATT', false, [], [], attested_pair()
    )['data']);
} catch (Throwable $e) {
    t_check('all three job shapes render without a fatal (' . $e->getMessage() . ')', false);
    echo "\n$count tests, $fails failures\n";
    exit(1);
}

foreach (['verified only' => $verifiedOnly, 'mixed' => $mixed, 'attested only' => $attestedOnly] as $name => $t) {
    t_check("$name: a certificate was produced", strlen($t) > 500);
}

// --- 1. verified only: the original wording, unchanged -----------------------
t_check('verified only: the banner still claims all media destroyed',
    strpos($verifiedOnly, 'DATA DESTROYED ON ALL 2 MEDIA ITEMS') !== false);
t_check('verified only: the attestation still names the tool as the sanitiser',
    strpos($verifiedOnly, 'was used as the sanitisation tool.') !== false);
t_check('verified only: nothing mentions operator attestation',
    stripos($verifiedOnly, 'operator attestation') === false);
t_check('verified only: there is no attested section',
    strpos($verifiedOnly, 'PHYSICALLY DESTROYED') === false);
t_check('verified only: the scope line still points at Annex A alone',
    strpos($verifiedOnly, 'recorded in Annex A') !== false);

// --- 2. mixed: both figures, never summed ------------------------------------
t_check('mixed: the verified figure is stated as verified',
    strpos($mixed, '1 MEDIA SANITISED IN PLACE (VERIFIED)') !== false);
t_check('mixed: the attested figure is stated as attested',
    strpos($mixed, 'MEDIA DESTROYED (ATTESTED') !== false);
t_check('mixed: the tool claim is qualified by the attested caveat',
    strpos($mixed, 'operator attestation') !== false);
t_check('mixed: the attested section is present',
    strpos($mixed, 'PHYSICALLY DESTROYED') !== false);
t_check('mixed: the run timestamps still set the certification date (no N/A)',
    strpos($mixed, '09:00') !== false);
t_check('mixed: the scope line names both annexes',
    strpos($mixed, 'the operator attestation section') !== false);

// --- 3. attested only: no false claim anywhere -------------------------------
t_check('attested only: the banner states destroyed-by-attestation, not a zero count',
    strpos($attestedOnly, '2 MEDIA DESTROYED (ATTESTED, 2 DEVICES)') !== false);
t_check('attested only: no "all N media items" claim is made',
    strpos($attestedOnly, 'DATA DESTROYED ON ALL') === false);
t_check('attested only: the annex explains there is nothing verified',
    strpos($attestedOnly, 'No appliance-verified media in this job.') !== false);
t_check('attested only: the attestation does NOT claim the tool sanitised anything',
    strpos($attestedOnly, 'was used as the sanitisation tool') === false);t_check('attested only: the attestation disclaims instrument verification',
    strpos($attestedOnly, 'operator attestation only') !== false);
t_check('attested only: the certification date comes from the recorded destroy dates',
    strpos($attestedOnly, '2026-10-13 to 2026-10-14') !== false);
t_check('attested only: the scope line points at the attested section',
    strpos($attestedOnly, 'the operator attestation section') !== false);
t_check('attested only: the qualifier is still printed',
    stripos($attestedOnly, 'not instrument-verified') !== false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);

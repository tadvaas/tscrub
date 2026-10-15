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
    $flat = trim($flat === null ? $joined : $flat);

    // The certificate uses the core Helvetica fonts, which are WinAnsi-encoded: an em
    // dash is written as the single octal escape \227, not as UTF-8. Left alone, that byte
    // is invalid UTF-8 and every assertion containing a typographic character fails for a
    // reason that has nothing to do with the certificate.
    if ($flat !== '' && !mb_check_encoding($flat, 'UTF-8')) {
        $conv = @mb_convert_encoding($flat, 'UTF-8', 'Windows-1252');
        if ($conv !== false && $conv !== '') { $flat = $conv; }
    }

    return $flat;
}

/**
 * The value that follows a page-1 meta label — used to read the certification date.
 *
 * Page 1 writes the three labels first and then the three values, so the text after the
 * LAST label begins with the certification date. The label must be matched in its
 * uppercase form: the subtitle also contains "Chain of Custody ID", and strpos is
 * case-sensitive.
 */
function meta_value_after(string $lines, string $label): string
{
    $i = strpos($lines, $label);
    if ($i === false) { return ''; }
    return trim(substr($lines, $i + strlen($label)));
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

/** A destroyed unit with no recorded medium: the operator may not have recorded the disk. */
function attested_no_media(): array
{
    return ['devices' => [[
        'category' => 'laptop', 'make' => 'Dell', 'model' => 'Latitude E5470',
        'serial' => 'NOMEDIA-1', 'reason' => 'would_not_boot', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-15',
        'attested_by' => 'Jane Operator', 'destroy_witness' => 'W. Ness',
        'media' => [],
    ]], 'media' => []];
}

/** Standalone destroyed media with no host unit at all. */
function attested_loose_only(): array
{
    return ['devices' => [], 'media' => [[
        'type' => 'SSD', 'make' => 'Samsung', 'model' => '870 EVO', 'serial' => 'LOOSE-9',
        'size' => '500 GB', 'bus' => 'SATA', 'reason' => 'physically_damaged',
        'destroy_method' => 'crushed', 'destroy_date' => '2026-10-12',
        'attested_by' => 'Jane Operator',
    ]]];
}

/** Nothing recorded for WHEN the destruction happened. */
function attested_undated(): array
{
    return ['devices' => [[
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 5',
        'serial' => 'UNDATED-1', 'reason' => 'media_soldered', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '', 'attested_by' => 'Jane Operator',
        'media' => [[
            'type' => 'eMMC/UFS (integrated)', 'make' => 'Apple', 'model' => 'iPhone 5',
            'serial' => 'UNDATED-1', 'size' => '16 GB', 'bus' => '',
            'destroy_method' => 'shredded', 'destroy_date' => '',
        ]],
    ]], 'media' => []];
}

/** A date typed by hand in a spreadsheet, not the canonical Y-m-d form. */
function attested_odd_date(): array
{
    return ['devices' => [[
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 4',
        'serial' => 'ODDDATE-1', 'reason' => 'media_soldered', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '13/10/2026',
        'attested_by' => 'Jane Operator',
        'media' => [[
            'type' => 'eMMC/UFS (integrated)', 'make' => 'Apple', 'model' => 'iPhone 4',
            'serial' => 'ODDDATE-1', 'size' => '8 GB', 'bus' => '',
            'destroy_method' => 'shredded', 'destroy_date' => '13/10/2026',
        ]],
    ]], 'media' => []];
}

/**
 * The case this vocabulary exists for: network kit and phones the appliance cannot boot at
 * all. They were reset using their own facilities and kept, so nothing was destroyed and
 * the device is still in the fleet — but the operator still has to record that it happened
 * and that the data is gone, which is what an attestation is for.
 */
function attested_reset(): array
{
    return ['devices' => [
        [
            'category' => 'ip_phone', 'make' => 'Cisco', 'model' => '7942G',
            'serial' => 'FCW1234ABCD', 'reason' => 'not_supported_by_appliance',
            'destroy_method' => 'factory_reset', 'destroy_vendor' => '',
            'destroy_date' => '2026-10-16', 'attested_by' => 'Jane Operator',
            'destroy_witness' => 'W. Ness', 'evidence_ref' => 'IT-2026-88',
            'media' => [[
                'type' => 'Flash (integrated)', 'make' => 'Cisco', 'model' => '7942G',
                'serial' => 'FCW1234ABCD', 'size' => '', 'bus' => '',
                'destroy_method' => 'factory_reset', 'destroy_date' => '2026-10-16',
            ]],
        ],
        [
            'category' => 'switch', 'make' => 'Cisco', 'model' => 'C2960-24',
            'serial' => 'FOC9876XYZ', 'reason' => 'not_supported_by_appliance',
            'destroy_method' => 'factory_reset', 'destroy_date' => '2026-10-16',
            'attested_by' => 'Jane Operator', 'media' => [],
        ],
    ], 'media' => []];
}

/** Destroyed and retained items on the same certificate — the figures must stay apart. */
function attested_reset_and_destroy(): array
{
    $pair = attested_pair();
    $reset = attested_reset();
    return ['devices' => array_merge($pair['devices'], $reset['devices']), 'media' => []];
}

/**
 * One unit destroyed with its media, EXCEPT one drive pulled out and reset. The banner
 * already counted the two outcomes apart; the item detail did not, so the bullet sat
 * silently under a line reading "Destroyed" while the summary said "RETAINED".
 */
function attested_mixed_unit(): array
{
    return ['devices' => [[
        'category' => 'laptop', 'make' => 'Dell', 'model' => 'Latitude E5470',
        'serial' => 'MIXED-1', 'reason' => 'beyond_economic_repair', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-17',
        'attested_by' => 'Jane Operator',
        'media' => [
            ['type' => 'eMMC/UFS (integrated)', 'model' => 'Latitude E5470', 'serial' => 'MIXED-1',
             'size' => '128 GB', 'destroy_method' => 'shredded'],
            ['type' => 'SSD', 'model' => '870 EVO', 'serial' => 'MIXED-SSD-1', 'size' => '500 GB',
             'bus' => 'SATA', 'destroy_method' => 'factory_reset', 'destroy_date' => '2026-10-17'],
        ],
    ]], 'media' => []];
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

    $noMedia = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-NOMEDIA', false, [], [], attested_no_media()
    )['data']);

    $looseOnly = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-LOOSE', false, [], [], attested_loose_only()
    )['data']);

    $undated = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-UNDATED', false, [], [], attested_undated()
    )['data']);

    $oddDate = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-ODDDATE', false, [], [], attested_odd_date()
    )['data']);

    // The appliance ran, one medium FAILED, and other items were attested: the failure
    // warning has to win the banner, but the attested figures must still be stated.
    $failedMixed = pdf_lines(render_certificate_pdf(
        group([drive('VER-F', 'FAILED')]), 'COD-CLAIM-FAILMIX', false, [], [], attested_pair()
    )['data']);

    $resetOnly = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-RESET', false, [], [], attested_reset()
    )['data']);

    $resetAndDestroy = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-RESETMIX', false, [], [], attested_reset_and_destroy()
    )['data']);

    $mixedUnit = pdf_lines(render_certificate_pdf(
        group([], null, null), 'COD-CLAIM-MIXEDUNIT', false, [], [], attested_mixed_unit()
    )['data']);
} catch (Throwable $e) {
    t_check('all three job shapes render without a fatal (' . $e->getMessage() . ')', false);
    echo "\n$count tests, $fails failures\n";
    exit(1);
}

foreach (['verified only' => $verifiedOnly, 'mixed' => $mixed, 'attested only' => $attestedOnly,
          'no media' => $noMedia, 'loose media only' => $looseOnly, 'undated' => $undated,
          'odd date' => $oddDate, 'failed + attested' => $failedMixed,
          'factory reset' => $resetOnly, 'reset + destroy' => $resetAndDestroy,
          'mixed unit' => $mixedUnit] as $name => $t) {
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

// --- 4. edge: a destroyed unit with no recorded medium -----------------------
// This used to render '0 MEDIA DESTROYED (ATTESTED, 1 DEVICE)' — a banner contradicting
// itself in one sentence, on a certificate, about a destroyed machine.
t_check('no media: the banner leads with the device, not a zero media count',
    strpos($noMedia, '1 DEVICE DESTROYED (ATTESTED, NO MEDIA RECORDED)') !== false);
t_check('no media: the banner never says zero media were destroyed',
    strpos($noMedia, '0 MEDIA DESTROYED') === false);
t_check('no media: the unit is still named', strpos($noMedia, 'NOMEDIA-1') !== false);
t_check('no media: the missing medium is stated rather than implied',
    strpos($noMedia, 'NO MEDIA RECORDED') !== false);
t_check('no media: the certification date still comes from the device',
    strpos(meta_value_after($noMedia, 'CHAIN OF CUSTODY ID'), '2026-10-15') === 0);

// --- 5. edge: standalone destroyed media, no host unit ----------------------
t_check('loose media only: the banner claims the media, not a device',
    strpos($looseOnly, '1 MEDIA DESTROYED (ATTESTED)') !== false);
t_check('loose media only: no phantom ", 0 DEVICES" is printed',
    strpos($looseOnly, '0 DEVICES') === false);
t_check('loose media only: the medium is listed', strpos($looseOnly, 'LOOSE-9') !== false);
t_check('loose media only: the certification date comes from the medium',
    strpos(meta_value_after($looseOnly, 'CHAIN OF CUSTODY ID'), '2026-10-12') === 0);

// --- 6. edge: dates that cannot be trusted ----------------------------------
// An unparseable date must not be invented into the summary, but it must not be hidden
// either: the item line still prints exactly what the operator entered.
t_check('undated: no certification date is invented',
    strpos(meta_value_after($undated, 'CHAIN OF CUSTODY ID'), 'N/A') === 0);
t_check('odd date: a spreadsheet-typed date is not turned into a range',
    strpos(meta_value_after($oddDate, 'CHAIN OF CUSTODY ID'), 'N/A') === 0);
t_check('odd date: the date as entered is still printed against its item',
    strpos($oddDate, '13/10/2026') !== false);
t_check('odd date: no range is built from the malformed value',
    strpos($oddDate, '13/10/2026 to') === false);

// --- 7. edge: failures present AND attested items ---------------------------
t_check('failed + attested: the failure warning wins the banner',
    strpos($failedMixed, '1 MEDIA ITEM NOT DESTROYED — SEE ANNEX A') !== false);
t_check('failed + attested: no all-destroyed claim is made',
    strpos($failedMixed, 'DATA DESTROYED ON ALL') === false);
t_check('failed + attested: the attested figures are still stated',
    strpos($failedMixed, '2 items destroyed by attestation') !== false);
t_check('failed + attested: the attested section still renders',
    strpos($failedMixed, 'PHYSICALLY DESTROYED') !== false);
t_check('failed + attested: a verified item is still reported as verified',
    strpos($failedMixed, '1 verified media item') !== false);

// --- 8. factory reset: retained, so nothing may be called destroyed ----------
// The whole point of deriving the verb from the method: a device that was reset and handed
// back must never be certified as destroyed, in the operator's name.
t_check('factory reset: the banner says retained, not destroyed',
    strpos($resetOnly, '1 MEDIA FACTORY RESET (ATTESTED, RETAINED, 2 DEVICES)') !== false);
t_check('factory reset: no destruction claim is made on page 1',
    strpos($resetOnly, 'MEDIA DESTROYED') === false
    && strpos($resetOnly, 'DATA DESTROYED ON ALL') === false);
t_check('factory reset: the item line states retention',
    strpos($resetOnly, 'Factory reset (2026-10-16) — device retained') !== false);
t_check('factory reset: no item carries the destroyed verb',
    strpos($resetOnly, 'Destroyed — ') === false);
t_check('factory reset: the section heading does not claim physical destruction',
    strpos($resetOnly, 'PHYSICALLY DESTROYED') === false
    && strpos($resetOnly, 'OPERATOR ATTESTATION — NOT INSTRUMENT-VERIFIED') !== false);
t_check('factory reset: the qualifier is still printed',
    stripos($resetOnly, 'not instrument-verified') !== false);
t_check('factory reset: the attestation says the devices were retained',
    strpos($resetOnly, 'reset to factory settings and retained') !== false);
t_check('factory reset: the attestation makes no appliance claim',
    strpos($resetOnly, 'was used as the sanitisation tool') === false);
t_check('factory reset: a reset unit with no medium is still stated as retained',
    strpos($resetOnly, 'FOC9876XYZ') !== false);
t_check('factory reset: the certification date comes from the recorded reset dates',
    strpos($resetOnly, '2026-10-16') !== false);

// --- 9. some destroyed, some retained, on one certificate --------------------
t_check('reset + destroy: both figures are stated, separately',
    strpos($resetAndDestroy, '2 MEDIA DESTROYED (ATTESTED, 2 DEVICES)') !== false
    && strpos($resetAndDestroy, '1 MEDIA FACTORY RESET (ATTESTED, RETAINED, 2 DEVICES)') !== false);
t_check('reset + destroy: the two figures are never added together',
    strpos($resetAndDestroy, '3 MEDIA') === false);
t_check('reset + destroy: the destroyed item keeps its verb',
    strpos($resetAndDestroy, 'Destroyed — Shredded') !== false);
t_check('reset + destroy: the reset item does not carry it',
    strpos($resetAndDestroy, 'Factory reset (2026-10-16) — device retained') !== false);
t_check('reset + destroy: the summary names both outcomes',
    strpos($resetAndDestroy, 'reset to factory settings and retained') !== false);

// --- 10. one unit, two outcomes: the detail must agree with the summary ------
// The banner counted them apart, but the bullet under the device said nothing, so the
// detail contradicted the summary on the same page.
t_check('mixed unit: the retained medium is called out under its destroyed host',
    strpos($mixedUnit, 'MIXED-SSD-1 · retained — Factory reset (2026-10-17)') !== false);
t_check('mixed unit: the medium destroyed with the unit is not labelled',
    strpos($mixedUnit, 'MIXED-1 · destroyed') === false
    && strpos($mixedUnit, 'MIXED-1 · retained') === false);
t_check('mixed unit: the summary states both figures',
    strpos($mixedUnit, '1 MEDIA DESTROYED (ATTESTED, 1 DEVICE)') !== false
    && strpos($mixedUnit, '1 MEDIA FACTORY RESET (ATTESTED, RETAINED)') !== false);
t_check('mixed unit: the unit itself is still reported as destroyed',
    strpos($mixedUnit, 'Destroyed — Shredded (Acme Shredding, 2026-10-17)') !== false);

// --- 11. the attestation paragraph fits the space page 1 gives it -------------
// The paragraph is drawn in a FIXED band: page 1 is landscape A4, its heading is at
// y=118 and the operator signature block at y=140, so 17mm — four lines — is the whole
// budget. The text is NOT fixed: a mixed job appends the caveat clause and a job with
// reset items appends a retention note. Drawn at 8.5pt without measuring, the mixed
// wording came to 18.7mm and printed straight through the operator's own name.
//
// No wording assertion can catch that: every sentence was present, correct and in the
// PDF. Only the geometry fails, so the geometry is what is asserted here. Each shape is
// built from the same fixtures the render checks above use, and measured with the same
// helpers the renderer uses, so a future clause that pushes a shape over budget fails
// here rather than on a customer's certificate.
$band = cert_attestation_band();
$measure = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$measure->SetPrintHeader(false);
$measure->SetPrintFooter(false);
$measure->SetAutoPageBreak(false);
$measure->SetMargins(0, 0, 0, true);
$measure->AddPage();

$shapes = [
    'verified only'            => [2, []],
    'mixed'                    => [1, attested_pair()],
    'mixed with retained items' => [1, attested_reset_and_destroy()],
    'attested only'            => [0, attested_pair()],
    'attested only, reset'     => [0, attested_reset()],
    'attested only, loose media' => [0, attested_loose_only()],
    'attested only, no media'  => [0, attested_no_media()],
];
foreach ($shapes as $name => [$verified, $attested]) {
    $split    = md_attested_split($attested);
    $items    = count($attested['devices'] ?? []) + count($attested['media'] ?? []);
    $retained = (int)$split['retained']['devices'] + (int)$split['retained']['media'];
    $text     = cert_attestation_text('tScrub v1.11.51', $verified, $items, $retained);

    $measure->SetFont('helvetica', '', $band['max']);
    $h = $measure->getStringHeight($band['w'], $text);
    // 1.5mm of headroom, not merely 'inside': the paragraph must still fit if the tool
    // version string grows a character or two.
    t_check("$name: the attestation fits the band at the full {$band['max']}pt "
        . '(' . round($h, 2) . 'mm of ' . $band['h'] . 'mm, bottom edge '
        . round($band['y'] + $h, 1) . 'mm vs the signature block at ' . ($band['y'] + $band['h']) . 'mm)',
        $h <= $band['h'] - 1.5);
}

t_check('the band leaves room for four lines and starts where the renderer starts',
    $band['h'] === 17.0 && $band['line'] * 4 <= $band['h']);

// A job shape that outgrows the wording (the next clause someone adds, or an operator
// account with a much longer issuer name) must shrink the type rather than overlap.
$overlong = cert_attestation_text('tScrub v1.11.51', 1, 4, 2)
    . ' ' . str_repeat('An added clause that keeps going and going. ', 6);
$size = cert_attestation_size($measure, $overlong, $band['w'], $band['h'], $band['max'], $band['min']);
$measure->SetFont('helvetica', '', $size);
t_check('an over-long attestation is shrunk to fit rather than printed over the signature '
    . "($size pt, " . round($measure->getStringHeight($band['w'], $overlong), 2) . 'mm of ' . $band['h'] . 'mm)',
    $size < $band['max'] && $measure->getStringHeight($band['w'], $overlong) <= $band['h']);
t_check('the real wording is not shrunk — the guard is a backstop, not the fix',
    cert_attestation_size($measure, cert_attestation_text('tScrub v1.11.51', 1, 4, 0), $band['w'], $band['h'],
        $band['max'], $band['min']) === $band['max']);

// --- 12. the shortened wording still makes every claim it has to --------------
$mixedLong = cert_attestation_text('tScrub v1.11.51', 1, 4, 2);
$pureLong  = cert_attestation_text('tScrub v1.11.51', 0, 4, 2);
t_check('shortened: the verified claim still names the means, the custody and the tool',
    strpos($mixedLong, 'by in-place sanitisation') !== false
    && strpos($mixedLong, 'chain-of-custody from receipt to final verification') !== false
    && strpos($mixedLong, 'was used as the sanitisation tool.') !== false);
t_check('shortened: the mixed caveat still says the tool did not sanitise them',
    strpos($mixedLong, 'were not sanitised by this tool') !== false);
t_check('shortened: the retained case is still stated, not dropped for length',
    strpos($mixedLong, 'as reset to factory settings and retained') !== false);
t_check('shortened: the pure case still refuses the appliance claim',
    strpos($pureLong, 'was used as the sanitisation tool') === false
    && strpos($pureLong, 'no appliance-verified sanitisation is claimed') !== false);
t_check('shortened: the pure reset case still warns the device is still out there',
    strpos($pureLong, 'remains in circulation') !== false);
t_check('shortened: every shape still carries the qualifier',
    stripos($mixedLong, 'not instrument-verified') !== false
    && stripos($pureLong, 'not instrument-verified') !== false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);

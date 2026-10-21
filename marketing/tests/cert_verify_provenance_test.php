<?php
/**
 * Phase 1 of the IEEE 2883 work: RECORD the verification technique, do not
 * CLAIM a standard.
 *
 * v1.11.73 added two per-drive fields to the appliance report:
 *   VerifyTechnique   sentinel | overwrite-sample | ce-exempt
 *   VerifyCoveragePct the sampled percentage (only for overwrite-sample)
 * and renamed the CSV column Standard -> OverwriteStandard so the bare word
 * "standard" stays free for a future sanitisation CLAIM. Nothing here may
 * introduce IEEE wording on any surface.
 *
 * Asserted here:
 *   1. cert_verify_label() maps each technique to one honest phrase;
 *   2. the Annex gains a Verification column only when some drive carries one,
 *      and a certificate with no technique is byte-identical in wording;
 *   3. the storage round-trip carries the fields (column map, INSERT, SELECT,
 *      DB-row -> group-drive mapping);
 *   4. the JSON-LD document publishes overwrite_standard (+ legacy alias) and
 *      the two verification fields;
 *   5. no surface mentions IEEE yet.
 *
 * Needs TCPDF, so run it where the server code lives:
 *   php cert_verify_provenance_test.php
 *   php cert_verify_provenance_test.php /home/oxwet/webs/tscrub-form
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$tcpdf = $serverDir . '/tcpdf/tcpdf.php';
if (is_file($tcpdf)) { require_once $tcpdf; }
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/cert_terms.php';
require_once $serverDir . '/jsonld.php';
require_once $serverDir . '/reports_lib.php';

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

/** Visible text as one continuous string (see cert_overwrite_standard_test.php). */
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
    $joined = implode(' ', $parts);
    $flat = preg_replace('/\s+/', ' ', $joined);
    $flat = trim($flat === null ? $joined : $flat);
    if ($flat !== '' && !mb_check_encoding($flat, 'UTF-8')) {
        $conv = @mb_convert_encoding($flat, 'UTF-8', 'Windows-1252');
        if ($conv !== false && $conv !== '') { $flat = $conv; }
    }
    return $flat;
}

function drive_row(string $serial, string $method, string $cls, array $extra = []): array
{
    return array_merge([
        'serial' => $serial, 'model' => $serial . '-MODEL', 'type' => 'SSD',
        'size' => '512 GB', 'bus' => 'NVMe', 'status' => 'COMPLETED',
        'cert' => '', 'method' => $method, 'cls' => $cls, 'device' => 'nvme0n1',
        'ts' => '2026-11-02 09:12:03', 'tool_version' => 'v1.11.73',
        'passes' => '', 'erase_engine' => '', 'standard' => '',
        'verify_technique' => '', 'verify_coverage_pct' => '',
    ], $extra);
}

function group_of(array $drives): array
{
    return [
        'cocid' => 'TEST-COC-VERIFY', 'reports' => [],
        'first' => '2026-11-02 09:10:00', 'last' => '2026-11-02 09:20:00',
        'operator' => 'Operator One', 'validator' => '',
        'tool_version' => 'v1.11.73',
        'shaState' => 'verified', 'sigState' => 'none',
        'drives' => $drives,
    ];
}

// ---------------------------------------------------------------------------
// 1. One honest phrase per technique, and nothing for an absent one
// ---------------------------------------------------------------------------
t_check('sentinel is named', cert_verify_label('sentinel') === 'Sentinel');
t_check('a sampled overwrite states its percentage',
    cert_verify_label('overwrite-sample', '5') === 'Sampled 5%');
t_check('a sampled overwrite without a number still says sampled',
    cert_verify_label('overwrite-sample') === 'Sampled');
t_check('a junk percentage is dropped, not printed',
    cert_verify_label('overwrite-sample', 'five') === 'Sampled');
t_check('a 3-digit percentage is accepted',
    cert_verify_label('overwrite-sample', '100') === 'Sampled 100%');
t_check('a crypto erase reads as an exemption',
    cert_verify_label('ce-exempt') === 'CE exempt');
t_check('an absent technique renders nothing',
    cert_verify_label('') === '' && cert_verify_label('none') === ''
    && cert_verify_label('N/A') === '');
t_check('an unknown technique renders nothing rather than a guess',
    cert_verify_label('something-new') === '');

// ---------------------------------------------------------------------------
// 2. The Annex column appears only when it has something to say
// ---------------------------------------------------------------------------
$withTechniques = pdf_lines(render_certificate_pdf(
    group_of([
        drive_row('VF-1', 'DoD Short', 'CLEAR',
            ['standard' => 'dodshort', 'verify_technique' => 'overwrite-sample',
             'verify_coverage_pct' => '5']),
        drive_row('VF-2', 'ATA Secure Erase', 'CLEAR',
            ['verify_technique' => 'sentinel', 'verify_sectors' => '40']),
        drive_row('VF-3', 'NVMe Crypto Purge', 'PURGE',
            ['verify_technique' => 'ce-exempt']),
    ]),
    'COD-VERIFY-1', false, [], [], []
)['data']);

t_check('annex shows the sampled percentage',
    strpos($withTechniques, 'Sampled 5%') !== false);
t_check('annex shows the sentinel technique',
    strpos($withTechniques, 'Sentinel') !== false);
t_check('annex shows the crypto-erase exemption',
    strpos($withTechniques, 'CE exempt') !== false);
t_check('annex header names the column',
    strpos($withTechniques, 'Verification') !== false);
t_check('the overwrite standard is still named on the row',
    strpos($withTechniques, 'DoD Short') !== false);
t_check('no IEEE wording anywhere on the certificate',
    stripos($withTechniques, 'IEEE') === false);
t_check('no sanitisation standard is claimed',
    stripos($withTechniques, '2883') === false);

$firmwareOnly = pdf_lines(render_certificate_pdf(
    group_of([drive_row('FW-9', 'NVMe Format', 'CLEAR')]),
    'COD-VERIFY-2', false, [], [], []
)['data']);

t_check('a certificate with no technique shows no technique words',
    stripos($firmwareOnly, 'Sentinel') === false
    && stripos($firmwareOnly, 'Sampled') === false
    && stripos($firmwareOnly, 'CE exempt') === false);
t_check('a certificate with no technique still names its method',
    strpos($firmwareOnly, 'NVMe Controller-Level Format (Clear) - Verified') !== false);

// ---------------------------------------------------------------------------
// 3. Storage round-trip: the fields survive column map, INSERT and read
// ---------------------------------------------------------------------------
$map = certificate_drives_column_map();
t_check('column map carries verify_technique',
    ($map['verify_technique'] ?? null) === 'verify_technique');
t_check('column map carries verify_coverage_pct',
    ($map['verify_coverage_pct'] ?? null) === 'verify_coverage_pct');
$insertSql = certificate_drives_insert_sql();
t_check('INSERT binds verify_technique', strpos($insertSql, 'verify_technique') !== false);
t_check('INSERT binds verify_coverage_pct', strpos($insertSql, 'verify_coverage_pct') !== false);

// The read query lists its columns BY HAND, so it can silently drift from the
// column map — that is exactly the class of bug this release is about.
$libSrc = (string)@file_get_contents($serverDir . '/reports_lib.php');
$selStart = strpos($libSrc, 'function load_cert_drives');
$selBody = $selStart === false ? '' : substr($libSrc, $selStart, 1200);
t_check('load_cert_drives selects verify_technique',
    $selBody !== '' && strpos($selBody, 'verify_technique') !== false);
t_check('load_cert_drives selects verify_coverage_pct',
    $selBody !== '' && strpos($selBody, 'verify_coverage_pct') !== false);

$back = db_drive_to_group([
    'serial' => 'RT-1', 'model' => 'M', 'device' => 'nvme0n1', 'type' => 'SSD',
    'size' => '512 GB', 'bus' => 'NVMe', 'class' => 'CLEAR', 'method' => 'DoD Short',
    'certification' => 'OVERWRITE', 'final_status' => 'COMPLETED',
    'standard' => 'dodshort', 'passes' => '3', 'erase_engine' => 'nwipe 0.41',
    'verify_technique' => 'overwrite-sample', 'verify_coverage_pct' => '5',
]);
t_check('DB row -> group drive keeps the technique',
    ($back['verify_technique'] ?? '') === 'overwrite-sample');
t_check('DB row -> group drive keeps the percentage',
    ($back['verify_coverage_pct'] ?? '') === '5');
t_check('DB row -> group drive keeps the overwrite standard',
    ($back['standard'] ?? '') === 'dodshort');

// ---------------------------------------------------------------------------
// 4. JSON-LD publishes the renamed field plus a legacy alias
// ---------------------------------------------------------------------------
$norm = jsonld_normalize_drive([
    'serial' => 'JL-1', 'standard' => 'dodshort',
    'verify_technique' => 'overwrite-sample', 'verify_coverage_pct' => '5',
]);
$doc = jsonld_drive($norm);
$props = $doc['properties'] ?? [];
t_check('JSON-LD names overwrite_standard',
    ($props['overwrite_standard'] ?? '') === 'dodshort');
t_check('JSON-LD keeps the legacy standard alias',
    ($props['standard'] ?? '') === 'dodshort');
t_check('JSON-LD publishes the verification technique',
    ($props['verify_technique'] ?? '') === 'overwrite-sample');
t_check('JSON-LD publishes the sampled percentage',
    ($props['verify_coverage_pct'] ?? '') === '5');
t_check('JSON-LD never mentions IEEE',
    stripos(json_encode($doc), 'IEEE') === false);

$emptyDoc = jsonld_drive(jsonld_normalize_drive(['serial' => 'JL-2']));
$emptyProps = $emptyDoc['properties'] ?? [];
t_check('an unverified drive publishes no verification fields',
    !isset($emptyProps['verify_technique']) && !isset($emptyProps['verify_coverage_pct']));
t_check('an unverified drive publishes no overwrite standard',
    !isset($emptyProps['overwrite_standard']) && !isset($emptyProps['standard']));

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

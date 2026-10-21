<?php
/**
 * The software-overwrite standard must be NAMED on the certificate.
 *
 * v1.11.70 made the overwrite standard selectable and recorded it (CSV
 * `Standard`, manifest, payload, Media drawer, JSON-LD), but the certificate's
 * method label did not know the new METHOD strings. Those rows carried the
 * standard's own label ("DoD Short", "Zero fill", …), which matched neither the
 * alias table nor the "NWIPE" rule that caught the old literal "nwipe Quick",
 * so every software overwrite fell through to the generic class wording
 * "Sanitisation (Clear) - Verified" — the same sentence for all six standards,
 * with the name printed nowhere in the method summary.
 *
 * Two things are asserted here:
 *   1. the six named standards render a label that names the standard;
 *   2. nothing else moves — the firmware wordings, the legacy "nwipe Quick"
 *      wording, the generic CLEAR fallback and the status overrides are all
 *      byte-identical, and a firmware-only certificate never mentions a
 *      software overwrite.
 *
 * Needs TCPDF (the certificate renderer requires it), so run it where the
 * server code lives:
 *   php cert_overwrite_standard_test.php                       # repo checkout
 *   php cert_overwrite_standard_test.php /home/oxwet/webs/tscrub-form
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$tcpdf = $serverDir . '/tcpdf/tcpdf.php';
if (is_file($tcpdf)) { require_once $tcpdf; }
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/cert_terms.php';

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
 * The visible text as one continuous string: string literals in order, escapes
 * undone, joined with a space (a wrapped line is emitted as one literal per
 * visual line), then WinAnsi byte 0x97 normalised to a real em dash.
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
    $joined = implode(' ', $parts);
    $flat = preg_replace('/\s+/', ' ', $joined);
    $flat = trim($flat === null ? $joined : $flat);
    if ($flat !== '' && !mb_check_encoding($flat, 'UTF-8')) {
        $conv = @mb_convert_encoding($flat, 'UTF-8', 'Windows-1252');
        if ($conv !== false && $conv !== '') { $flat = $conv; }
    }
    return $flat;
}

/** One drive row, with the per-drive fields the certificate reads. */
function drive_row(string $serial, string $method, string $cls, array $extra = []): array
{
    return array_merge([
        'serial' => $serial, 'model' => $serial . '-MODEL', 'type' => 'SSD',
        'size' => '512 GB', 'bus' => 'NVMe', 'status' => 'COMPLETED',
        'cert' => '', 'method' => $method, 'cls' => $cls, 'device' => 'nvme0n1',
        'ts' => '2026-10-21 07:51:41', 'tool_version' => 'v1.11.72',
        'passes' => '', 'erase_engine' => '', 'standard' => '',
    ], $extra);
}

function group_of(array $drives): array
{
    return [
        'cocid' => 'TEST-COC-OVERWRITE', 'reports' => [],
        'first' => '2026-10-21 07:50:00', 'last' => '2026-10-21 08:00:00',
        'operator' => 'Operator One', 'validator' => '',
        'tool_version' => 'v1.11.72',
        'shaState' => 'verified', 'sigState' => 'none',
        'drives' => $drives,
    ];
}

// ---------------------------------------------------------------------------
// 1. The six named standards are named in the label
// ---------------------------------------------------------------------------
$standards = [
    'Zero fill'        => 'Zero fill',
    'PRNG stream'      => 'PRNG stream',
    'DoD 5220.22-M'    => 'DoD 5220.22-M',
    'DoD Short'        => 'DoD Short',
    'Gutmann'          => 'Gutmann',
    'HMG IS5 Enhanced' => 'HMG IS5 Enhanced',
];

foreach ($standards as $method => $named) {
    $label = method_label($method, 'CLEAR', 'COMPLETED');
    t_check("'$method' names the standard", strpos($label, $named) !== false);
    t_check("'$method' says Software Overwrite", strpos($label, 'Software Overwrite') === 0);
    t_check("'$method' keeps the Clear claim", strpos($label, '(Clear) - Verified') !== false);
    t_check("'$method' is not the generic wording",
        $label !== 'Sanitisation (Clear) - Verified');
}

// ---------------------------------------------------------------------------
// 2. Nothing else moved
// ---------------------------------------------------------------------------
t_check('NVMe format wording unchanged',
    method_label('NVMe Format', 'CLEAR', 'COMPLETED') === 'NVMe Controller-Level Format (Clear) - Verified');
t_check('ATA enhanced wording unchanged',
    method_label('Enhanced Erase', 'PURGE', 'COMPLETED') === 'ATA Enhanced Secure Erase (Purge) - Verified');
t_check('ATA secure erase wording unchanged',
    method_label('ATA Secure Erase', 'CLEAR', 'COMPLETED') === 'ATA Security Erase (Clear) - Verified');
t_check('legacy nwipe Quick wording unchanged',
    method_label('nwipe Quick', 'CLEAR', 'COMPLETED') === 'SCSI Software Overwrite (Clear) - Verified');
t_check('generic CLEAR fallback unchanged',
    method_label('', 'CLEAR', 'COMPLETED') === 'Sanitisation (Clear) - Verified');
t_check('a failed software overwrite is still "not sanitised"',
    method_label('DoD Short', 'CLEAR', 'FAILED') === 'Not sanitised — erasure failed');
t_check('a blocked drive is still "blocked by firmware"',
    method_label('DoD Short', 'CLEAR', 'BLOCKED') === 'Not sanitised — blocked by firmware (Block SID)');
t_check('technique stays a technique, not a standard name',
    cert_technique_label('DoD Short', 'CLEAR', 'COMPLETED') === 'Software overwrite (Clear)');
t_check('Annex per-drive cell shows the standard verbatim',
    cert_method_short('DoD Short', 'CLEAR', 'COMPLETED') === 'DoD Short');

// ---------------------------------------------------------------------------
// 3. The rendered certificate names it, and a firmware-only one does not
// ---------------------------------------------------------------------------
$mixed = pdf_lines(render_certificate_pdf(
    group_of([
        drive_row('OW-1', 'DoD Short', 'CLEAR',
            ['passes' => '6', 'erase_engine' => 'nwipe 0.41', 'standard' => 'dodshort']),
        drive_row('FW-1', 'NVMe Format', 'CLEAR'),
    ]),
    'COD-OW-MIXED', false, [], [], []
)['data']);

t_check('summary names the overwrite standard',
    strpos($mixed, 'Software Overwrite — DoD Short (Clear) - Verified') !== false);
t_check('per-drive row shows the standard', strpos($mixed, 'DoD Short') !== false);
t_check('pass count is present', preg_match('/\b6\b/', $mixed) === 1);
t_check('erase engine is present', strpos($mixed, 'nwipe 0.41') !== false);
t_check('the firmware drive keeps its own wording',
    strpos($mixed, 'NVMe Controller-Level Format (Clear) - Verified') !== false);

$firmwareOnly = pdf_lines(render_certificate_pdf(
    group_of([drive_row('FW-2', 'NVMe Format', 'CLEAR')]),
    'COD-OW-FW', false, [], [], []
)['data']);

t_check('a firmware-only certificate never claims a software overwrite',
    stripos($firmwareOnly, 'Software Overwrite') === false);
t_check('a firmware-only certificate still names its own method',
    strpos($firmwareOnly, 'NVMe Controller-Level Format (Clear) - Verified') !== false);

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

<?php
/**
 * Server side of the software-overwrite report: CSV → drive → certificate
 * annex → JSON-LD.
 *
 * Until v1.11.73 only the APPLIANCE half of `standard`/`passes`/`erase_engine`
 * was tested (`product/tests/test_report.sh`). The server half was verified
 * with throwaway probes, because parse_reports() reads uploads through
 * is_uploaded_file() and so cannot be driven from the CLI at all. The pieces
 * that read a row were therefore unreachable from a test, and that is exactly
 * where the v1.11.73 `Standard` -> `OverwriteStandard` rename (and its legacy
 * fallback) had to be got right.
 *
 * v1.11.73 pulls three helpers out of parse_reports() — reports_header_map(),
 * reports_row_value() and reports_drive_provenance() — and this file is the
 * reason they exist:
 *
 *   1. the column contract (normalisation, absent columns, legacy spelling);
 *   2. the REAL appliance header, parsed from a fixture written by
 *      `report::csv` itself (tests/fixtures/report-v1.11.73.csv), so a column
 *      rename on the appliance side breaks this test rather than import;
 *   3. that the parsed value survives into the certificate annex and the
 *      signed JSON-LD document.
 *
 * Needs TCPDF for part 3, so run it where the server code lives:
 *   php overwrite_parse_test.php
 *   php overwrite_parse_test.php /home/oxwet/webs/tscrub-form
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

function t_eq(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    t_check($label, $ok);
    if (!$ok) {
        echo '      expected: ' . var_export($expected, true) . "\n";
        echo '      actual:   ' . var_export($actual, true) . "\n";
    }
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

/** Visible text as one continuous string (see cert_verify_provenance_test.php). */
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

// ---------------------------------------------------------------------------
// 1. The column contract
// ---------------------------------------------------------------------------
$map = reports_header_map(['Model', ' EraseEngine ', 'OverwriteStandard', 'PASSES']);
t_eq('column names are lowercased and trimmed', 1, $map['eraseengine'] ?? null);
t_eq('an appliance-cased column maps to its lowercase key', 2, $map['overwritestandard'] ?? null);
t_eq('an already-lowercase column still maps', 3, $map['passes'] ?? null);
t_eq('a duplicated column keeps the last occurrence', 1,
    reports_header_map(['Foo', 'FOO'])['foo'] ?? null);

$row = ['m1', 'dodshort', '', '6'];
$m = reports_header_map(['Model', 'OverwriteStandard', 'Standard', 'Passes']);
t_eq('a present column reads', 'dodshort', reports_row_value($row, $m, 'overwritestandard'));
t_eq('an absent column reads empty', '', reports_row_value($row, $m, 'eraseengine'));
t_eq('a missing cell reads empty',
    '', reports_row_value(['only'], reports_header_map(['Model', 'Passes']), 'passes'));

// ---------------------------------------------------------------------------
// 1b. The sanitisation claim (v1.11.76+), including the pre-claim report
// ---------------------------------------------------------------------------
// The fixture below predates the claim, which is exactly why these cases use a
// synthetic row: an old report must read as "no claim", and a new one must
// carry standard + level + shortfall independently. Getting this wrong is what
// made the first hardware run lose the level (it was computed and never
// reported), so the three columns are asserted separately.
$claimMap = reports_header_map(['Model', 'SanitizationStandard', 'SanitizationLevel',
    'SanitizationShortfall']);
$claimRow = ['m1', 'IEEE 2883-2022', 'Purge', ''];
t_eq('the claim standard parses', 'IEEE 2883-2022',
    reports_row_value($claimRow, $claimMap, 'sanitizationstandard'));
t_eq('the claim level parses', 'Purge',
    reports_row_value($claimRow, $claimMap, 'sanitizationlevel'));
t_eq('an earned claim has no shortfall', '',
    reports_row_value($claimRow, $claimMap, 'sanitizationshortfall'));

$refusedMap = reports_header_map(['Model', 'SanitizationStandard', 'SanitizationShortfall']);
$refusedRow = ['m1', 'NIST 800-88', 'G2 coverage 2% < 5%'];
t_eq('a refused claim keeps NIST', 'NIST 800-88',
    reports_row_value($refusedRow, $refusedMap, 'sanitizationstandard'));
t_eq('a refused claim names its gate', 'G2 coverage 2% < 5%',
    reports_row_value($refusedRow, $refusedMap, 'sanitizationshortfall'));
t_eq('a report without the claim columns reads as no claim', '',
    reports_row_value(['m1'], reports_header_map(['Model']), 'sanitizationstandard'));

// ---------------------------------------------------------------------------
// 2. The REAL appliance header, from a CSV that report::csv wrote
// ---------------------------------------------------------------------------
$fixture = __DIR__ . '/fixtures/report-v1.11.73.csv';
$fh = @fopen($fixture, 'r');
t_check('the appliance CSV fixture is present', $fh !== false);
$header = $fh ? fgetcsv($fh) : [];
$row = $fh ? fgetcsv($fh) : [];
if ($fh) { fclose($fh); }

t_check('the fixture carries a full report header (>= 80 columns)',
    is_array($header) && count($header) >= 80);
$fmap = reports_header_map(is_array($header) ? $header : []);

foreach (['overwritestandard', 'passes', 'eraseengine', 'verifytechnique',
          'verifycoveragepct'] as $col) {
    t_check("the appliance writes a column the parser reads ($col)",
        array_key_exists($col, $fmap));
}
t_check('the fixture predates no legacy Standard column',
    !array_key_exists('standard', $fmap));

$prov = reports_drive_provenance(is_array($row) ? $row : [], $fmap);
t_eq('the overwrite standard parses from the real header', 'dodshort', $prov['standard']);
t_eq('the pass count parses', '6', $prov['passes']);
t_eq('the erase engine parses', 'nwipe 0.41', $prov['erase_engine']);
t_eq('the verification technique parses', 'overwrite-sample', $prov['verify_technique']);
t_eq('the sampled percentage parses', '5', $prov['verify_coverage_pct']);

// The row's other columns resolve through the same map, so the test above is
// not passing on a map that happens to hold only the new columns.
t_eq('the drive serial parses', 'S6PTNX0T900001', reports_row_value($row, $fmap, 'serial'));
t_eq('the method parses', 'DoD Short', reports_row_value($row, $fmap, 'method'));
t_eq('the class parses', 'CLEAR', reports_row_value($row, $fmap, 'class'));
t_eq('the final status parses', 'COMPLETED', reports_row_value($row, $fmap, 'finalstatus'));

// ---------------------------------------------------------------------------
// 3. Legacy spelling, and the precedence between the two
// ---------------------------------------------------------------------------
$legacyMap = reports_header_map(['Serial', 'Standard', 'Passes', 'EraseEngine']);
$legacy = reports_drive_provenance(['LEG-1', 'dodshort', '6', 'nwipe 0.41'], $legacyMap);
t_eq('a pre-rename report keeps its standard', 'dodshort', $legacy['standard']);
t_eq('a pre-rename report keeps its pass count', '6', $legacy['passes']);
t_eq('a pre-rename report keeps its engine', 'nwipe 0.41', $legacy['erase_engine']);
t_eq('a pre-rename report claims no verification technique',
    '', $legacy['verify_technique']);

$bothMap = reports_header_map(['Serial', 'Standard', 'OverwriteStandard']);
$both = reports_drive_provenance(['BOTH-1', 'zero', ''], $bothMap);
t_eq('an empty OverwriteStandard falls back to the legacy column',
    'zero', $both['standard']);

$disagree = reports_drive_provenance(['BOTH-2', 'zero', 'gutmann'], $bothMap);
t_eq('the renamed column wins when both are populated', 'gutmann', $disagree['standard']);

$none = reports_drive_provenance(['NONE-1'], reports_header_map(['Serial']));
t_eq('a report with no overwrite columns reads empty, not "0"', '', $none['standard']);
t_eq('a report with no overwrite columns has no passes', '', $none['passes']);

$long = reports_drive_provenance(
    ['L-1', str_repeat('x', 40)],
    reports_header_map(['Serial', 'OverwriteStandard']));
t_eq('an over-long standard is clipped to the column width', 32, strlen($long['standard']));

// ---------------------------------------------------------------------------
// 4. Parsed value → certificate annex → JSON-LD
// ---------------------------------------------------------------------------
$drive = array_merge([
    'serial' => reports_row_value($row, $fmap, 'serial'),
    'model' => reports_row_value($row, $fmap, 'model'),
    'method' => reports_row_value($row, $fmap, 'method'),
    'cls' => reports_row_value($row, $fmap, 'class'),
    'cert' => reports_row_value($row, $fmap, 'certification'),
    'status' => reports_row_value($row, $fmap, 'finalstatus'),
    'ts' => reports_row_value($row, $fmap, 'timestamp'),
    'type' => reports_row_value($row, $fmap, 'type'),
    'size' => reports_row_value($row, $fmap, 'size'),
    'bus' => reports_row_value($row, $fmap, 'bus'),
    'device' => reports_row_value($row, $fmap, 'device'),
], $prov);

t_eq('the annex method cell names the standard',
    'DoD Short', cert_method_short($drive['method'], $drive['cls'], $drive['status']));
t_eq('the annex verification cell states the sampled percentage',
    'Sampled 5%', cert_verify_label($drive['verify_technique'], $drive['verify_coverage_pct']));

$props = jsonld_drive(jsonld_normalize_drive($drive))['properties'] ?? [];
t_eq('JSON-LD carries overwrite_standard', 'dodshort', $props['overwrite_standard'] ?? '');
t_eq('JSON-LD carries the legacy alias', 'dodshort', $props['standard'] ?? '');
t_eq('JSON-LD carries the pass count', '6', $props['passes'] ?? '');
t_eq('JSON-LD carries the erase engine', 'nwipe 0.41', $props['erase_engine'] ?? '');
t_eq('JSON-LD carries the verification technique', 'overwrite-sample', $props['verify_technique'] ?? '');
t_eq('JSON-LD carries the sampled percentage', '5', $props['verify_coverage_pct'] ?? '');

$group = [
    'cocid' => 'TEST-COC-PARSE', 'reports' => [],
    'first' => '2026-10-21 10:00:00', 'last' => '2026-10-21 10:05:00',
    'operator' => 'Operator One', 'validator' => '', 'tool_version' => 'v1.11.73',
    'shaState' => 'verified', 'sigState' => 'none',
    'drives' => [$drive],
];
$pdf = pdf_lines(render_certificate_pdf($group, 'COD-PARSE-1', false, [], [], [])['data']);
t_check('the rendered certificate names the parsed standard',
    strpos($pdf, 'DoD Short') !== false);
t_check('the rendered certificate states the parsed coverage',
    strpos($pdf, 'Sampled 5%') !== false);
t_check('the rendered certificate never claims IEEE', stripos($pdf, 'IEEE') === false);

// ---------------------------------------------------------------------------
// 5. The importer actually calls them
// ---------------------------------------------------------------------------
// A helper that exists, is tested, and is never called is worse than no helper:
// the claim it is supposed to fix keeps failing and the suite stays green. That
// is how md_attested_banner() shipped a false certificate claim once, so assert
// the CALL SITES, not just the behaviour of the functions.
$lib = (string)@file_get_contents($serverDir . '/reports_lib.php');
t_check('the importer builds its map with reports_header_map()',
    preg_match('/\$map = reports_header_map\(\$header\)/', $lib) === 1);
t_check('the importer reads cells with reports_row_value()',
    preg_match('/return reports_row_value\(\$row, \$map, \$key\)/', $lib) === 1);
t_check('the importer merges reports_drive_provenance() into every drive row',
    preg_match('/\$drive = array_merge\(\$drive, reports_drive_provenance\(\$row, \$map\)\)/', $lib) === 1);

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

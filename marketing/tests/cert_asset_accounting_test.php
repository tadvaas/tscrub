<?php
/**
 * Certificate asset accounting — the evidence a job must carry when a medium
 * was pulled from an asset and sanitised on another host.
 *
 * Asserts the document, not the helper's return value: a block that is built but
 * never rendered is exactly the class of defect this feature exists to prevent.
 *
 * Needs TCPDF, so run it where the server code lives:
 *   php marketing/tests/cert_asset_accounting_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$tcpdf = $serverDir . '/tcpdf/tcpdf.php';
if (is_file($tcpdf)) { require_once $tcpdf; }
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/media_provenance.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; } else { $fails++; echo "FAIL - $label\n"; }
}

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

function t_drive(string $serial, string $status, string $host): array
{
    return [
        'serial' => $serial, 'model' => 'Samsung PM981a', 'type' => 'SSD',
        'size' => '512 GB', 'bus' => 'NVMe', 'status' => $status, 'cert' => '',
        'method' => 'NVMe Crypto Purge', 'cls' => 'PURGE', 'device' => 'nvme0',
        'sysserial' => $host, 'system' => 'Host ' . $host,
        'ts' => '2026-10-30 11:00:00', 'tool_version' => 'v1.11.86',
    ];
}

function t_diag(string $serial, array $media): array
{
    return [
        'sysserial' => $serial, 'system' => 'Dell Latitude 5420',
        'asset_tag' => 'AT-' . $serial, 'serial_source' => 'dmi',
        'uploaded_at' => '2026-10-30 10:02',
        'drives' => array_map(static fn($s) => ['serial' => $s], $media),
    ];
}

function t_group(array $drives): array
{
    return [
        'cocid' => 'TEST-90001', 'drives' => $drives,
        'reports' => [['name' => 'report.csv', 'sha' => str_repeat('a', 64), 'state' => 'verified']],
        'first' => '2026-10-30 10:00:00', 'last' => '2026-10-30 12:00:00',
        'shaState' => 'verified', 'sigState' => 'valid',
    ];
}

// ---- A job with one medium removed and one in place ------------------------
$payloads = [
    ['sysserial' => 'ASSET1', 'system' => 'Host ASSET1', 'drives' => [t_drive('D1', 'FAILED', 'ASSET1')]],
    ['sysserial' => 'BENCH',  'system' => 'Host BENCH',  'drives' => [t_drive('D1', 'COMPLETED', 'BENCH')]],
    ['sysserial' => 'ASSET2', 'system' => 'Host ASSET2', 'drives' => [t_drive('D2', 'COMPLETED', 'ASSET2')]],
];
$diags = [t_diag('ASSET1', ['D1']), t_diag('ASSET3', [])];
$merged = [t_drive('D1', 'COMPLETED', 'BENCH'), t_drive('D2', 'COMPLETED', 'ASSET2')];

$prov = mp_apply_evidence(mp_collect($payloads, $diags, [], ['bench' => true]), $merged);
foreach ($merged as $i => $dv) {
    $ms = mp_key($dv['serial']);
    if (isset($prov['drives'][$ms])) {
        $merged[$i] = array_merge($dv, $prov['drives'][$ms]);
    }
}

$g = t_group($merged);
$rendered = render_certificate_pdf($g, 'COD-TEST-0001-AA-BBB-CCCC-DDDD', false, [], [], [], '', $prov['assets']);
$pdf = $rendered['data'];
$text = pdf_lines($pdf);

t_check('pdf is produced', strlen($pdf) > 1000);
t_check('asset accounting block is rendered', strpos($text, 'ASSET ACCOUNTING') !== false);
t_check('the removed asset is named', strpos($text, 'ASSET1') !== false);
t_check('the host that erased it is named', strpos($text, 'sanitised on BENCH') !== false);
t_check('provenance names the failed attempt', strpos($text, 'erase failed on this asset') !== false);
t_check('the asset with no media is named', strpos($text, 'ASSET3') !== false);
t_check('the no-media outcome is stated', strpos($text, 'no data-bearing media') !== false);
t_check('page 1 counts the exception', strpos($text, 'asset with media removed') !== false);
t_check('the origin column is present', strpos($text, 'Origin asset') !== false);
t_check('the in-place asset is not claimed as removed', strpos($text, 'ASSET2 —') === false);
preg_match_all('~/Type\s*/Page[^s]~', $pdf, $m);
t_check('page count is sane', count($m[0]) >= 2 && count($m[0]) <= 8);

// The rendered drive carries the origin; the document states both facts.
$d1 = [];
foreach ($rendered['drives'] as $rd) {
    if (strcasecmp((string)($rd['serial'] ?? ''), 'D1') === 0) { $d1 = $rd; break; }
}
t_check('rendered drive knows its origin', (string)($d1['asset_serial'] ?? '') === 'ASSET1');
t_check('rendered asset block is reconciled', count($rendered['assets']) >= 2);

// ---- JSON-LD twin ----------------------------------------------------------
require_once $serverDir . '/jsonld.php';
$doc = build_cert_jsonld([
    'cert' => 'COD-TEST-0001-AA-BBB-CCCC-DDDD', 'cocid' => 'TEST-90001',
    'issued_at' => '2026-10-30 12:00:00', 'devices' => count($merged), 'methods' => 1, 'runs' => 1,
    'first' => '2026-10-30 10:00:00', 'last' => '2026-10-30 12:00:00',
    'sha_state' => 'verified', 'sig_state' => 'valid', 'pdf_sha256' => str_repeat('b', 64),
], $rendered['drives'], [], [], [], $rendered['assets']);

t_check('jsonld has the asset accounting', isset($doc['assetAccounting']['assets']));
$about = $doc['about'][0] ?? [];
t_check('jsonld drive carries originAsset', (string)($about['originAsset'] ?? '') === 'ASSET1');
t_check('jsonld drive carries originProvenance', (string)($about['originProvenance'] ?? '') === 'observed_failed');
t_check('jsonld drive carries hostSerial', (string)($about['hostSerial'] ?? '') === 'BENCH');

// ---- An ordinary in-place job must render exactly as before ----------------
$inPlace = [t_drive('D2', 'COMPLETED', 'ASSET2')];
$ord = mp_apply_evidence(
    mp_collect([['sysserial' => 'ASSET2', 'system' => 'Host ASSET2', 'drives' => $inPlace]], [t_diag('ASSET2', ['D2'])], [], []),
    $inPlace
);
$plain = render_certificate_pdf(t_group($inPlace), 'COD-TEST-0002-AA-BBB-CCCC-DDDD', false, [], [], [], '', $ord['assets']);
$plainText = pdf_lines($plain['data']);
t_check('in-place job has no asset block', strpos($plainText, 'ASSET ACCOUNTING') === false);
t_check('in-place job has no origin column', strpos($plainText, 'Origin asset') === false);
t_check('in-place job still states the media count', strpos($plainText, 'DATA DESTROYED') !== false);

$plainDoc = build_cert_jsonld([
    'cert' => 'COD-TEST-0002-AA-BBB-CCCC-DDDD', 'cocid' => 'TEST-90002',
    'issued_at' => '2026-10-30 12:00:00', 'devices' => 1, 'methods' => 1, 'runs' => 1,
    'first' => null, 'last' => null, 'sha_state' => 'verified', 'sig_state' => 'valid',
    'pdf_sha256' => str_repeat('c', 64),
], $plain['drives'], [], [], [], $plain['assets']);
t_check('in-place jsonld has no assetAccounting', !isset($plainDoc['assetAccounting']));

// ---- The physical-destruction decision must reach the asset block ----------
$failed = [t_drive('D1', 'FAILED', 'ASSET1')];
$dprov = mp_apply_evidence(
    mp_collect([['sysserial' => 'ASSET1', 'system' => 'Host ASSET1', 'drives' => $failed]], [t_diag('ASSET1', ['D1'])], [], []),
    $failed
);
$after = mp_reconcile_assets($dprov['assets'], [t_drive('D1', 'DESTROYED', 'ASSET1')]);
$asset = $after[0] ?? [];
t_check('destroyed decision changes the asset disposition', (string)($asset['disposition'] ?? '') === 'media_destroyed');
t_check('destroyed medium is listed as destroyed',
    (string)($asset['media']['d1']['disposition'] ?? '') === 'media_destroyed');

echo "\n$count checks, $fails failure(s)\n";
exit($fails === 0 ? 0 : 1);

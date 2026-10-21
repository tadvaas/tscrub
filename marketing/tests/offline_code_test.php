<?php
/**
 * Offline / air-gapped certificate verification: the self-contained code, its
 * Ed25519 signature, and the fact that the certificate prints it.
 *
 * Plan: research/offline-verification/README.md.
 *
 * A code is
 *   2|<kid>|<cert_id>|<issued_at RFC3339 UTC>|<Ed25519 sig>
 *
 * Version 1 carried `sha256` of the rendered PDF. That was impossible to do
 * honestly: the code is printed INSIDE that PDF, so adding the code changes the
 * file and therefore the hash, and "the PDF without the code" is a hash nobody
 * holding the paper can reproduce. Version 2 attests what an offline check can
 * actually attest (we issued this certificate id at this time under this key),
 * and the verifier compares the verified certificate id with the one printed on
 * the page.
 *
 * The two rejection rules decide whether the feature is worth anything:
 *
 *   - an unknown `kid` is a REJECTION, never a fallback to a key taken from the
 *     payload (a QR carrying its own key proves only that its author can sign);
 *   - every signed field is bound, so moving the code from one certificate to
 *     another, or editing a date, breaks the signature.
 *
 * Keys here are ephemeral (generated per run) so the test never needs the
 * production vendor key. Run it where the server code lives:
 *   php offline_code_test.php
 *   php offline_code_test.php /home/oxwet/webs/tscrub-form
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$tcpdf = $serverDir . '/tcpdf/tcpdf.php';
if (is_file($tcpdf)) { require_once $tcpdf; }
$qrcode = $serverDir . '/tcpdf/include/barcodes/qrcode.php';
if (is_file($qrcode)) { require_once $qrcode; }
require_once $serverDir . '/render_cert.php';
require_once $serverDir . '/cert_terms.php';
require_once $serverDir . '/jsonld.php';

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

/** Fresh Ed25519 keypair: ['key' => private path, 'pem' => public PEM]. */
function new_keypair(): array
{
    $dir = sys_get_temp_dir() . '/tscrub_offline_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0700, true);
    $priv = $dir . '/priv.pem';
    exec('openssl genpkey -algorithm ED25519 -out ' . escapeshellarg($priv) . ' 2>/dev/null', $o, $rc);
    $pem = $rc === 0 ? (string)shell_exec('openssl pkey -in ' . escapeshellarg($priv) . ' -pubout 2>/dev/null') : '';
    return ['key' => $priv, 'pem' => $pem, 'dir' => $dir];
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

$kp = new_keypair();
t_check('an ephemeral Ed25519 keypair is available', is_file($kp['key']) && strpos($kp['pem'], 'BEGIN PUBLIC KEY') !== false);
$keys = ['test-1' => $kp['pem']];

$certId = 'COD-869047-DS-AIW-3693-QHMI';          // the real 27-char shape
$issuedAt = '2026-10-21T10:00:00Z';

// ---------------------------------------------------------------------------
// 1. Build and verify
// ---------------------------------------------------------------------------
$code = cert_offline_code($certId, $issuedAt, $kp['key'], 'test-1');
t_check('a code is produced', is_string($code) && $code !== '');
t_eq('the code has five pipe-separated fields', 5, count(explode('|', (string)$code)));
t_check('the code starts with the format version', strpos((string)$code, '2|test-1|') === 0);
t_check('the code carries no newline', strpos((string)$code, "\n") === false);

$v = cert_offline_verify((string)$code, $keys);
t_check('a valid code verifies', $v['ok'] === true);
t_eq('verification reports no reason', '', $v['reason']);
t_eq('the cert id round-trips', $certId, $v['cert_id']);
t_eq('the issued-at round-trips', $issuedAt, $v['issued_at']);
t_eq('the kid round-trips', 'test-1', $v['kid']);
t_check('the verified result carries no hash field', !array_key_exists('pdf_sha256', $v));

// ---------------------------------------------------------------------------
// 2. Every signed field is bound
// ---------------------------------------------------------------------------
$parts = explode('|', (string)$code);
function with_field(array $parts, int $i, string $val): string
{
    $parts[$i] = $val;
    return implode('|', $parts);
}
foreach ([
    'the cert id'   => [2, 'COD-869047-DS-AIW-3693-QHMO'],
    'the issued-at' => [3, '2026-10-21T10:00:01Z'],
] as $what => [$idx, $val]) {
    $t = cert_offline_verify(with_field($parts, $idx, $val), $keys);
    t_eq("editing $what of the code is rejected", 'bad-signature', $t['reason']);
    t_check("editing $what does not verify", $t['ok'] === false);
}
$t = cert_offline_verify(with_field($parts, 4, rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=')), $keys);
t_eq('a replaced signature is rejected', 'bad-signature', $t['reason']);

// A signature lifted from another certificate must not transfer: the message
// binds the cert id, so swapping the two halves breaks both.
$codeB = cert_offline_code('COD-111111-AA-BBB-2222-CCCC', $issuedAt, $kp['key'], 'test-1');
$pa = explode('|', (string)$code);
$pb = explode('|', (string)$codeB);
$t = cert_offline_verify(implode('|', array_merge(array_slice($pa, 0, 4), [$pb[4]])), $keys);
t_eq("another certificate's signature is rejected", 'bad-signature', $t['reason']);
$t = cert_offline_verify(implode('|', array_merge(array_slice($pb, 0, 4), [$pa[4]])), $keys);
t_eq('and so is the reverse swap', 'bad-signature', $t['reason']);

// ---------------------------------------------------------------------------
// 3. Trust: pinned kid only
// ---------------------------------------------------------------------------
$other = new_keypair();
$t = cert_offline_verify((string)$code, ['someone-else-1' => $other['pem']]);
t_eq('an unknown kid is rejected as unknown-key', 'unknown-key', $t['reason']);
t_check('an unknown kid never verifies', $t['ok'] === false);

// The forger's move: sign with your own key AND name a kid that is not ours.
$forged = cert_offline_code($certId, $issuedAt, $other['key'], 'test-1');
$t = cert_offline_verify((string)$forged, $keys);
t_eq('a code signed by another key under our kid is rejected', 'bad-signature', $t['reason']);

// Rotation: an old kid stays pinned, so old certificates keep verifying.
$keysBoth = ['test-1' => $kp['pem'], 'test-0' => $other['pem']];
$t = cert_offline_verify((string)$code, $keysBoth);
t_check('an old kid still verifies after a rotation', $t['ok'] === true);

// ---------------------------------------------------------------------------
// 4. The paper: the certificate id printed on the page
// ---------------------------------------------------------------------------
$t = cert_offline_verify((string)$code, $keys, $certId);
t_check('the code verifies against the certificate it was issued for', $t['ok'] === true);
$t = cert_offline_verify((string)$code, $keys, 'COD-111111-AA-BBB-2222-CCCC');
t_eq('the code does not verify against another certificate id', 'cert-mismatch', $t['reason']);
t_check('a certificate mismatch is not "verified"', $t['ok'] === false);

// ---------------------------------------------------------------------------
// 5. Malformed input, and the builder's own guard
// ---------------------------------------------------------------------------
foreach ([
    'empty'                 => '',
    'four fields'           => implode('|', array_slice($parts, 0, 4)),
    'six fields'            => (string)$code . '|extra',
    'unknown version'       => with_field($parts, 0, '3'),
    'cert id with a pipe'   => '2|test-1|COD|1|' . $issuedAt . '|' . $parts[4],
    'bad date'              => with_field($parts, 3, '2026-10-21 10:00:00'),
    'date without Z'        => with_field($parts, 3, '2026-10-21T10:00:00'),
    'non-base64 signature'  => with_field($parts, 4, 'not a signature!'),
    'short signature'       => with_field($parts, 4, 'AAAA'),
] as $what => $bad) {
    $t = cert_offline_verify($bad, $keys);
    t_check("$what is rejected", $t['ok'] === false);
}

t_check('the builder refuses a cert id containing a pipe',
    cert_offline_code('COD|injected', $issuedAt, $kp['key'], 'test-1') === null);
t_check('the builder refuses a non-UTC timestamp',
    cert_offline_code($certId, '2026-10-21 10:00:00', $kp['key'], 'test-1') === null);
t_check('the builder returns null rather than an unsigned code when the key is missing',
    cert_offline_code($certId, $issuedAt, '/nonexistent/key.pem', 'test-1') === null);

// ---------------------------------------------------------------------------
// 6. Size: the payload has to stay printable
// ---------------------------------------------------------------------------
$len = strlen((string)$code);
t_check("the code is at most 170 characters (measured: $len)", $len <= 170);
t_eq('the signature is 86 base64url characters', 86, strlen($parts[4]));

if (class_exists('QRcode')) {
    // The renderer draws the code at ECC H, 30 mm square. Anything above 65
    // modules drops below 5.4 px/module at 300 dpi, where cheap phone cameras
    // start failing, so this is a layout guard, not a style preference.
    $q = new QRcode((string)$code, 'H');
    $arr = $q->getBarcodeArray();
    $rows = (int)($arr['num_rows'] ?? 0);
    t_check("the code fits a 65-module symbol at ECC H (measured: $rows)",
        $rows > 0 && $rows <= 65);

    // Longest realistic case: the production kid (15 chars) with a 27-char
    // certificate id. This is what actually gets printed, and it sits exactly at
    // the guard, so any field growth forces a deliberate layout decision.
    $prod = cert_offline_code($certId, $issuedAt, $kp['key'], 'tscrub-vendor-1');
    $q = new QRcode((string)$prod, 'H');
    $arr = $q->getBarcodeArray();
    $prows = (int)($arr['num_rows'] ?? 0);
    t_check("the production-shaped code fits too (" . strlen((string)$prod)
        . " chars, $prows modules at ECC H)", $prows > 0 && $prows <= 65);
}

// ---------------------------------------------------------------------------
// 7. The certificate actually prints it
// ---------------------------------------------------------------------------
$drive = [
    'serial' => 'OFF-1', 'model' => 'OFF-1-MODEL', 'type' => 'SSD', 'size' => '512 GB',
    'bus' => 'NVMe', 'status' => 'COMPLETED', 'cert' => '', 'method' => 'NVMe Format',
    'cls' => 'CLEAR', 'device' => 'nvme0n1', 'ts' => '2026-10-21 10:00:00',
    'tool_version' => 'v1.11.73', 'passes' => '', 'erase_engine' => '', 'standard' => '',
    'verify_technique' => '', 'verify_coverage_pct' => '',
];
$group = [
    'cocid' => 'TEST-COC-OFFLINE', 'reports' => [],
    'first' => '2026-10-21 09:55:00', 'last' => '2026-10-21 10:05:00',
    'operator' => 'Operator One', 'validator' => '', 'tool_version' => 'v1.11.73',
    'shaState' => 'verified', 'sigState' => 'none', 'drives' => [$drive],
];
$with = render_certificate_pdf($group, 'COD-OFFLINE-1', false, [], [], [], (string)$code)['data'] ?? '';
$without = render_certificate_pdf($group, 'COD-OFFLINE-1', false, [], [], [], '')['data'] ?? '';
t_check('both renders produce a document', strlen($with) > 1000 && strlen($without) > 1000);

$textWith = pdf_lines($with);
$textWithout = pdf_lines($without);
t_check('with a code, the caption says it verifies offline or online',
    strpos($textWith, 'Verify offline or online') !== false);
t_check('without a code, the caption keeps the online wording',
    strpos($textWithout, 'Verify online') !== false
    && strpos($textWithout, 'Verify offline') === false);
t_check('the two documents differ (the QR payload is drawn)',
    hash('sha256', $with) !== hash('sha256', $without));
t_check('the certificate still prints its Certificate ID',
    strpos($textWith, 'COD-OFFLINE-1') !== false);
t_check('the rendered certificate never claims IEEE', stripos($textWith, 'IEEE') === false);

// The generator has to actually pass the code, at BOTH certificate creation
// sites (create and merge-and-re-render). A renderer that can print a code
// nothing supplies is the "helper nobody calls" failure again.
$api = (string)@file_get_contents($serverDir . '/api.php');
t_eq('the generator builds the code before rendering',
    true, strpos($api, "gmdate('Y-m-d\\TH:i:s\\Z')") !== false);
$sites = preg_match_all('/render_certificate_pdf\([^;]*?cert_offline_code\(\$certId, \$issuedAtCode\)/s', $api);
t_eq('both render call sites pass the offline code', 2, (int)$sites);

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

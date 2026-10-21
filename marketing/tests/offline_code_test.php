<?php
/**
 * Phase 1 of the offline / air-gapped certificate verification:
 * the self-contained code and its Ed25519 signature.
 *
 * Plan: research/offline-verification/README.md. Nothing user-visible yet — the
 * certificate's QR is untouched; this file proves the payload format, the
 * signature and, most importantly, the two rejection rules that decide whether
 * the feature is worth anything:
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

$kp = new_keypair();
t_check('an ephemeral Ed25519 keypair is available', is_file($kp['key']) && strpos($kp['pem'], 'BEGIN PUBLIC KEY') !== false);
$keys = ['test-1' => $kp['pem']];

$certId = 'COD-869047-DS-AIW-3693-QHMI';          // the real 27-char shape
$issuedAt = '2026-10-21T10:00:00Z';
$pdfSha = hash('sha256', 'pretend this is the certificate PDF');

// ---------------------------------------------------------------------------
// 1. Build and verify
// ---------------------------------------------------------------------------
$code = cert_offline_code($certId, $issuedAt, $pdfSha, $kp['key'], 'test-1');
t_check('a code is produced', is_string($code) && $code !== '');
t_eq('the code has six pipe-separated fields', 6, count(explode('|', (string)$code)));
t_check('the code starts with the format version', strpos((string)$code, '1|test-1|') === 0);
t_check('the code carries no newline', strpos((string)$code, "\n") === false);

$v = cert_offline_verify((string)$code, $keys);
t_check('a valid code verifies', $v['ok'] === true);
t_eq('verification reports no reason', '', $v['reason']);
t_eq('the cert id round-trips', $certId, $v['cert_id']);
t_eq('the issued-at round-trips', $issuedAt, $v['issued_at']);
t_eq('the pdf hash round-trips', $pdfSha, $v['pdf_sha256']);
t_eq('the kid round-trips', 'test-1', $v['kid']);

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
    'the pdf hash'  => [4, hash('sha256', 'a different document')],
] as $what => [$idx, $val]) {
    $t = cert_offline_verify(with_field($parts, $idx, $val), $keys);
    t_eq("editing $what of the code is rejected", 'bad-signature', $t['reason']);
    t_check("editing $what does not verify", $t['ok'] === false);
}
$t = cert_offline_verify(with_field($parts, 5, rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=')), $keys);
t_eq('a replaced signature is rejected', 'bad-signature', $t['reason']);

// A signature lifted from another certificate must not transfer: the message
// binds the cert id, so swapping the two halves breaks both.
$codeB = cert_offline_code('COD-111111-AA-BBB-2222-CCCC', $issuedAt, $pdfSha, $kp['key'], 'test-1');
$pa = explode('|', (string)$code);
$pb = explode('|', (string)$codeB);
$t = cert_offline_verify(implode('|', array_merge(array_slice($pa, 0, 5), [$pb[5]])), $keys);
t_eq("another certificate's signature is rejected", 'bad-signature', $t['reason']);
$t = cert_offline_verify(implode('|', array_merge(array_slice($pb, 0, 5), [$pa[5]])), $keys);
t_eq('and so is the reverse swap', 'bad-signature', $t['reason']);

// ---------------------------------------------------------------------------
// 3. Trust: pinned kid only
// ---------------------------------------------------------------------------
$other = new_keypair();
$t = cert_offline_verify((string)$code, ['someone-else-1' => $other['pem']]);
t_eq('an unknown kid is rejected as unknown-key', 'unknown-key', $t['reason']);
t_check('an unknown kid never verifies', $t['ok'] === false);

// The forger's move: sign with your own key AND name a kid that is not ours.
$forged = cert_offline_code($certId, $issuedAt, $pdfSha, $other['key'], 'test-1');
$t = cert_offline_verify((string)$forged, $keys);
t_eq('a code signed by another key under our kid is rejected', 'bad-signature', $t['reason']);

// Rotation: an old kid stays pinned, so old certificates keep verifying.
$keysBoth = ['test-1' => $kp['pem'], 'test-0' => $other['pem']];
$t = cert_offline_verify((string)$code, $keysBoth);
t_check('an old kid still verifies after a rotation', $t['ok'] === true);

// ---------------------------------------------------------------------------
// 4. Tying the code to the sheet of paper
// ---------------------------------------------------------------------------
$t = cert_offline_verify((string)$code, $keys, $pdfSha);
t_check('the code verifies against the PDF it was issued for', $t['ok'] === true);
$t = cert_offline_verify((string)$code, $keys, hash('sha256', 'a different document'));
t_eq('the code does not verify against another PDF', 'pdf-mismatch', $t['reason']);
t_check('a pdf mismatch is not "verified"', $t['ok'] === false);

// ---------------------------------------------------------------------------
// 5. Malformed input, and the builder's own guard
// ---------------------------------------------------------------------------
foreach ([
    'empty'                 => '',
    'five fields'           => implode('|', array_slice($parts, 0, 5)),
    'seven fields'          => (string)$code . '|extra',
    'unknown version'       => with_field($parts, 0, '2'),
    'cert id with a pipe'   => '1|test-1|COD-1|2|' . $issuedAt . '|' . $pdfSha . '|' . $parts[5],
    'bad date'              => with_field($parts, 3, '2026-10-21 10:00:00'),
    'short hash'            => with_field($parts, 4, 'abc'),
    'non-base64 signature'  => with_field($parts, 5, 'not a signature!'),
] as $what => $bad) {
    $t = cert_offline_verify($bad, $keys);
    t_check("$what is rejected", $t['ok'] === false);
}

t_check('the builder refuses a cert id containing a pipe',
    cert_offline_code('COD|injected', $issuedAt, $pdfSha, $kp['key'], 'test-1') === null);
t_check('the builder refuses a non-UTC timestamp',
    cert_offline_code($certId, '2026-10-21 10:00:00', $pdfSha, $kp['key'], 'test-1') === null);
t_check('the builder refuses a short pdf hash',
    cert_offline_code($certId, $issuedAt, 'deadbeef', $kp['key'], 'test-1') === null);
t_check('the builder returns null rather than an unsigned code when the key is missing',
    cert_offline_code($certId, $issuedAt, $pdfSha, '/nonexistent/key.pem', 'test-1') === null);

// ---------------------------------------------------------------------------
// 6. Size: the payload has to stay printable
// ---------------------------------------------------------------------------
$len = strlen((string)$code);
t_check("the code is at most 230 characters (measured: $len)", $len <= 230);
t_eq('the signature is 86 base64url characters', 86, strlen($parts[5]));

if (class_exists('QRcode')) {
    $q = new QRcode((string)$code, 'M');
    $arr = $q->getBarcodeArray();
    $rows = (int)($arr['num_rows'] ?? 0);
    // 30 mm at 300 dpi = 354 px; 354/65 = 5.4 px per module is the floor we
    // accept, so a symbol bigger than 65 modules needs a layout change.
    t_check("the code fits a 65-module symbol at ECC M (measured: $rows)", $rows > 0 && $rows <= 65);
}

// ---------------------------------------------------------------------------
// 7. The real document: a rendered certificate's own hash
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
$pdf = render_certificate_pdf($group, 'COD-OFFLINE-1', false, [], [], [])['data'] ?? '';
t_check('a certificate renders for the tie-in test', is_string($pdf) && strlen($pdf) > 1000);
if ($pdf !== '') {
    $realSha = hash('sha256', $pdf);
    $realCode = cert_offline_code('COD-OFFLINE-1', $issuedAt, $realSha, $kp['key'], 'test-1');
    t_check('a code can be built from a real certificate hash', is_string($realCode));
    $t = cert_offline_verify((string)$realCode, $keys, $realSha);
    t_check('the code verifies against the rendered PDF', $t['ok'] === true);
    $t = cert_offline_verify((string)$realCode, $keys, hash('sha256', $pdf . 'x'));
    t_eq('the same code fails against a speck of altered paper', 'pdf-mismatch', $t['reason']);
}

printf("\n%d checks, %d failures\n", $count, $fails);
exit($fails === 0 ? 0 : 1);

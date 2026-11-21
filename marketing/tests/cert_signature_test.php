<?php
declare(strict_types=1);

/**
 * Signed PDFs must keep their trailing %%EOF newline inside the signature.
 *
 * LibreOffice (via PDFium) only records a "trailer end" when the %%EOF marker
 * is followed by an end-of-line, and its PDF signature check marks a document
 * "only partially signed" when the signature's byte range does not end exactly
 * at a recorded trailer end. TCPDF's stock Output() stripped the final newline
 * before signing, so the signed file ended with a bare "%%EOF" and LibreOffice
 * reported every tScrub PDF as partially signed (while Adobe and poppler still
 * said "valid", which is why it was only noticed by the operator).
 *
 *   php tests/cert_signature_test.php [server-dir]
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/render_cert.php'; // defines tScrubPDF (extends TCPDF)

error_reporting(E_ALL & ~E_DEPRECATED);

$count = 0;
$fails = 0;
function t_check(string $label, bool $ok, $detail = null): void {
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; }
    else {
        $fails++;
        echo "FAIL - $label" . ($detail !== null ? ' (got: ' . json_encode($detail) . ')' : '') . "\n";
    }
}

// --- Build and sign a one-page PDF with a throwaway self-signed certificate. ---
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) { fwrite(STDERR, "openssl_pkey_new failed — is ext-openssl loaded?\n"); exit(2); }
$csr = openssl_csr_new(['commonName' => 'tScrub Test'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);

$pdf = new tScrubPDF();
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 12);
$pdf->Cell(0, 10, 'signature regression test', 0, 1);
$pdf->setSignature($certPem, $keyPem, '', '', 2, ['Name' => 'tScrub', 'Reason' => 'regression test']);
$data = $pdf->Output('', 'S');

// --- The actual regression: the signed file must end %%EOF<newline>. ---
t_check('signed output ends with a newline after %%EOF',
    substr($data, -6) === "%%EOF\n",
    substr($data, -8));

// --- And that newline must sit inside the signed byte range. ---
if (preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $data, $m)) {
    [$_, $off1, $len1, $off2, $len2] = array_map('intval', $m);
    $end = $off2 + $len2;
    t_check('second byte range reaches the end of the file',
        $end === strlen($data),
        "end=$end size=" . strlen($data));
    t_check('first byte range starts at byte zero',
        $off1 === 0,
        "off1=$off1");
} else {
    t_check('a /ByteRange is present in the signed PDF', false, 'no ByteRange found');
}

t_check('the signature object is present',
    strpos($data, '/Type /Sig') !== false && strpos($data, '/Contents<') !== false);

// --- Source guard: tScrubPDF pads the trailing newline before TCPDF signs. ---
$src = file_get_contents($serverDir . '/render_cert.php');
t_check('tScrubPDF pads the trailing newline before signing',
    strpos($src, 'function Output($name') !== false
    && strpos($src, '$this->buffer .= "\n";') !== false);

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);

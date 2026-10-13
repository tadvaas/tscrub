<?php
/**
 * Certificate verification page: /verify?cert=COD-XXXXXXXX-XX-XXX-XXXX-XXXX
 * Looks up an issued certificate in MySQL and confirms its details,
 * including the document hash so the PDF can be checked for tampering.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/certifier.php';
require_once __DIR__ . '/jsonld.php';
// Pure vocabulary only (no DB) — the qualifier string must be the same one the PDF and
// the JSON-LD twin print, so a verifier cannot read a different claim on the web page.
require_once __DIR__ . '/manual_destruction.php';

$cert = strtoupper(trim((string)($_GET['cert'] ?? '')));
$format = strtolower(trim((string)($_GET['format'] ?? '')));
if ($format !== 'jsonld' && $format !== 'json') { $format = ''; }
$isValidId = (preg_match('/^COD-[A-Z0-9-]{6,}$/', $cert) === 1);

$record = null;
$certRow = null;
$recordTier = 'free';
$dbError = false;
$attDevices = 0;
$attMedia = 0;
$hasReports = false;
$hasAttested = false;
$attestedSummary = '';
$recordsMoved = false;
if ($isValidId) {
    try {
        $stmt = db()->prepare('SELECT * FROM certificates WHERE cert_id = ?');
        $stmt->execute([$cert]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $certRow = $row;
            $rq = db()->prepare('SELECT report_name AS name, sha256 AS sha, state FROM certificate_reports WHERE certificate_id = ? ORDER BY id');
            $rq->execute([(int)$row['id']]);
            $record = [
                'cert'       => (string)$row['cert_id'],
                'cocid'      => (string)$row['cocid'],
                'devices'    => (int)$row['devices'],
                'methods'    => (int)$row['methods'],
                'runs'       => (int)$row['runs'],
                'first'      => $row['first_ts'],
                'last'       => $row['last_ts'],
                'sha_state'  => (string)$row['sha_state'],
                'sig_state'  => (string)$row['sig_state'],
                'pdf_sha256' => (string)$row['pdf_sha256'],
                'issued'     => (string)$row['issued_at'],
                'reports'    => $rq->fetchAll(),
            ];
            // Operator-attested destruction is not a report and lives in its own tables, so
            // a job made only of it legitimately has zero verified devices. Without these
            // figures the page reads '0 / 0 / 0' on a certificate that records three
            // destroyed machines — and says nothing at all about the destruction it covers.
            try {
                $aq = db()->prepare('SELECT COUNT(*) FROM certificate_devices WHERE certificate_id = ?');
                $aq->execute([(int)$row['id']]);
                $attDevices = (int)$aq->fetchColumn();
                $aq = db()->prepare("SELECT COUNT(*) FROM certificate_drives WHERE certificate_id = ? AND source = 'manual'");
                $aq->execute([(int)$row['id']]);
                $attMedia = (int)$aq->fetchColumn();
            } catch (Throwable $e) {
                // Installs predating attested destruction have no such tables; zero is right.
                $attDevices = 0;
                $attMedia = 0;
            }

            // This page exists to verify a DOCUMENT, so the figures it states are the ones the
            // document carries, read back from the stored JSON-LD snapshot. The live counts
            // are kept alongside them so drift is reported rather than silently papered over.
            $docAttDevices = $attDevices;
            $docAttMedia   = $attMedia;
            $snapshot = jsonld_read_cert($row);
            if (is_array($snapshot['attestedDestruction'] ?? null)) {
                $docAttDevices = (int)($snapshot['attestedDestruction']['devices'] ?? 0);
                $docAttMedia   = (int)($snapshot['attestedDestruction']['media'] ?? 0);
            }
            $recordsMoved = ($docAttDevices !== $attDevices) || ($docAttMedia !== $attMedia);

            // Computed here, BEFORE any prose is chosen: the integrity sentence below has a
            // case for 'no reports at all', and deciding that from an undefined variable
            // would misstate every ordinary certificate.
            $hasReports  = !empty($record['reports']);
            $hasAttested = ($docAttDevices + $docAttMedia) > 0;

            // Two separate figures, never summed: one is instrument-verified, the other is
            // the operator's word. Same rule as the PDF and the JSON-LD twin.
            if ($hasAttested) {
                $devWord = $docAttDevices === 1 ? 'device' : 'devices';
                $medWord = $docAttMedia === 1 ? 'medium' : 'media';
                if ($docAttDevices > 0 && $docAttMedia > 0) {
                    $attestedSummary = $docAttDevices . ' ' . $devWord . ' · ' . $docAttMedia . ' ' . $medWord;
                } elseif ($docAttDevices > 0) {
                    $attestedSummary = $docAttDevices . ' ' . $devWord . ' · no media recorded';
                } else {
                    $attestedSummary = $docAttMedia . ' ' . $medWord;
                }
                // The row label already says 'operator-attested', so only the second half of
                // the canonical qualifier is appended — same string, without the repetition.
                $qualBits = explode('—', md_attested_qualifier());
                $attestedSummary .= ' — ' . trim((string)end($qualBits));
            }
            // Free-tier licences carry no report key, so their reports are
            // self-signed by the appliance and cannot be independently confirmed
            // or attributed. Determine the owner's tier to drive the upsell.
            if (!empty($row['user_id'])) {
                $tq = db()->prepare('SELECT tier FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
                $tq->execute([(int)$row['user_id']]);
                $tr = $tq->fetch();
                if ($tr !== false && !empty($tr['tier'])) {
                    $recordTier = (string)$tr['tier'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('verify.php db error: ' . $e->getMessage());
        $dbError = true;
    }
}

// Machine-readable output: /verify?cert=…&format=jsonld (or format=json).
// JSON-LD is valid JSON, so both formats serve the same signed document.
if ($format !== '') {
    if ($certRow === null) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['ok' => false, 'error' => 'Certificate not found.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    certs_ensure_schema();
    $data = jsonld_ensure_cert($certRow);
    if ($data === null) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['ok' => false, 'error' => 'Machine-readable certificate unavailable.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Content-Type: ' . ($format === 'jsonld' ? 'application/ld+json' : 'application/json') . '; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $data['json'];
    exit;
}

function v_ts($ts) {
    if (is_string($ts) && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $ts, $m)) {
        return $m[1] . ' ' . $m[2];
    }
    if (is_string($ts) && preg_match('/^(\d{4}-\d{2}-\d{2})/', $ts, $m)) {
        return $m[1];
    }
    return $ts === null ? '' : (string)$ts;
}
function v_sha_state($s) {
    if ($s === 'verified') return 'SHA-256 VERIFIED';
    if ($s === 'mismatch') return 'SHA-256 MISMATCH';
    return 'SHA-256 RECORDED';
}
function v_sig_state($s) {
    if ($s === 'attributed') return 'SIGNATURE VALID';
    if ($s === 'valid') return 'SIGNATURE UNATTRIBUTED';
    if ($s === 'invalid') return 'SIGNATURE INVALID';
    return 'NOT SIGNED';
}
function v_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$found = $record !== null;

$window = '';
$integrityNote = 'This certificate of destruction was issued by tScrub.';
if ($found) {
    $first = $record['first'] ?? null;
    $last  = $record['last'] ?? null;
    if ($first !== null) {
        $window = v_ts($first);
        if ($last !== null && $first !== $last) { $window .= ' to ' . v_ts($last); }
    }
    $verified = ($recordTier !== 'free')
        && ($record['sha_state'] ?? '') === 'verified'
        && ($record['sig_state'] ?? '') === 'attributed';
    $allGood = ($record['sha_state'] === 'verified')
        && ($record['sig_state'] === 'attributed');
    if (!$hasReports) {
        // Saying 'some underlying reports did not pass integrity checks' when there are no
        // reports at all is simply false, and it is the sentence the previous code fell
        // through to for exactly this case.
        $integrityNote = 'This certificate of destruction was issued by tScrub. It covers no '
            . 'appliance reports: the destruction it records is a statement by the operator, '
            . 'not an instrument-verified sanitisation.';
    } elseif ($allGood) {
        $integrityNote = 'This certificate of destruction was issued by tScrub and its underlying reports passed integrity checks at the time of issuance.';
    } else {
        $integrityNote = 'This certificate of destruction was issued by tScrub. Note: some underlying reports did not pass integrity checks at the time of issuance — see the report list below.';
    }
}
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $found ? ($verified ? 'Certificate Verified' : 'Certificate Record') : 'Certificate Not Found' ?> — tScrub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  body{font-family:Inter,system-ui,sans-serif;background:#f8fafc;color:#0b1220;margin:0;display:flex;flex-direction:column;min-height:100vh;}
  header{padding:18px 24px;display:flex;align-items:center;gap:10px;background:#fff;border-bottom:1px solid #e2e8f0;}
  header .logo{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:#059669;color:#fff;border-radius:8px;font-family:monospace;font-weight:700;font-size:15px;}
  header .brand{font-weight:800;font-size:18px;letter-spacing:-0.02em;}
  main{flex:1;width:100%;max-width:760px;margin:0 auto;padding:40px 20px;}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px;}
  .status{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:800;margin-bottom:6px;}
  .dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;}
  .ok{color:#059669;} .bad{color:#dc2626;} .warn{color:#d97706;}
  .dot.ok{background:#059669;} .dot.bad{background:#dc2626;} .dot.warn{background:#d97706;}
  .upsell{margin-top:14px;padding:14px 16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;font-size:13.5px;color:#92400e;}
  .upsell a{font-weight:700;color:#b45309;}
  .sub{color:#64748b;font-size:14px;margin-bottom:20px;}
  table{width:100%;border-collapse:collapse;font-size:14px;}
  th,td{text-align:left;padding:9px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top;}
  th{color:#64748b;font-weight:600;width:40%;}
  td code{font-size:12px;word-break:break-all;}
  .reports p{margin:16px 0 6px;font-weight:700;font-size:13px;}
  .reports div{padding:4px 0;border-bottom:1px dashed #e2e8f0;font-size:12px;color:#475569;word-break:break-all;}
  .note{margin-top:18px;font-size:12.5px;color:#64748b;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;}
  a{color:#059669;}
  footer{padding:18px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0;background:#fff;}
</style>
</head>
<body>
<header><span class="logo">t</span><span class="brand">tScrub</span></header>
<main>
  <div class="card">
<?php if ($found): ?>
    <?php if ($hasAttested && !$hasReports): ?>
    <div class="status"><span class="dot warn"></span><span class="warn">Certificate Record — operator-attested destruction</span></div>
    <div class="sub"><?= v_h($integrityNote) ?></div>
    <?php elseif ($recordTier !== 'free' && $verified): ?>
    <div class="status"><span class="dot ok"></span><span class="ok">Certificate Verified</span></div>
    <div class="sub"><?= v_h($integrityNote) ?></div>
    <?php elseif ($recordTier !== 'free'): ?>
    <div class="status"><span class="dot warn"></span><span class="warn">Certificate Record — signature not attributable</span></div>
    <div class="sub">This certificate's reports are signed, but the signature could not be matched to the account's licence, so it is recorded but not independently confirmed as attributable. <?= v_h($integrityNote) ?></div>
    <?php else: ?>
    <div class="status"><span class="dot warn"></span><span class="warn">Free-tier certificate — cannot be confirmed</span></div>
    <div class="sub">This certificate was generated on the free tier. Free-tier reports are self-signed by the appliance, so their authenticity cannot be independently confirmed or attributed to a verified organisation.</div>
    <div class="upsell">Upgrade to a commercial plan to enable signed, attributable verification of your certificates of destruction. <a href="/pricing">See plans</a></div>
    <?php endif; ?>
    <table>
      <tr><th>Certificate ID</th><td><code><?= v_h($record['cert'] ?? $cert) ?></code></td></tr>
      <tr><th>Chain of Custody ID</th><td><?= v_h($record['cocid'] ?? '') ?></td></tr>
      <tr><th>Issued</th><td><?= v_h(v_ts($record['issued'] ?? '')) ?></td></tr>
      <?php if ($window !== ''): ?>
      <tr><th>Sanitisation window</th><td><?= v_h($window) ?></td></tr>
      <?php endif; ?>
      <tr><th><?= $hasAttested ? 'Verified devices / methods / runs' : 'Devices / Methods / Runs' ?></th><td><?= (int)($record['devices'] ?? 0) ?> / <?= (int)($record['methods'] ?? 0) ?> / <?= (int)($record['runs'] ?? 0) ?></td></tr>
      <?php if ($hasAttested): ?>
      <tr><th>Operator-attested</th><td><?= v_h($attestedSummary) ?></td></tr>
      <?php endif; ?>
      <tr><th>Integrity</th><td><?= v_h(v_sha_state($record['sha_state'] ?? 'unverified')) ?></td></tr>
      <tr><th>Signature</th><td><?php
        // 'Self-signed by the appliance' describes a report signature, so it is wrong on a
        // certificate with no reports — as is claiming an unsigned document is signed.
        if (!$hasReports) { echo 'Not applicable — this certificate covers no appliance reports'; }
        elseif ($recordTier === 'free') { echo 'Self-signed — not attributable'; }
        else { echo v_h(v_sig_state($record['sig_state'] ?? 'none')); }
      ?></td></tr>
      <tr><th>Document hash (SHA-256)</th><td><code><?= v_h($record['pdf_sha256'] ?? '') ?></code></td></tr>
      <tr><th>Machine-readable</th><td><a href="/verify?cert=<?= v_h(urlencode($record['cert'] ?? $cert)) ?>&amp;format=jsonld">Machine-readable JSON-LD certificate</a></td></tr>
    </table>
    <?php if ($recordsMoved): ?>
    <div class="note"><strong>These records have changed since this certificate was issued.</strong>
      The certificate states <?= (int)$docAttDevices ?> <?= $docAttDevices === 1 ? 'device' : 'devices' ?> and
      <?= (int)$docAttMedia ?> <?= $docAttMedia === 1 ? 'medium' : 'media' ?> recorded as operator-attested;
      the records now hold <?= (int)$attDevices ?> <?= $attDevices === 1 ? 'device' : 'devices' ?> and
      <?= (int)$attMedia ?> <?= $attMedia === 1 ? 'medium' : 'media' ?>. The document hash above still identifies
      the certificate as issued, so regenerate the certificate if an updated document is needed.</div>
    <?php endif; ?>
    <?php if (!empty($record['reports'])): ?>
    <div class="reports">
      <p>Consolidated reports</p>
      <?php foreach ((array)$record['reports'] as $r): ?>
      <div><?= v_h($r['name'] ?? '') ?> — <code><?= v_h($r['sha'] ?? '') ?></code></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="note">To check this document hasn't been altered, compute the SHA-256 of your PDF and compare it with the document hash above.</div>
<?php else: ?>
    <?php if ($dbError): ?>
    <div class="status"><span class="dot bad"></span><span class="bad">Verification Unavailable</span></div>
    <div class="sub">The verification service is temporarily unavailable. Please try again shortly.</div>
    <?php else: ?>
    <div class="status"><span class="dot bad"></span><span class="bad">Certificate Not Found</span></div>
    <div class="sub"><?= $isValidId ? 'No certificate with that ID was issued through tscrub.com.' : 'The certificate ID format is invalid.' ?></div>
    <div class="note">Use the exact Certificate ID printed on the certificate (for example <code>COD-123456-AB-CDE-1234-ABCD</code>).</div>
    <?php endif; ?>
<?php endif; ?>
  </div>
</main>
<footer>tScrub — verifiable disk sanitisation · <a href="/docs">Documentation</a></footer>
</body>
</html>

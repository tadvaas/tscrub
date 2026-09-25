<?php
/**
 * Certificate of Destruction generator.
 * POST multipart: reports[] = .csv (+ .csv.sig, .json). Returns a PDF.
 * Verifies the SHA-256 and (when present) the Ed25519 signature, then renders
 * a certificate via TCPDF.
 */

// ---- helpers ---------------------------------------------------------------

function fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function run_cmd(array $cmd) {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { return null; }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

function tmpfile_path($data) {
    $p = tempnam(sys_get_temp_dir(), 'tscrub-cert-');
    file_put_contents($p, $data);
    return $p;
}

function fmt_ts($ts) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', (string)$ts, $m)) {
        return $m[1] . ' ' . $m[2];
    }
    return (string)$ts;
}

function fmt_date($ts) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string)$ts, $m)) {
        return $m[1];
    }
    return fmt_ts($ts);
}

function type_label($type, $model) {
    $t = strtoupper(trim((string)$type));
    $map = [
        'SSD' => 'Solid State Drive (SSD)',
        'HDD' => 'Hard Disk Drive (HDD)',
        'EMMC' => 'Onboard Storage (eMMC)',
        'USB' => 'Flash Media (USB / SD)',
        'SD' => 'Flash Media (USB / SD)',
    ];
    if ($t !== '' && isset($map[$t])) return $map[$t];
    if (stripos((string)$model, 'EMMC') !== false) return 'Onboard Storage (eMMC)';
    return $type !== '' ? $type : '-';
}

function method_label($method, $cls) {
    $m = strtoupper(trim((string)$method));
    $c = strtoupper(trim((string)$cls));
    $aliases = [
        'ENHANCED ERASE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'SECURE ERASE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'ATA SECURE ERASE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'ATA ENHANCED SECURE ERASE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'NVME FORMAT' => 'NVMe Controller-Level Format (Clear) - Verified',
        'NVME CRYPTO ERASE' => 'NVMe Controller-Level Format (Clear) - Verified',
        'NVME CRYPTO PURGE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'SCSI SANITIZE OVERWRITE' => 'Sanitisation (Purge) - Verified',
        'SCSI SANITIZE' => 'Sanitisation (Purge) - Verified',
        'FACTORY RESET' => 'Manufacturer Factory Reset (Clear) - Verified',
        'MANUFACTURER FACTORY RESET' => 'Manufacturer Factory Reset (Clear) - Verified',
        'PHYSICAL DESTRUCTION' => 'Manual Dismantling + Media Destruction',
        'PHYSICAL DESTR.' => 'Manual Dismantling + Media Destruction',
    ];
    if ($m !== '') {
        if (isset($aliases[$m])) return $aliases[$m];
        if (strpos($m, 'FACTORY RESET') !== false) return 'Manufacturer Factory Reset (Clear) - Verified';
        if (strpos($m, 'PHYSICAL DESTR') !== false) return 'Manual Dismantling + Media Destruction';
    }
    $clsMap = [
        'PURGE' => 'Controller-Level Secure Erase (Purge) - Verified',
        'CLEAR' => 'NVMe Controller-Level Format (Clear) - Verified',
        'SANITISATION' => 'Sanitisation (Purge) - Verified',
        'DESTRUCTION' => 'Manual Dismantling + Media Destruction',
    ];
    if ($c !== '' && isset($clsMap[$c])) return $clsMap[$c];
    return $method !== '' ? $method : '-';
}

function gen_cert_id() {
    $p1 = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $p2 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
    $p3 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 3));
    $p4 = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $p5 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 4));
    return 'COD-' . $p1 . '-' . $p2 . '-' . $p3 . '-' . $p4 . '-' . $p5;
}

// ---- main ------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST only');
}

if (empty($_FILES['reports']) || !is_array($_FILES['reports']['name'])) {
    fail(400, 'Upload one or more tScrub report files (.csv).');
}

// 1) Collect uploaded files, bucketing CSVs, manifests and signatures by stem.
$csvs = [];       // stem => ['name' =>, 'tmp' =>]
$manifests = [];  // stem => tmp path
$sigs = [];       // stem => tmp path

foreach ($_FILES['reports']['name'] as $i => $name) {
    if (($_FILES['reports']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $tmp = $_FILES['reports']['tmp_name'][$i];
    if (!is_uploaded_file($tmp)) continue;
    if (strlen($name) > 200 || filesize($tmp) > 2_000_000) continue;

    $l = strtolower($name);
    if (substr($l, -4) === '.csv') {
        $csvs[substr($name, 0, -4)] = ['name' => $name, 'tmp' => $tmp];
    } elseif (substr($l, -8) === '.csv.sig') {
        $sigs[substr($name, 0, -8)] = $tmp;
    } elseif (substr($l, -4) === '.sig') {
        $sigs[substr($name, 0, -4)] = $tmp;
    } elseif (substr($l, -5) === '.json') {
        $manifests[substr($name, 0, -5)] = $tmp;
    }
}

if (!$csvs) {
    fail(400, 'Upload one or more tScrub report files (.csv).');
}

// 2) Parse each CSV and consolidate drives by Chain of Custody ID.
$groups = [];
$seenShas = [];    // report-file SHA-256s already ingested (whole-file dedup)
$seenSerials = []; // per-COCID serial numbers already listed (drive dedup)
foreach ($csvs as $stem => $csv) {
    // Skip an identical report file already uploaded under another name.
    $sha = strtolower(hash_file('sha256', $csv['tmp']));
    if (isset($seenShas[$sha])) continue;
    $seenShas[$sha] = true;

    $fh = fopen($csv['tmp'], 'r');
    if (!$fh) continue;
    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); continue; }

    // Map columns by name so both the 12-column production format and any
    // legacy format with System/SystemSerial/BaseboardSerial are accepted.
    $map = [];
    foreach ($header as $idx => $col) { $map[strtolower(trim((string)$col))] = $idx; }
    $get = function ($row, $key) use (&$map) {
        $idx = $map[$key] ?? null;
        return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
    };

    $cocid = '';
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row) || count($row) < 2) continue;
        if ($get($row, 'model') === '' && $get($row, 'serial') === '') continue;
        if ($cocid === '') $cocid = preg_replace('/[^A-Za-z0-9_-]/', '', $get($row, 'cocid'));
        $rows[] = $row;
    }
    fclose($fh);

    if (!$rows) continue;

    if ($cocid === '' && preg_match('/_(\d{5})_/', $csv['name'], $mm)) $cocid = $mm[1];
    if ($cocid === '') $cocid = 'UNKNOWN';

    // SHA-256 (already computed above) + optional Ed25519 signature verification.
    $manifestData = null;
    if (isset($manifests[$stem])) {
        $decoded = json_decode(file_get_contents($manifests[$stem]), true);
        if (is_array($decoded)) $manifestData = $decoded;
    }
    $recorded = $manifestData ? strtolower((string)($manifestData['sha256'] ?? '')) : '';
    $shaState = 'unverified';
    if ($recorded !== '') {
        $shaState = hash_equals($recorded, $sha) ? 'verified' : 'mismatch';
    }

    $sigState = 'none';
    if ($manifestData && !empty($manifestData['signed']) && !empty($manifestData['public_key']) && isset($sigs[$stem])) {
        $pubPath = tmpfile_path(base64_decode((string)$manifestData['public_key']));
        $sigPath = tmpfile_path(base64_decode(file_get_contents($sigs[$stem])));
        $r = run_cmd(['openssl', 'pkeyutl', '-verify', '-pubin', '-inkey', $pubPath,
                      '-rawin', '-in', $csv['tmp'], '-sigfile', $sigPath]);
        $sigState = ($r && $r[0] === 0) ? 'valid' : 'invalid';
        @unlink($pubPath); @unlink($sigPath);
    }

    if (!isset($groups[$cocid])) {
        $groups[$cocid] = [
            'cocid' => $cocid, 'drives' => [], 'reports' => [],
            'shaState' => 'unverified', 'sigState' => 'none',
            'system' => '', 'sysSerial' => '', 'bbSerial' => '',
            'first' => null, 'last' => null,
        ];
    }
    $g = &$groups[$cocid];
    $g['reports'][] = ['name' => $csv['name'], 'sha' => $sha, 'state' => $shaState];

    if ($shaState === 'mismatch') $g['shaState'] = 'mismatch';
    elseif ($shaState === 'verified' && $g['shaState'] !== 'mismatch') $g['shaState'] = 'verified';

    if ($sigState === 'invalid') $g['sigState'] = 'invalid';
    elseif ($sigState === 'valid' && $g['sigState'] !== 'invalid') $g['sigState'] = 'valid';

    $seenSerials[$cocid] = $seenSerials[$cocid] ?? [];
    foreach ($rows as $row) {
        $serial = $get($row, 'serial');
        if ($serial !== '') {
            $dkey = strtolower($serial);
            if (isset($seenSerials[$cocid][$dkey])) continue; // duplicate drive in this COCID
            $seenSerials[$cocid][$dkey] = true;
        }
        $ts = $get($row, 'timestamp');
        $g['drives'][] = [
            'ts' => $ts,
            'model' => $get($row, 'model'),
            'serial' => $get($row, 'serial'),
            'device' => $get($row, 'device'),
            'type' => $get($row, 'type'),
            'size' => $get($row, 'size'),
            'bus' => $get($row, 'bus'),
            'method' => $get($row, 'method'),
            'cls' => $get($row, 'class'),
            'cert' => $get($row, 'certification'),
            'status' => $get($row, 'finalstatus'),
            'system' => $get($row, 'system'),
            'sysserial' => $get($row, 'systemserial'),
            'bbserial' => $get($row, 'baseboardserial'),
        ];
        if ($g['system'] === '' && isset($map['system'])) {
            $g['system'] = $get($row, 'system');
            $g['sysSerial'] = $get($row, 'systemserial');
            $g['bbSerial'] = $get($row, 'baseboardserial');
        }
        if ($ts !== '') {
            if ($g['first'] === null || $ts < $g['first']) $g['first'] = $ts;
            if ($g['last'] === null || $ts > $g['last']) $g['last'] = $ts;
        }
    }
    unset($g);
}

// 3) Render the certificate(s) with TCPDF (landscape A4, tSoft-style layout).
require_once(__DIR__ . '/tcpdf/tcpdf.php');

class tScrubPDF extends TCPDF {
    public function __construct() {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false; // remove the hidden "Powered by TCPDF" link
    }
}

function tscrub_render_annex(TCPDF $pdf, float $x, float $W, float $H, string $certId, string $cocid, array $drives, array $reports): void {
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, 10);
    $pdf->Cell($W - 2 * $x, 10, 'ANNEX A - DEVICE DETAILS', 0, 1, 'C');

    // Column definitions: key => [label, width(mm), align].
    $cols = [
        '#'         => ['label' => '#',                'w' => 8,  'align' => 'C'],
        'size'      => ['label' => 'Size',             'w' => 20, 'align' => 'L'],
        'type'      => ['label' => 'Type',             'w' => 32, 'align' => 'L'],
        'model'     => ['label' => 'Model',            'w' => 56, 'align' => 'L'],
        'serial'    => ['label' => 'Serial',           'w' => 42, 'align' => 'L'],
        'cert'      => ['label' => 'Certification',    'w' => 28, 'align' => 'L'],
        'method'    => ['label' => 'Method',           'w' => 56, 'align' => 'L'],
        'ts'        => ['label' => 'Wiped',            'w' => 26, 'align' => 'C'],
        'system'    => ['label' => 'System',           'w' => 30, 'align' => 'L'],
        'sysserial' => ['label' => 'System serial',    'w' => 22, 'align' => 'L'],
        'bbserial'  => ['label' => 'Baseboard serial', 'w' => 22, 'align' => 'L'],
    ];

    $cell = function (array $d, string $key, int $idx): string {
        switch ($key) {
            case '#':         return (string)$idx;
            case 'size':      return (string)$d['size'];
            case 'type':      return type_label($d['type'], $d['model']);
            case 'model':     return (string)$d['model'];
            case 'serial':    return (string)$d['serial'];
            case 'cert':      return (string)$d['cert'];
            case 'method':    return method_label($d['method'], $d['cls']);
            case 'ts':        return fmt_ts($d['ts']);
            case 'system':    return (string)$d['system'];
            case 'sysserial': return (string)$d['sysserial'];
            case 'bbserial':  return (string)$d['bbserial'];
        }
        return '';
    };

    $rawEmpty = function (array $d, string $key): bool {
        switch ($key) {
            case 'size':      return trim((string)$d['size']) === '';
            case 'type':      return trim((string)$d['type']) === '';
            case 'model':     return trim((string)$d['model']) === '';
            case 'serial':    return trim((string)$d['serial']) === '';
            case 'cert':      return trim((string)$d['cert']) === '';
            case 'method':    return trim((string)$d['method']) === '' && trim((string)$d['cls']) === '';
            case 'ts':        return trim((string)$d['ts']) === '';
            case 'system':    return trim((string)$d['system']) === '';
            case 'sysserial': return trim((string)$d['sysserial']) === '';
            case 'bbserial':  return trim((string)$d['bbserial']) === '';
        }
        return false;
    };

    // Drop columns that are empty for every drive (e.g. a missing Size column).
    foreach (array_keys($cols) as $key) {
        if ($key === '#') continue;
        $allEmpty = true;
        foreach ($drives as $d) {
            if (!$rawEmpty($d, $key)) { $allEmpty = false; break; }
        }
        if ($allEmpty) unset($cols[$key]);
    }

    // Scale the remaining widths to fill the usable page width.
    $total = 0.0;
    foreach ($cols as $c) { $total += $c['w']; }
    $usable = $W - 2 * $x;
    foreach ($cols as $k => $c) { $cols[$k]['w'] = round($c['w'] * $usable / $total, 2); }

    $keys = array_keys($cols);
    $nCols = count($cols);

    $pdf->setCellPaddings(1.4, 0.9, 1.4, 0.9); // table cell padding

    $drawHeader = function (float $y) use ($pdf, $cols, $keys, $nCols, $x, $W, $H, $certId) {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($W - 175, $H - 12);
        $pdf->Cell(165, 4, 'Certificate ID: ' . $certId . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');

        $pdf->SetFont('helvetica', 'B', 7.5);
        $headerH = 0.0;
        foreach ($keys as $key) {
            $h = $pdf->getStringHeight($cols[$key]['w'] - 1.2, $cols[$key]['label'], false, true);
            if ($h > $headerH) $headerH = $h;
        }
        $headerH += 0.8;

        $pdf->SetXY($x, $y);
        $pdf->SetFillColor(11, 18, 32);
        $pdf->SetTextColor(255, 255, 255);
        $i = 0;
        foreach ($keys as $key) {
            $i++;
            $pdf->MultiCell($cols[$key]['w'], $headerH, $cols[$key]['label'], 1, 'C', true, ($i === $nCols) ? 1 : 0, '', '', true);
        }
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetFont('helvetica', '', 7.5);
    };

    $drawHeader(30);

    $idx = 0;
    foreach ($drives as $d) {
        $idx++;
        $row = [];
        foreach ($keys as $key) { $row[] = $cell($d, $key, $idx); }

        $required = 6.0;
        foreach ($keys as $ci => $key) {
            // measure with a slightly narrower width so boundary wraps are counted conservatively
            $h = $pdf->getStringHeight($cols[$key]['w'] - 1.2, (string)$row[$ci], false, true);
            if ($h > $required) $required = $h;
        }
        $required += 0.8; // safety margin

        if ($pdf->GetY() + $required > $H - 16) {
            $pdf->AddPage();
            $drawHeader(12);
        }

        $pdf->SetX($x);
        foreach ($keys as $ci => $key) {
            $pdf->MultiCell($cols[$key]['w'], $required, (string)$row[$ci], 'LTR', $cols[$key]['align'], false, ($ci === $nCols - 1) ? 1 : 0, '', '', true);
        }

        $rightX = $x + array_sum(array_column($cols, 'w'));
        $y = $pdf->GetY();
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $y, $rightX, $y);
        $pdf->SetDrawColor(0, 0, 0);
    }

    $pdf->setCellPaddings(0, 0, 0, 0);

    // Report manifest: each uploaded report, its SHA-256 and verification state.
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, $pdf->GetY() + 8);
    $pdf->Cell($W - 2 * $x, 6, 'REPORT MANIFEST', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(71, 85, 105);
    foreach ($reports as $r) {
        $mark = $r['state'] === 'mismatch' ? '[FAIL]' : '[OK]';
        $state = $r['state'] === 'verified' ? 'sha256 verified' : ($r['state'] === 'mismatch' ? 'MISMATCH' : 'sha256 recorded');
        $line = $mark . '  ' . $r['name'] . '  -  ' . $r['sha'] . '  (' . $state . ')';

        if ($pdf->GetY() + 4 > $H - 12) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(71, 85, 105);
        }
        $pdf->SetX($x);
        $pdf->Cell($W - 2 * $x, 4, $line, 0, 1, 'L');
    }
}

ksort($groups);

$certIds = [];
$registryEntries = [];

$pdf = new tScrubPDF();
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->SetAutoPageBreak(false);
$pdf->SetMargins(0, 0, 0, true);
$pdf->setCellPaddings(0, 0, 0, 0);
$pdf->SetCreator('tScrub');
$pdf->SetTitle('Certificate of Destruction');

// Digitally sign the PDF (self-signed X.509) for tamper-evidence.
$signCert = __DIR__ . '/sign.crt';
$signKey  = __DIR__ . '/sign.key';
if (is_file($signCert) && is_file($signKey)) {
    $pdf->setSignature(
        'file://' . $signCert,
        'file://' . $signKey,
        '',
        '',
        2,
        [
            'Name' => 'tScrub',
            'Location' => 'United Kingdom',
            'Reason' => 'Certificate of Destruction',
            'ContactInfo' => 'https://tscrub.com',
        ]
    );
}

$W = 297.0;
$H = 210.0;
$BG_IMAGE = __DIR__ . '/cert-bg.png';

// Issuer identity (edit for your legal entity).
$ISSUER = [
    'name' => 'TFix Ltd',
    'reg'  => 'Company No. 07892418',
    'addr' => 'Generator Business Centre, 95 Miles Road, Mitcham, Surrey, CR4 3FH',
];
$RETENTION = '6 years';

$tableX = 10.0;

foreach ($groups as $cocid => $g) {
    usort($g['drives'], function ($a, $b) {
        $c = strcmp($a['ts'], $b['ts']);
        return $c !== 0 ? $c : strcmp($a['device'], $b['device']);
    });

    $methodsUsed = [];
    foreach ($g['drives'] as $d) {
        $methodsUsed[method_label($d['method'], $d['cls'])] = true;
    }

    $certId = gen_cert_id();
    $certIds[] = $certId;
    $devices = count($g['drives']);
    $methods = count($methodsUsed);
    $runs = count($g['reports']);

    $range = 'N/A';
    if ($g['first'] !== null) {
        if ($g['first'] === $g['last']) {
            $range = fmt_ts($g['first']);
        } elseif (substr((string)$g['first'], 0, 10) === substr((string)$g['last'], 0, 10)) {
            $range = fmt_ts($g['first']) . ' to ' . fmt_ts($g['last']);
        } else {
            $range = fmt_date($g['first']) . ' to ' . fmt_date($g['last']);
        }
    }

    $registryEntries[] = [
        'cert' => $certId,
        'cocid' => (string)$cocid,
        'devices' => $devices,
        'methods' => $methods,
        'runs' => $runs,
        'first' => $g['first'],
        'last' => $g['last'],
        'sha_state' => $g['shaState'],
        'sig_state' => $g['sigState'],
        'reports' => $g['reports'],
    ];

    if ($g['shaState'] === 'verified')     { $shaTxt = 'SHA-256 VERIFIED'; $shaColor = [5, 150, 105]; }
    elseif ($g['shaState'] === 'mismatch') { $shaTxt = 'SHA-256 MISMATCH'; $shaColor = [220, 38, 38]; }
    else                                   { $shaTxt = 'SHA-256 RECORDED'; $shaColor = [71, 85, 105]; }

    if ($g['sigState'] === 'valid')        { $sigTxt = 'SIGNATURE VALID'; $sigColor = [5, 150, 105]; }
    elseif ($g['sigState'] === 'invalid')  { $sigTxt = 'SIGNATURE INVALID'; $sigColor = [220, 38, 38]; }
    else                                   { $sigTxt = 'NOT SIGNED'; $sigColor = [71, 85, 105]; }

    // --- Summary page ---
    $pdf->AddPage();
    $pdf->Image($BG_IMAGE, 0, 0, $W, $H, '', '', '', false, 300);

    $pdf->SetFont('helvetica', 'B', 30);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(10, 24);
    $pdf->Cell($W - 20, 14, 'CERTIFICATE OF DESTRUCTION', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 13);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(10, 42);
    $pdf->Cell($W - 20, 6, 'Chain of Custody ID: ' . $cocid, 0, 1, 'C');

    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 54);
    $pdf->Cell($W - 60, 9, 'DEVICES LISTED IN ANNEX A', 'B', 1, 'C');

    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->SetTextColor(51, 65, 85);
    $summary = 'The devices listed in Annex A were processed under controlled chain-of-custody procedures '
        . 'from receipt to final verification. Destruction, or sanitisation where applicable, was completed '
        . 'using approved methods selected for each device type. Each device was logged, reconciled to the '
        . 'Chain of Custody ID, and recorded to support UK GDPR and Data Protection Act 2018 accountability requirements.';
    $pdf->SetXY(25, 67);
    $pdf->MultiCell($W - 50, 5, $summary, 0, 'C');

    $bx = [30, 112.5, 195];
    $bw = 72.0;

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(11, 18, 32);
    foreach (['Certification Date', 'Certificate ID', 'Chain of Custody ID'] as $k => $lbl) {
        $pdf->SetXY($bx[$k], 88);
        $pdf->Cell($bw, 5, $lbl, 0, 0, 'C');
    }
    $pdf->SetFont('helvetica', '', 10);
    $pdf->setCellPaddings(1.6, 0, 1.6, 0);
    foreach ([$range, $certId, $cocid] as $k => $val) {
        $pdf->SetXY($bx[$k], 93.5);
        $pdf->Cell($bw, 8, $val, 1, 0, 'C');
    }

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(11, 18, 32);
    foreach (['Devices', 'Methods Used', 'Runs Consolidated'] as $k => $lbl) {
        $pdf->SetXY($bx[$k], 108);
        $pdf->Cell($bw, 5, $lbl, 0, 0, 'C');
    }
    $pdf->SetFont('helvetica', '', 10);
    foreach ([(string)$devices, (string)$methods, (string)$runs] as $k => $val) {
        $pdf->SetXY($bx[$k], 113.5);
        $pdf->Cell($bw, 8, $val, 1, 0, 'C');
    }

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 128);
    $pdf->Cell($bw, 5, 'Integrity', 0, 0, 'C');
    $pdf->SetXY(112.5, 128);
    $pdf->Cell($bw, 5, 'Signature', 0, 0, 'C');

    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor($shaColor[0], $shaColor[1], $shaColor[2]);
    $pdf->SetXY(30, 133.5);
    $pdf->Cell($bw, 8, $shaTxt, 1, 0, 'C');
    $pdf->SetTextColor($sigColor[0], $sigColor[1], $sigColor[2]);
    $pdf->SetXY(112.5, 133.5);
    $pdf->Cell($bw, 8, $sigTxt, 1, 0, 'C');
    $pdf->setCellPaddings(0, 0, 0, 0);

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $scope = 'This certificate confirms controlled processing of the listed devices only, performed under chain-of-custody procedures '
        . 'aligned with NIST SP 800-88 Rev 1 and UK NCSC guidance, in support of UK GDPR and Data Protection Act 2018 accountability. '
        . 'Item-level evidence is recorded in Annex A and traceable by Certificate ID ' . $certId . ' and Chain of Custody ID ' . $cocid . '. '
        . 'Records are retained for ' . $RETENTION . '. Verify authenticity at tscrub.com/docs or scan the QR code.';
    $pdf->SetXY(25, 146);
    $pdf->MultiCell($W - 50, 4, $scope, 0, 'C');

    // Issuer identity block (bottom-left).
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 166);
    $pdf->Cell(120, 4.5, 'Issued by ' . $ISSUER['name'], 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(71, 85, 105);
    $y = 171.5;
    if ($ISSUER['reg'] !== '') {
        $pdf->SetXY(30, $y);
        $pdf->Cell(120, 4, $ISSUER['reg'], 0, 1, 'L');
        $y += 4;
    }
    if ($ISSUER['addr'] !== '') {
        $pdf->SetXY(30, $y);
        $pdf->Cell(120, 4, $ISSUER['addr'], 0, 1, 'L');
        $y += 4;
    }
    $pdf->SetXY(30, $y);
    $pdf->Cell(120, 4, 'Certificate issued: ' . date('Y-m-d'), 0, 1, 'L');

    // Verification QR code (bottom-right).
    $verifyUrl = 'https://tscrub.com/verify?cert=' . $certId;
    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', 238, 160, 28, 28, ['border' => 0, 'padding' => 0], 'N');
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(238, 189);
    $pdf->Cell(28, 4, 'Verify online', 0, 0, 'C');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(30, 194);
    $pdf->Cell($W - 60, 5, 'Issued by tScrub - verifiable disk sanitisation  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    // --- Annex A ---
    tscrub_render_annex($pdf, $tableX, $W, $H, $certId, $cocid, $g['drives'], $g['reports']);
}

// 4) Output the PDF.
$pdfData = $pdf->Output('', 'S');
$pdfSha = strtolower(hash('sha256', $pdfData));

// 4b) Record issuance so the certificate can be re-verified online at /verify.
$registryDir = __DIR__ . '/certificates';
if (!is_dir($registryDir)) { @mkdir($registryDir, 0775, true); }
foreach ($registryEntries as $entry) {
    $entry['issued'] = gmdate('Y-m-d\TH:i:s\Z');
    $entry['pdf_sha256'] = $pdfSha;
    @file_put_contents(
        $registryDir . '/' . $entry['cert'] . '.json',
        json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

$filename = (count($groups) === 1)
    ? 'Certificate-of-Destruction-' . array_key_first($groups) . '-' . $certIds[0] . '.pdf'
    : 'Certificates-of-Destruction-' . $certIds[0] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfData));
echo $pdfData;

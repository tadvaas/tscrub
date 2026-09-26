<?php
declare(strict_types=1);

/**
 * Shared Certificate-of-Destruction PDF renderer.
 *
 * Used by api.php (machine ingestion and dashboard certificate generation) so
 * that every certificate gets its own tamper-evident PDF — one PDF per Chain
 * of Custody ID, never one combined PDF for a multi-COCID upload.
 */

require_once __DIR__ . '/tcpdf/tcpdf.php';

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

function method_label($method, $cls, $status = '') {
    // Status-aware: a drive that did not complete must never be labelled
    // "verified" or imply a wipe/destruction that did not happen.
    $s = strtoupper(trim((string)$status));
    if ($s === 'DESTROYED') return 'Physical Destruction (operator-confirmed)';
    if ($s === 'FAILED')    return 'Not sanitised — erasure failed';
    if ($s === 'BLOCKED')   return 'Not sanitised — blocked by firmware (Block SID)';
    if ($s === 'FROZEN')    return 'Frozen — Physical Destruction Required';
    if ($s === 'UNKNOWN')   return 'Not sanitised — outcome unknown';
    if ($s === 'DRY-RUN')   return 'Dry run — no sanitisation performed';

    $m = strtoupper(trim((string)$method));
    $c = strtoupper(trim((string)$cls));
    $aliases = [
        // NVMe purge methods (current product strings).
        'NVME CRYPTO PURGE'      => 'NVMe Crypto Sanitise (Purge) - Verified',
        'NVME BLOCK PURGE'       => 'NVMe Block Sanitise (Purge) - Verified',
        'NVME OVERWRITE PURGE'   => 'NVMe Overwrite Sanitise (Purge) - Verified',
        // ATA purge.
        'ENHANCED ERASE'           => 'ATA Enhanced Secure Erase (Purge) - Verified',
        'ATA ENHANCED SECURE ERASE' => 'ATA Enhanced Secure Erase (Purge) - Verified',
        // ATA clear (SSD).
        'ATA SECURE ERASE'   => 'ATA Security Erase (Clear) - Verified',
        'ATA SECURITY ERASE' => 'ATA Security Erase (Clear) - Verified',
        // ATA clear on an HDD is classified PURGE by the product.
        'HDD OVERWRITE' => 'Software Overwrite (Purge) - Verified',
        // SCSI software wipe.
        'NWIPE QUICK'            => 'SCSI Software Overwrite (Clear) - Verified',
        'SCSI CLEAR (NWIPE QUICK)' => 'SCSI Software Overwrite (Clear) - Verified',
        // NVMe clear.
        'NVME FORMAT'       => 'NVMe Controller-Level Format (Clear) - Verified',
        'NVME CRYPTO ERASE' => 'NVMe Controller-Level Format (Clear) - Verified',
        // Legacy generic NVMe purge string (pre-2026-09-19 reports).
        'SECURE ERASE' => 'Controller-Level Secure Erase (Purge) - Verified',
        // Physical destruction / frozen.
        'PHYSICAL DESTRUCTION' => 'Manual Dismantling + Media Destruction',
        'PHYSICAL DESTR.'      => 'Manual Dismantling + Media Destruction',
        'PHYS. DESTR.'         => 'Manual Dismantling + Media Destruction',
        'FROZEN DRIVE'         => 'Frozen — Physical Destruction Required',
        'SCSI SANITIZE OVERWRITE' => 'Sanitisation (Purge) - Verified',
        'SCSI SANITIZE'          => 'Sanitisation (Purge) - Verified',
        'FACTORY RESET'          => 'Manufacturer Factory Reset (Clear) - Verified',
        'MANUFACTURER FACTORY RESET' => 'Manufacturer Factory Reset (Clear) - Verified',
    ];
    if ($m !== '') {
        if (isset($aliases[$m])) return $aliases[$m];
        if (strpos($m, 'FACTORY RESET') !== false) return 'Manufacturer Factory Reset (Clear) - Verified';
        if (strpos($m, 'PHYSICAL DESTR') !== false) return 'Manual Dismantling + Media Destruction';
        if (strpos($m, 'NWIPE') !== false) return 'SCSI Software Overwrite (Clear) - Verified';
        if (strpos($m, 'FROZEN') !== false) return 'Frozen — Physical Destruction Required';
    }
    $clsMap = [
        'PURGE'        => 'Controller-Level Secure Erase (Purge) - Verified',
        'CLEAR'        => 'Sanitisation (Clear) - Verified',
        'SANITISATION' => 'Sanitisation (Purge) - Verified',
        'DESTRUCTION'  => 'Manual Dismantling + Media Destruction',
    ];
    if ($c !== '' && isset($clsMap[$c])) return $clsMap[$c];
    return $method !== '' ? $method : '-';
}

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
        'status'    => ['label' => 'Status',           'w' => 20, 'align' => 'L'],
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
            case 'method':    return method_label($d['method'], $d['cls'], $d['status']);
            case 'status':    return (string)$d['status'];
            case 'ts':        return (strtoupper(trim((string)($d['status'] ?? ''))) === 'COMPLETED') ? fmt_ts($d['ts']) : '';
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
            case 'status':    return trim((string)$d['status']) === '';
            case 'ts':        return trim((string)$d['ts']) === '';
            case 'system':    return trim((string)$d['system']) === '';
            case 'sysserial': return trim((string)$d['sysserial']) === '';
            case 'bbserial':  return trim((string)$d['bbserial']) === '';
        }
        return false;
    };

    // Drop columns that are empty for every drive (e.g. a missing Size column).
    // The Status column is only shown when at least one drive is not COMPLETED,
    // so FAILED / BLOCKED / FROZEN drives are visible on the certificate.
    foreach (array_keys($cols) as $key) {
        if ($key === '#') continue;
        $allEmpty = true;
        foreach ($drives as $d) {
            if ($key === 'status') {
                $s = strtoupper(trim((string)($d['status'] ?? '')));
                if ($s !== '' && $s !== 'COMPLETED') { $allEmpty = false; break; }
            } elseif (!$rawEmpty($d, $key)) {
                $allEmpty = false;
                break;
            }
        }
        if ($allEmpty) unset($cols[$key]);
    }

    // Scale the remaining widths to fill the usable page width.
    $total = 0.0;
    foreach ($cols as $c) { $total += $c['w']; }
    $usable = $W - 2 * $x;
    foreach ($cols as $k => $c) { $cols[$k]['w'] = round($c['w'] * $usable / $total, 2); }

    // Fixed-vocabulary columns must stay on one line — otherwise they wrap
    // mid-word ("COMPLETE" + "D", or a split "2026-09-21 / 10:30" timestamp).
    // Steal any shortfall from the widest other column so the table still
    // spans the full page width. (Cell padding is 1.4mm each side = 2.8mm.)
    $minOneLine = [
        'status' => 'COMPLETED',
        'ts'     => '2026-09-21 10:30',
    ];
    foreach ($minOneLine as $key => $sample) {
        if (!isset($cols[$key])) continue;
        $need = $pdf->getStringWidth($sample, 'helvetica', '', 7.5) + 3.2; // text + padding + margin
        if ($cols[$key]['w'] >= $need) continue;
        $deficit = $need - $cols[$key]['w'];
        $cols[$key]['w'] = round($need, 2);
        $widestKey = null;
        $widestW = 0.0;
        foreach ($cols as $k => $c) {
            if ($k === 'status' || $k === 'ts' || $k === '#') continue;
            if ($c['w'] > $widestW) { $widestW = $c['w']; $widestKey = $k; }
        }
        if ($widestKey !== null) {
            $cols[$widestKey]['w'] = round(max(1.0, $cols[$widestKey]['w'] - $deficit), 2);
        }
    }

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
        $mark = $r['state'] === 'mismatch' ? '[FAIL]' : ($r['state'] === 'verified' ? '[OK]' : '[REC]');
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

function smart_fmt(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (strtoupper($v) === 'UNSUP') return 'N/S';
    return $v;
}

function tscrub_render_smart(TCPDF $pdf, float $x, float $W, float $H, string $certId, string $cocid, array $drives): void {
    $smartKeys = ['smart', 'tempc', 'poweronhours', 'powercycles', 'reallocsectors', 'pctused', 'availspare', 'tbw_tb', 'smartpost', 'tempcpost', 'poweronhourspost'];

    // Only render when at least one drive carries SMART data (legacy CSVs have none).
    $hasData = false;
    foreach ($drives as $d) {
        foreach ($smartKeys as $k) {
            $v = trim((string)($d[$k] ?? ''));
            if ($v !== '' && strtoupper($v) !== 'UNSUP') { $hasData = true; break 2; }
        }
    }
    if (!$hasData) return;

    $cols = [
        'device'           => ['label' => 'Device',            'w' => 22, 'align' => 'L'],
        'serial'           => ['label' => 'Serial',            'w' => 40, 'align' => 'L'],
        'smart'            => ['label' => 'SMART',             'w' => 16, 'align' => 'C'],
        'tempc'            => ['label' => 'Temp °C',           'w' => 15, 'align' => 'C'],
        'poweronhours'     => ['label' => 'Power-on h',        'w' => 18, 'align' => 'C'],
        'powercycles'      => ['label' => 'Power cycles',      'w' => 18, 'align' => 'C'],
        'reallocsectors'   => ['label' => 'Realloc sectors',   'w' => 20, 'align' => 'C'],
        'pctused'          => ['label' => 'Used %',            'w' => 14, 'align' => 'C'],
        'availspare'       => ['label' => 'Avail spare',       'w' => 18, 'align' => 'C'],
        'tbw_tb'           => ['label' => 'TBW (TB)',          'w' => 16, 'align' => 'C'],
        'smartpost'        => ['label' => 'SMART (post)',      'w' => 22, 'align' => 'C'],
        'tempcpost'        => ['label' => 'Temp °C (post)',    'w' => 22, 'align' => 'C'],
        'poweronhourspost' => ['label' => 'Power-on h (post)', 'w' => 24, 'align' => 'C'],
    ];

    // Drop columns that are empty for every drive.
    foreach (array_keys($cols) as $key) {
        if ($key === 'device' || $key === 'serial') continue;
        $allEmpty = true;
        foreach ($drives as $d) {
            $v = trim((string)($d[$key] ?? ''));
            if ($v !== '' && strtoupper($v) !== 'UNSUP') { $allEmpty = false; break; }
        }
        if ($allEmpty) unset($cols[$key]);
    }
    if (count($cols) <= 2) return;

    // Scale the remaining widths to fill the usable page width.
    $total = 0.0;
    foreach ($cols as $c) { $total += $c['w']; }
    $usable = $W - 2 * $x;
    foreach ($cols as $k => $c) { $cols[$k]['w'] = round($c['w'] * $usable / $total, 2); }

    $keys = array_keys($cols);
    $nCols = count($cols);

    $pdf->AddPage();
    $pdf->setCellPaddings(1.2, 0.8, 1.2, 0.8);

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, 10);
    $pdf->Cell($W - 2 * $x, 10, 'ANNEX B - SMART CAPTURE', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY($x, 21);
    $pdf->Cell($W - 2 * $x, 5, 'Pre- and post-wipe SMART health recorded for each device in Chain of Custody ID ' . $cocid . '.', 0, 1, 'C');

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

    foreach ($drives as $d) {
        $row = [];
        foreach ($keys as $key) {
            $row[] = ($key === 'device' || $key === 'serial')
                ? (string)($d[$key] ?? '')
                : smart_fmt((string)($d[$key] ?? ''));
        }

        $required = 5.0;
        foreach ($keys as $ci => $key) {
            $h = $pdf->getStringHeight($cols[$key]['w'] - 1.2, (string)$row[$ci], false, true);
            if ($h > $required) $required = $h;
        }
        $required += 0.8;

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
}

/**
 * Render one certificate (one Chain of Custody ID) into its own PDF.
 * Returns the PDF bytes, its SHA-256, the computed counts, and the sorted
 * drive rows (so the caller can persist them in the same order).
 */
function render_certificate_pdf(array $g, string $certId, bool $canSign, array $issuer = [], array $destroyed = []): array {
    $cocid = (string)$g['cocid'];
    $drives = $g['drives'];
    usort($drives, function ($a, $b) {
        $c = strcmp($a['ts'], $b['ts']);
        return $c !== 0 ? $c : strcmp($a['device'], $b['device']);
    });

    // Apply the operator's physical-destruction decisions. Drives that already
    // carry a DESTROYED status (from a previous generation/merge) are kept.
    $destroyedSet = [];
    foreach ($destroyed as $s) { $destroyedSet[strtolower(trim((string)$s))] = true; }
    foreach ($drives as $i => $d) {
        $st = strtoupper(trim((string)($d['status'] ?? '')));
        if ($st === 'COMPLETED' || $st === 'DESTROYED') continue;
        $s = strtolower(trim((string)($d['serial'] ?? '')));
        if ($s !== '' && isset($destroyedSet[$s])) {
            $drives[$i]['status'] = 'DESTROYED';
            $drives[$i]['cert']   = 'DESTRUCTION';
            $drives[$i]['method'] = 'PHYSICAL DESTRUCTION';
        } else {
            $drives[$i]['cert'] = ($st === 'FROZEN') ? 'DESTRUCTION REQUIRED' : 'NOT SANITISED';
        }
    }
    $nonCompleted = 0;
    $destroyedCount = 0;
    foreach ($drives as $d) {
        $st = strtoupper(trim((string)($d['status'] ?? '')));
        if ($st === 'COMPLETED') continue;
        $nonCompleted++;
        if ($st === 'DESTROYED') $destroyedCount++;
    }

    $methodsUsed = [];
    foreach ($drives as $d) {
        $methodsUsed[method_label($d['method'], $d['cls'], $d['status'])] = true;
    }

    $devices = count($drives);
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

    if ($g['shaState'] === 'verified')     { $shaTxt = 'SHA-256 VERIFIED'; $shaColor = [5, 150, 105]; }
    elseif ($g['shaState'] === 'mismatch') { $shaTxt = 'SHA-256 MISMATCH'; $shaColor = [220, 38, 38]; }
    else                                   { $shaTxt = 'SHA-256 RECORDED'; $shaColor = [71, 85, 105]; }

    if (!$canSign)                       { $sigTxt = 'FREE TIER — NO VERIFICATION'; $sigColor = [100, 116, 139]; }
    elseif ($g['sigState'] === 'attributed') { $sigTxt = 'REPORT SIGNATURE VALID'; $sigColor = [5, 150, 105]; }
    elseif ($g['sigState'] === 'valid')  { $sigTxt = 'SIGNATURE UNATTRIBUTED'; $sigColor = [217, 119, 6]; }
    elseif ($g['sigState'] === 'invalid'){ $sigTxt = 'REPORT SIGNATURE INVALID'; $sigColor = [220, 38, 38]; }
    else                                 { $sigTxt = 'REPORT NOT SIGNED'; $sigColor = [71, 85, 105]; }

    $W = 297.0;
    $H = 210.0;
    $BG_IMAGE = __DIR__ . '/cert-bg.png';

    // Certifying party — the customer's own organisation/person, not tScrub.
    // Falls back to a neutral label if the profile has no details yet.
    $ISSUER = $issuer !== [] ? $issuer : [
        'name'  => 'The certifying organisation',
        'reg'   => '',
        'addr'  => '',
        'phone' => '',
    ];
    $RETENTION = '6 years';
    $tableX = 10.0;

    // Digitally sign the PDF (self-signed X.509) for tamper-evidence. Paid tiers only.
    $signCert = __DIR__ . '/sign.crt';
    $signKey  = __DIR__ . '/sign.key';

    $pdf = new tScrubPDF();
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0, true);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetCreator('tScrub');
    $pdf->SetTitle('Certificate of Destruction');
    if ($canSign && is_file($signCert) && is_file($signKey)) {
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
    $integrityCaveat = ($g['shaState'] === 'mismatch' || $g['sigState'] === 'invalid')
        ? 'NOTE: One or more underlying reports failed integrity verification at issuance. '
        : '';
    $outcomeCaveat = '';
    if ($nonCompleted > 0) {
        $outcomeCaveat = 'NOTE: ' . $nonCompleted . ' device(s) listed in Annex A were not sanitised';
        if ($destroyedCount > 0) {
            $outcomeCaveat .= '; ' . $destroyedCount . ' were physically destroyed (operator-confirmed)';
        }
        $outcomeCaveat .= '. ';
    }
    $summary = $integrityCaveat . $outcomeCaveat
        . 'The devices listed in Annex A were processed under controlled chain-of-custody procedures '
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
        . 'Records are retained for ' . $RETENTION . '. '
        . ($canSign ? 'Verify authenticity at tscrub.com/docs or scan the QR code.' : 'Verify authenticity at tscrub.com/docs by Certificate ID or scan the QR code.');
    $pdf->SetXY(25, 146);
    $pdf->MultiCell($W - 50, 4, $scope, 0, 'C');

    // Certifier identity block (bottom-left).
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 166);
    $pdf->Cell(120, 4.5, 'Certified by ' . $ISSUER['name'], 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(71, 85, 105);
    $y = 171.5;
    if (($ISSUER['reg'] ?? '') !== '') {
        $pdf->SetXY(30, $y);
        $pdf->Cell(120, 4, $ISSUER['reg'], 0, 1, 'L');
        $y += 4;
    }
    if (($ISSUER['addr'] ?? '') !== '') {
        $pdf->SetXY(30, $y);
        $pdf->Cell(120, 4, $ISSUER['addr'], 0, 1, 'L');
        $y += 4;
    }
    if (($ISSUER['phone'] ?? '') !== '') {
        $pdf->SetXY(30, $y);
        $pdf->Cell(120, 4, 'Tel: ' . $ISSUER['phone'], 0, 1, 'L');
        $y += 4;
    }
    $pdf->SetXY(30, $y);
    $pdf->Cell(120, 4, 'Prepared with tScrub — certificate issued ' . gmdate('Y-m-d'), 0, 1, 'L');

    // Verification QR code (bottom-right) — on every certificate, paid or free.
    // It encodes the /verify lookup URL; the digital signature (above) is the
    // tier-gated extra.
    $verifyUrl = 'https://tscrub.com/verify?cert=' . $certId;
    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', 238, 160, 28, 28, ['border' => 0, 'padding' => 0], 'N');
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(238, 189);
    $pdf->Cell(28, 4, 'Verify online', 0, 0, 'C');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(30, 194);
    $pdf->Cell($W - 60, 5, 'Prepared with tScrub — verifiable disk sanitisation  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    // --- Annex A + Annex B ---
    tscrub_render_annex($pdf, $tableX, $W, $H, $certId, $cocid, $drives, $g['reports']);
    tscrub_render_smart($pdf, $tableX, $W, $H, $certId, $cocid, $drives);

    $data = $pdf->Output('', 'S');
    return [
        'data'    => $data,
        'sha'     => strtolower(hash('sha256', $data)),
        'devices' => $devices,
        'methods' => $methods,
        'runs'    => $runs,
        'drives'  => $drives,
    ];
}

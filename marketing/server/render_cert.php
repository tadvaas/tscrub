<?php
declare(strict_types=1);

/**
 * Shared Certificate-of-Destruction PDF renderer.
 *
 * Used by api.php (machine ingestion and dashboard certificate generation) so
 * that every certificate gets its own tamper-evident PDF — one PDF per Chain
 * of Custody ID, never one combined PDF for a multi-COCID upload.
 *
 * Layout (landscape A4, redrawn from the erasure-certificate design research):
 *   page 1 — summary: outcome banner, certification date / certificate ID /
 *            CoC ID, erasure information (incl. machines used), personnel &
 *            authority, attestation, two signature blocks, integrity +
 *            signature badges, verification QR.
 *   page 2+ — Annex A: one card per drive (identity, geometry, erasure method,
 *            level, timing, SMART pre/post, self-tests) + the report manifest.
 *
 * Built exclusively from TCPDF's native Cell / MultiCell / Line / RoundedRect
 * primitives (no writeHTML). render_diag.php requires this file for tScrubPDF,
 * fmt_ts / fmt_date / type_label.
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

/**
 * Fit a value into an exact width budget using the PDF's real font metrics,
 * ellipsising at a character boundary so no glyph is ever clipped mid-cell.
 */
function cert_fit(TCPDF $pdf, string $s, float $widthMm, string $style = 'B', float $size = 8.5): string {
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    if ($pdf->getStringWidth($s, 'helvetica', $style, $size) <= $widthMm - 0.6) {
        return $s;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    $sub = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $out = $s;
    while ($len > 1) {
        $len--;
        $out = $sub($out, 0, $len);
        if ($pdf->getStringWidth($out . '…', 'helvetica', $style, $size) <= $widthMm - 0.6) {
            return $out . '…';
        }
    }
    return '…';
}

/** Group integer digits with thousands separators (3907029168 → 3,907,029,168). */
function cert_grp(string $v): string {
    if ($v === '' || !preg_match('/^\d+$/', $v)) {
        return $v;
    }
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $v);
}

/** The tScrub brand mark — embeds the canonical logo.png (https://tscrub.com/logo.png). */
function cert_logo_mark(TCPDF $pdf, float $x, float $y, float $size = 11.0): void {
    $png = __DIR__ . '/logo.png';
    if (is_file($png)) {
        $pdf->Image($png, $x, $y, $size, $size, 'PNG');
        return;
    }
    // Fallback: draw the mark natively when the PNG is absent.
    $pdf->SetFillColor(5, 150, 105);
    $pdf->RoundedRect($x, $y, $size, $size, $size * 0.24, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', $size * 0.68);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($x, $y);
    $pdf->Cell($size, $size, 't', 0, 0, 'C', false, '', 0, false, 'C', 'M');
}

/** Colour for a drive final status (used for badges and chips). */
function cert_status_color(string $s): array {
    $u = strtoupper(trim($s));
    if ($u === 'COMPLETED') return [5, 150, 105];
    if ($u === 'DESTROYED') return [217, 119, 6];
    if ($u === 'DRY-RUN')   return [100, 116, 139];
    if ($u === 'FROZEN' || $u === 'BLOCKED' || $u === 'FAILED' || $u === 'UNKNOWN') return [220, 38, 38];
    return [148, 163, 184];
}

/** Colour for a PASS/FAIL/UNKNOWN verdict (SMART, self-test). */
function cert_verdict_color(string $v): array {
    $u = strtoupper(trim($v));
    if ($u === 'PASS')    return [5, 150, 105];
    if ($u === 'FAIL')    return [220, 38, 38];
    if ($u === 'UNKNOWN') return [217, 119, 6];
    return [148, 163, 184];
}

/** Format a duration in seconds as a compact human string (95 → "1m 35s"). */
function cert_duration(int $secs): string {
    if ($secs <= 0) return '';
    if ($secs < 60) return $secs . 's';
    if ($secs < 3600) { $m = intdiv($secs, 60); $s = $secs % 60; return $s ? $m . 'm ' . $s . 's' : $m . 'm'; }
    $h = intdiv($secs, 3600); $m = intdiv($secs % 3600, 60);
    return $m ? $h . 'h ' . $m . 'm' : $h . 'h';
}

/** Highest NIST sanitisation level reached across the drives (Destruction > Purge > Clear). */
function cert_level(array $drives): string {
    $rank = 0;
    foreach ($drives as $d) {
        $st  = strtoupper(trim((string)($d['status'] ?? '')));
        $cls = strtoupper(trim((string)($d['cls'] ?? '')));
        if ($st === 'DESTROYED') { $rank = max($rank, 3); continue; }
        if ($st !== 'COMPLETED') continue;
        if (strpos($cls, 'PURGE') !== false)     $rank = max($rank, 2);
        elseif (strpos($cls, 'CLEAR') !== false) $rank = max($rank, 1);
        else                                     $rank = max($rank, 1);
    }
    if ($rank === 3) return 'Destruction';
    if ($rank === 2) return 'Purge';
    if ($rank === 1) return 'Clear';
    return 'Not sanitised';
}

/** Compact (single-line) sanitisation method label for the fleet table. */
function cert_method_short(string $method, string $cls, string $status): string {
    $s = strtoupper(trim($status));
    if ($s === 'DESTROYED') return 'Physical destruction';
    if ($s === 'FAILED')    return 'Erasure failed';
    if ($s === 'BLOCKED')   return 'Blocked (Block SID)';
    if ($s === 'FROZEN')    return 'Frozen';
    if ($s === 'UNKNOWN')   return 'Outcome unknown';
    if ($s === 'DRY-RUN')   return 'Dry run';
    $m = trim($method);
    if ($m !== '' && strpos(strtoupper($m), 'PHYSICAL DESTR') === false) return $m;
    $c = strtoupper(trim($cls));
    $clsMap = ['PURGE' => 'Purge (controller)', 'CLEAR' => 'Clear (controller)', 'SANITISATION' => 'Sanitisation', 'DESTRUCTION' => 'Destruction'];
    return $clsMap[$c] ?? ($m !== '' ? $m : '-');
}

/**
 * Dense one-row-per-drive table for large fleets (200 drives ≈ 6 pages instead
 * of ~100 card pages). Shows the audit-critical fields only.
 */
function cert_render_table(TCPDF $pdf, float $x, float $W, float $H, string $certId, array $drives): void {
    $bg = function () use ($pdf, $W, $H): void {
        $pdf->SetFillColor(250, 249, 246);
        $pdf->Rect(0, 0, $W, $H, 'F');
    };
    $pageLabel = function () use ($pdf, $W, $certId): void {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($W - 175, 8);
        $pdf->Cell(165, 4, 'Certificate ID: ' . $certId . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');
    };

    $cols = [
        '#'        => ['label' => '#',            'w' => 7,  'align' => 'C'],
        'model'    => ['label' => 'Drive model',  'w' => 46, 'align' => 'L'],
        'serial'   => ['label' => 'Drive serial', 'w' => 30, 'align' => 'L'],
        'type'     => ['label' => 'Type',         'w' => 11, 'align' => 'L'],
        'size'     => ['label' => 'Drive size',   'w' => 14, 'align' => 'L'],
        'bus'      => ['label' => 'Bus',          'w' => 12, 'align' => 'L'],
        'cls'      => ['label' => 'Class',        'w' => 11, 'align' => 'L'],
        'method'   => ['label' => 'Method',       'w' => 42, 'align' => 'L'],
        'tool'     => ['label' => 'Tool',         'w' => 15, 'align' => 'L'],
        'system'   => ['label' => 'Machine',      'w' => 32, 'align' => 'L'],
        'sysserial'=> ['label' => 'Machine SN',   'w' => 18, 'align' => 'L'],
        'status'   => ['label' => 'Status',       'w' => 15, 'align' => 'L'],
        'verify'   => ['label' => 'Verified',     'w' => 15, 'align' => 'C'],
        'hpa'      => ['label' => 'HPA/DCO',      'w' => 16, 'align' => 'C'],
        'ts'       => ['label' => 'Erased',       'w' => 22, 'align' => 'C'],
    ];

    $cell = function (array $d, string $key, int $idx): string {
        switch ($key) {
            case '#':      return (string)$idx;
            case 'model':  return (string)($d['model'] ?? '');
            case 'serial': return (string)($d['serial'] ?? '');
            case 'type':   return strtoupper((string)($d['type'] ?? ''));
            case 'size':   return (string)($d['size'] ?? '');
            case 'bus':    return (string)($d['bus'] ?? '');
            case 'cls':    return strtoupper((string)($d['cls'] ?? ''));
            case 'method': return cert_method_short((string)($d['method'] ?? ''), (string)($d['cls'] ?? ''), (string)($d['status'] ?? ''));
            case 'tool': {
                $tv = trim((string)($d['tool_version'] ?? ''));
                return $tv !== '' ? 'tScrub ' . $tv : 'tScrub';
            }
            case 'system':    return (string)($d['system'] ?? '');
            case 'sysserial': return (string)($d['sysserial'] ?? '');
            case 'status': return (string)($d['status'] ?? '');
            case 'verify': return cert_verify_short((string)($d['verify_result'] ?? ''));
            case 'hpa':    return cert_hpa_short((string)($d['hpa_result'] ?? ''), (string)($d['dco_result'] ?? ''));
            case 'ts':     return (strtoupper(trim((string)($d['status'] ?? ''))) === 'COMPLETED') ? fmt_ts((string)($d['ts'] ?? '')) : '';
        }
        return '';
    };

    $rawEmpty = function (array $d, string $key): bool {
        switch ($key) {
            case 'model':  return trim((string)($d['model'] ?? '')) === '';
            case 'serial': return trim((string)($d['serial'] ?? '')) === '';
            case 'type':   return trim((string)($d['type'] ?? '')) === '';
            case 'size':   return trim((string)($d['size'] ?? '')) === '';
            case 'bus':    return trim((string)($d['bus'] ?? '')) === '';
            case 'cls':    return trim((string)($d['cls'] ?? '')) === '';
            case 'method': return trim((string)($d['method'] ?? '')) === '' && trim((string)($d['cls'] ?? '')) === '';
            case 'tool':   return trim((string)($d['tool_version'] ?? '')) === '';
            case 'system':    return trim((string)($d['system'] ?? '')) === '';
            case 'sysserial': return trim((string)($d['sysserial'] ?? '')) === '';
            case 'status': return trim((string)($d['status'] ?? '')) === '';
            case 'verify': return trim((string)($d['verify_result'] ?? '')) === '';
            case 'hpa':    return trim((string)($d['hpa_result'] ?? '')) === '' && trim((string)($d['dco_result'] ?? '')) === '';
            case 'ts':     return trim((string)($d['ts'] ?? '')) === '';
        }
        return false;
    };

    // Drop columns empty for every drive; show Status only when at least one
    // drive is not COMPLETED (an all-green column adds nothing).
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

    // Scale widths to fill the page.
    $total = 0.0;
    foreach ($cols as $c) { $total += $c['w']; }
    $usable = $W - 2 * $x;
    foreach ($cols as $k => $c) { $cols[$k]['w'] = round($c['w'] * $usable / $total, 2); }

    // Keep fixed-vocabulary columns on one line.
    $minOneLine = ['status' => 'COMPLETED', 'ts' => '2026-09-21 10:30', 'tool' => 'tScrub 9.9.99'];
    foreach ($minOneLine as $key => $sample) {
        if (!isset($cols[$key])) continue;
        $need = $pdf->getStringWidth($sample, 'helvetica', '', 7.5) + 3.2;
        if ($cols[$key]['w'] >= $need) continue;
        $deficit = $need - $cols[$key]['w'];
        $cols[$key]['w'] = round($need, 2);
        $widestKey = null; $widestW = 0.0;
        foreach ($cols as $k => $c) {
            if ($k === 'status' || $k === 'ts' || $k === '#') continue;
            if ($c['w'] > $widestW) { $widestW = $c['w']; $widestKey = $k; }
        }
        if ($widestKey !== null) $cols[$widestKey]['w'] = round(max(1.0, $cols[$widestKey]['w'] - $deficit), 2);
    }

    $keys = array_keys($cols);
    $nCols = count($cols);
    $pdf->setCellPaddings(1.1, 0.6, 1.1, 0.6);

    $drawHeader = function (float $y) use ($pdf, $cols, $keys, $nCols, $x) {
        $pdf->SetFont('helvetica', 'B', 7.5);
        $headerH = 0.0;
        foreach ($keys as $key) {
            $h = $pdf->getStringHeight($cols[$key]['w'] - 1.2, $cols[$key]['label'], false, true);
            if ($h > $headerH) $headerH = $h;
        }
        $headerH += 0.8;

        // TCPDF's MultiCell fill is unreliable with setCellPaddings + ln=0, so
        // draw the dark header band explicitly and render the labels on top.
        $totalW = 0.0;
        foreach ($cols as $c) { $totalW += $c['w']; }
        $pdf->SetFillColor(11, 18, 32);
        $pdf->Rect($x, $y, $totalW, $headerH, 'F');

        $pdf->SetXY($x, $y);
        $pdf->SetTextColor(255, 255, 255);
        $i = 0;
        foreach ($keys as $key) {
            $i++;
            $pdf->MultiCell($cols[$key]['w'], $headerH, $cols[$key]['label'], 0, 'C', false, ($i === $nCols) ? 1 : 0, '', '', true);
        }
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetFont('helvetica', '', 7.5);
    };

    $drawHeader(28);

    $idx = 0;
    foreach ($drives as $d) {
        $idx++;
        $row = [];
        foreach ($keys as $key) { $row[] = $cell($d, $key, $idx); }

        $required = 5.0;
        foreach ($keys as $ci => $key) {
            $h = $pdf->getStringHeight($cols[$key]['w'] - 1.2, (string)$row[$ci], false, true);
            if ($h > $required) $required = $h;
        }
        $required += 0.6;

        if ($pdf->GetY() + $required > $H - 12) {
            $pdf->AddPage();
            $bg();
            $pageLabel();
            $drawHeader(12);
        }

        $pdf->SetX($x);
        foreach ($keys as $ci => $key) {
            if ($key === 'status') {
                $sc = cert_status_color((string)($d['status'] ?? ''));
                $pdf->SetTextColor($sc[0], $sc[1], $sc[2]);
            } else {
                $pdf->SetTextColor(11, 18, 32);
            }
            $pdf->MultiCell($cols[$key]['w'], $required, (string)$row[$ci], 'LTR', $cols[$key]['align'], false, ($ci === $nCols - 1) ? 1 : 0, '', '', true);
        }
        $pdf->SetTextColor(11, 18, 32);

        $rightX = $x + array_sum(array_column($cols, 'w'));
        $yy = $pdf->GetY();
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $yy, $rightX, $yy);
        $pdf->SetDrawColor(0, 0, 0);
    }

    $pdf->setCellPaddings(0, 0, 0, 0);
}

/** Report manifest — always begins on its own page. */
function cert_render_manifest(TCPDF $pdf, float $x, float $W, float $H, string $certId, array $reports): void {
    $bg = function () use ($pdf, $W, $H): void {
        $pdf->SetFillColor(250, 249, 246);
        $pdf->Rect(0, 0, $W, $H, 'F');
    };
    $pageLabel = function () use ($pdf, $W, $certId): void {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($W - 175, 8);
        $pdf->Cell(165, 4, 'Certificate ID: ' . $certId . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');
    };

    $pdf->AddPage();
    $bg();
    $pageLabel();
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, 20);
    $pdf->Cell($W - 2 * $x, 6, 'REPORT MANIFEST', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetY(28);
    foreach ($reports as $r) {
        $mark  = ($r['state'] ?? '') === 'mismatch' ? '[FAIL]' : (($r['state'] ?? '') === 'verified' ? '[OK]' : '[REC]');
        $state = ($r['state'] ?? '') === 'verified' ? 'sha256 verified' : (($r['state'] ?? '') === 'mismatch' ? 'MISMATCH' : 'sha256 recorded');
        $line  = $mark . '  ' . ($r['name'] ?? '') . '  -  ' . ($r['sha'] ?? '') . '  (' . $state . ')';

        if ($pdf->GetY() + 4 > $H - 12) {
            $pdf->AddPage();
            $bg();
            $pageLabel();
            $pdf->SetY(14);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(71, 85, 105);
        }
        $pdf->SetX($x);
        $pdf->Cell($W - 2 * $x, 4, $line, 0, 1, 'L');
    }
}

function cert_render_annex(TCPDF $pdf, float $x, float $W, float $H, string $certId, string $cocid, array $drives, array $reports): void {
    $bg = function () use ($pdf, $W, $H): void {
        $pdf->SetFillColor(250, 249, 246);
        $pdf->Rect(0, 0, $W, $H, 'F');
    };
    $pageLabel = function () use ($pdf, $W, $certId): void {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($W - 175, 8);
        $pdf->Cell(165, 4, 'Certificate ID: ' . $certId . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');
    };

    $pdf->AddPage();
    $bg();
    $pageLabel();
    cert_logo_mark($pdf, 14, 10);

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, 10);
    $pdf->Cell($W - 2 * $x, 10, 'ANNEX A — DEVICE DETAILS', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($x, 21);
    $pdf->Cell($W - 2 * $x, 4.5, 'Item-level record of ' . count($drives) . ' device(s) processed under Chain of Custody ID ' . $cocid . '.', 0, 1, 'C');

    if ($drives === []) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, 60);
        $pdf->Cell($W - 2 * $x, 8, 'No devices recorded.', 0, 1, 'C');
        return;
    }

    // Dense one-row-per-drive table, then the report manifest (own page).
    cert_render_table($pdf, $x, $W, $H, $certId, $drives);
    cert_render_manifest($pdf, $x, $W, $H, $certId, $reports);
}

/** Short per-drive verification label for the Annex A table. */
function cert_verify_short(string $result): string {
    $r = strtoupper(trim($result));
    if ($r === 'PASSED') return 'Passed';
    if ($r === 'FAILED') return 'FAILED';
    if ($r === 'UNREADABLE') return 'Unreadable';
    if ($r === 'SKIPPED') return 'Skipped';
    return '-';
}

/** Page-1 "Verification" summary line — honest: mode + outcome + sector count. */
function cert_verify_summary(array $drives, string $mode): string {
    $mode = strtolower(trim($mode));
    if ($mode === '' || $mode === 'none') return 'Not run';
    $label = $mode === 'full' ? 'Full read-back' : 'Sampled read-back';
    $sectors = 0; $failed = false; $unreadable = false; $passed = 0;
    foreach ($drives as $d) {
        $st = strtoupper(trim((string)($d['status'] ?? '')));
        if ($st !== 'COMPLETED') continue;
        $sectors += (int)($d['verify_sectors'] ?? 0);
        $res = strtoupper(trim((string)($d['verify_result'] ?? '')));
        if ($res === 'FAILED') { $failed = true; }
        elseif ($res === 'UNREADABLE') { $unreadable = true; }
        elseif ($res === 'PASSED') { $passed++; }
    }
    if ($failed) return $label . ' — FAILED';
    if ($passed > 0) return $label . ' — passed (' . $sectors . ' sectors)';
    if ($unreadable) return $label . ' — unreadable';
    return $label;
}

/** Short per-drive HPA/DCO label for the Annex A table. */
function cert_hpa_short(string $hpaResult, string $dcoResult): string {
    $hr = strtolower(trim($hpaResult));
    $dr = strtolower(trim($dcoResult));
    if ($hr === 'failed' || $dr === 'failed') return 'FAILED';
    if ($hr === 'removed' || $dr === 'removed') return 'Removed';
    if ($hr === 'firmware-erased' || $dr === 'firmware-erased') return 'Firmware-erased';
    return '-';
}

/** Page-1 "HPA/DCO" summary line — honest: what happened to the hidden areas. */
function cert_hpa_summary(array $drives): string {
    $removed = 0; $fw = 0; $failed = 0;
    foreach ($drives as $d) {
        if (strtoupper(trim((string)($d['status'] ?? ''))) !== 'COMPLETED') continue;
        $hr = strtolower(trim((string)($d['hpa_result'] ?? '')));
        $dr = strtolower(trim((string)($d['dco_result'] ?? '')));
        if ($hr === 'removed' || $dr === 'removed') { $removed++; continue; }
        if ($hr === 'firmware-erased' || $dr === 'firmware-erased') { $fw++; continue; }
        if ($hr === 'failed' || $dr === 'failed') { $failed++; }
    }
    if ($failed > 0) return 'Removal failed — hidden data may remain';
    if ($removed > 0) return 'Hidden areas removed before erasure';
    if ($fw > 0)    return 'Hidden areas erased by firmware';
    return '';
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
        $c = strcmp((string)($a['ts'] ?? ''), (string)($b['ts'] ?? ''));
        return $c !== 0 ? $c : strcmp((string)($a['device'] ?? ''), (string)($b['device'] ?? ''));
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
        $methodsUsed[method_label((string)($d['method'] ?? ''), (string)($d['cls'] ?? ''), (string)($d['status'] ?? ''))] = true;
    }

    $devices = count($drives);
    $methods = count($methodsUsed);
    $runs = count($g['reports']);

    $range = 'N/A';
    if (($g['first'] ?? null) !== null) {
        if ($g['first'] === $g['last']) {
            $range = fmt_ts($g['first']);
        } elseif (substr((string)$g['first'], 0, 10) === substr((string)$g['last'], 0, 10)) {
            $range = fmt_ts($g['first']) . ' to ' . fmt_ts($g['last']);
        } else {
            $range = fmt_date($g['first']) . ' to ' . fmt_date($g['last']);
        }
    }

    if (($g['shaState'] ?? 'unverified') === 'verified') { $shaTxt = 'SHA-256 VERIFIED'; $shaColor = [5, 150, 105]; }
    elseif (($g['shaState'] ?? '') === 'mismatch')       { $shaTxt = 'SHA-256 MISMATCH';  $shaColor = [220, 38, 38]; }
    else                                                 { $shaTxt = 'SHA-256 RECORDED';  $shaColor = [71, 85, 105]; }

    if (!$canSign)                                      { $sigTxt = 'FREE TIER — NO VERIFICATION'; $sigColor = [100, 116, 139]; }
    elseif (($g['sigState'] ?? '') === 'attributed')    { $sigTxt = 'REPORT SIGNATURE VALID';      $sigColor = [5, 150, 105]; }
    elseif (($g['sigState'] ?? '') === 'valid')         { $sigTxt = 'SIGNATURE UNATTRIBUTED';      $sigColor = [217, 119, 6]; }
    elseif (($g['sigState'] ?? '') === 'invalid')       { $sigTxt = 'REPORT SIGNATURE INVALID';    $sigColor = [220, 38, 38]; }
    else                                                { $sigTxt = 'REPORT NOT SIGNED';           $sigColor = [71, 85, 105]; }

    $W = 297.0;
    $H = 210.0;
    $RETENTION = '6 years';

    // Certifying party — the customer's own organisation/person, not tScrub.
    $ISSUER = $issuer !== [] ? $issuer : [
        'name'  => 'The certifying organisation',
        'reg'   => '',
        'addr'  => '',
        'phone' => '',
    ];

    // Machine-level fields. Erasure CSVs duplicate the machine profile on every
    // drive row, so the first drive is a safe fallback when the group-level
    // fields were dropped by a multi-payload merge.
    $first = $drives[0] ?? [];
    $m = function (string $key) use ($g, $first): string {
        $v = trim((string)($g[$key] ?? ''));
        if ($v !== '') return $v;
        return trim((string)($first[$key] ?? ''));
    };

    $toolVersion = $m('tool_version');
    $operator    = $m('operator');
    $validator   = $m('validator');
    $mediaSource = $m('media_source');
    $mediaDest   = $m('media_destination');

    $totalSecs = 0;
    foreach ($drives as $d) {
        $v = trim((string)($d['duration_secs'] ?? ''));
        if (preg_match('/^\d+$/', $v)) $totalSecs += (int)$v;
    }
    $totalDuration = $totalSecs > 0 ? cert_duration($totalSecs) : '';

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

    // ---- Page 1: summary ----
    $pdf->AddPage();
    $pdf->SetFillColor(250, 249, 246);
    $pdf->Rect(0, 0, $W, $H, 'F');
    cert_logo_mark($pdf, 14, 10);

    $pdf->SetFont('helvetica', 'B', 26);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(10, 16);
    $pdf->Cell($W - 20, 12, 'CERTIFICATE OF DESTRUCTION', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(10, 32);
    $pdf->Cell($W - 20, 5, 'Chain of Custody ID ' . $cocid . '  ·  ' . $devices . ' device(s)  ·  issued ' . gmdate('Y-m-d H:i') . ' UTC', 0, 1, 'C');

    // Outcome banner.
    if ($nonCompleted === 0) {
        $bannerTxt = 'ALL DEVICES SANITISED';
        $bannerColor = [5, 150, 105];
    } else {
        $bannerTxt = $nonCompleted . ' DEVICE(S) NOT SANITISED — SEE ANNEX A';
        $bannerColor = [217, 119, 6];
    }
    $pdf->SetFillColor($bannerColor[0], $bannerColor[1], $bannerColor[2]);
    $pdf->RoundedRect(30, 41, $W - 60, 7.5, 1.5, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(30, 41);
    $pdf->Cell($W - 60, 7.5, $bannerTxt, 0, 0, 'C');

    // Meta boxes — certification date / certificate ID / chain of custody ID.
    $metaX = [30, 112.5, 195];
    $metaW = 72.0;
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(11, 18, 32);
    foreach (['CERTIFICATION DATE', 'CERTIFICATE ID', 'CHAIN OF CUSTODY ID'] as $k => $lbl) {
        $pdf->SetXY($metaX[$k], 54);
        $pdf->Cell($metaW, 4.5, $lbl, 0, 0, 'C');
    }
    $pdf->SetFont('helvetica', '', 10);
    $pdf->setCellPaddings(1.6, 0, 1.6, 0);
    foreach ([$range, $certId, $cocid] as $k => $val) {
        $pdf->SetXY($metaX[$k], 59);
        $pdf->Cell($metaW, 8, cert_fit($pdf, $val, $metaW - 3.2, '', 10), 1, 0, 'C');
    }
    $pdf->setCellPaddings(0, 0, 0, 0);

    // Section primitives.
    $sectionHeader = function (float $sx, float $sy, float $sw, string $title) use ($pdf): void {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($sx, $sy);
        $pdf->Cell($sw, 5, $title, 0, 0, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($sx, $sy + 5.0, $sx + $sw, $sy + 5.0);
        $pdf->SetDrawColor(0, 0, 0);
    };
    $row = function (float $sx, float $sy, float $labelW, float $valueW, string $label, string $value, array $color = [11, 18, 32]) use ($pdf): void {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($sx, $sy);
        $pdf->Cell($labelW, 3.6, $label, 0, 0, 'L');
        $pdf->SetXY($sx + $labelW, $sy);
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($valueW, 3.6, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->Cell($valueW, 3.6, cert_fit($pdf, $value, $valueW - 0.4, 'B', 7.5), 0, 0, 'L');
        }
    };
    $machinesUsed = 0;
    $seenMachines = [];
    foreach ($drives as $d) {
        $msys = trim((string)($d['system'] ?? ''));
        $msn  = trim((string)($d['sysserial'] ?? ''));
        if ($msys === '' && $msn === '') continue;
        $key = strtolower($msys . '|' . $msn);
        if (!isset($seenMachines[$key])) { $seenMachines[$key] = true; $machinesUsed++; }
    }

    $hpaSummary = cert_hpa_summary($drives);
    $erasureL = [
        ['Tool / version', $toolVersion !== '' ? 'tScrub ' . $toolVersion : 'tScrub'],
        ['Standard',       'NIST SP 800-88 Rev 1'],
        ['Level reached',  cert_level($drives)],
        ['Verification',   cert_verify_summary($drives, (string)($g['verify'] ?? ''))],
        ['Start',          ($g['first'] ?? null) !== null ? fmt_ts($g['first']) : ''],
        ['End',            ($g['last'] ?? null) !== null ? fmt_ts($g['last']) : ''],
    ];
    if ($hpaSummary !== '') {
        array_splice($erasureL, 4, 0, [['HPA/DCO', $hpaSummary]]);
    }
    $erasureR = [
        ['Total duration',    $totalDuration],
        ['Devices',           (string)$devices],
        ['Machines used',     $machinesUsed > 0 ? (string)$machinesUsed : ''],
        ['Methods used',      (string)$methods],
        ['Runs consolidated', (string)$runs],
    ];

    $personnelL = [
        ['Operator',  $operator],
        ['Validator', $validator],
    ];
    $personnelR = [
        ['Media source',      $mediaSource],
        ['Media destination', $mediaDest],
    ];

    // Full-width sections, each laid out in two internal columns so the
    // summary reads evenly instead of one long column beside a short one.
    $gridX    = [30.0, 151.0];
    $gridW    = 116.0;
    $labelW   = 30.0;
    $sectionY = 70.0;

    $sectionHeader(30, $sectionY, 237, 'ERASURE INFORMATION');
    $yy = $sectionY + 5.8;
    $maxRows = max(count($erasureL), count($erasureR));
    for ($r = 0; $r < $maxRows; $r++) {
        if (isset($erasureL[$r])) $row($gridX[0], $yy, $labelW, $gridW - $labelW, $erasureL[$r][0], $erasureL[$r][1]);
        if (isset($erasureR[$r])) $row($gridX[1], $yy, $labelW, $gridW - $labelW, $erasureR[$r][0], $erasureR[$r][1]);
        $yy += 3.6;
    }

    $yy += 2.2;
    $sectionHeader(30, $yy, 237, 'PERSONNEL & AUTHORITY');
    $yy += 5.8;
    $maxRows = max(count($personnelL), count($personnelR));
    for ($r = 0; $r < $maxRows; $r++) {
        if (isset($personnelL[$r])) $row($gridX[0], $yy, $labelW, $gridW - $labelW, $personnelL[$r][0], $personnelL[$r][1]);
        if (isset($personnelR[$r])) $row($gridX[1], $yy, $labelW, $gridW - $labelW, $personnelR[$r][0], $personnelR[$r][1]);
        $yy += 3.6;
    }

    // Attestation.
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 118);
    $pdf->Cell(237, 4.5, 'ATTESTATION', 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(51, 65, 85);
    $attestation = 'This is to certify that the data storage devices listed in Annex A were sanitised, or removed and destroyed, '
        . 'in accordance with the methods, standards and levels recorded herein, under controlled chain-of-custody procedures from '
        . 'receipt to final verification. ' . ($toolVersion !== '' ? 'tScrub ' . $toolVersion : 'tScrub') . ' was used as the sanitisation tool.';
    $pdf->SetXY(30, 123);
    $pdf->MultiCell(237, 3.8, $attestation, 0, 'L');

    // Two signature blocks — operator + validator.
    $sigBlock = function (float $sx, float $sy, float $sw, string $role, string $name) use ($pdf): void {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($sx, $sy);
        $pdf->Cell($sw, 4.5, $name !== '' ? $name : '________________________', 0, 0, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(148, 163, 184);
        $pdf->Line($sx, $sy + 6.5, $sx + $sw, $sy + 6.5);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($sx, $sy + 7.5);
        $pdf->Cell($sw, 4, $role, 0, 0, 'L');
    };
    $sigBlock(30, 140, 117, 'Performed by (operator) — name / date', $operator);
    $sigBlock(150, 140, 117, 'Validated by (validator) — name / date', $validator);

    // Audit badges.
    $pdf->setCellPaddings(1.6, 0, 1.6, 0);
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor($shaColor[0], $shaColor[1], $shaColor[2]);
    $pdf->SetXY(30, 156);
    $pdf->Cell(101, 7.5, $shaTxt, 1, 0, 'C');
    $pdf->SetTextColor($sigColor[0], $sigColor[1], $sigColor[2]);
    $pdf->SetXY(136, 156);
    $pdf->Cell(101, 7.5, $sigTxt, 1, 0, 'C');
    $pdf->setCellPaddings(0, 0, 0, 0);

    // Certifier identity block (bottom-left).
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(30, 166);
    $pdf->Cell(190, 4.5, 'Certified by ' . $ISSUER['name'], 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(71, 85, 105);
    $y = 171;
    if (($ISSUER['reg'] ?? '') !== '') { $pdf->SetXY(30, $y); $pdf->Cell(190, 4, $ISSUER['reg'], 0, 1, 'L'); $y += 4; }
    if (($ISSUER['addr'] ?? '') !== '') { $pdf->SetXY(30, $y); $pdf->Cell(190, 4, $ISSUER['addr'], 0, 1, 'L'); $y += 4; }
    if (($ISSUER['phone'] ?? '') !== '') { $pdf->SetXY(30, $y); $pdf->Cell(190, 4, 'Tel: ' . $ISSUER['phone'], 0, 1, 'L'); $y += 4; }

    // Verification QR code (bottom-right) — on every certificate, paid or free.
    $verifyUrl = 'https://tscrub.com/verify?cert=' . $certId;
    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', 244, 157, 30, 30, ['border' => 0, 'padding' => 0], 'N');
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(244, 189);
    $pdf->Cell(30, 4, 'Verify online', 0, 0, 'C');

    // Scope + retention line.
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $scope = 'Item-level evidence is recorded in Annex A and traceable by Certificate ID ' . $certId
        . ' and Chain of Custody ID ' . $cocid . '. Records are retained for ' . $RETENTION . '.';
    $pdf->SetXY(30, 185);
    $pdf->MultiCell(195, 3.4, $scope, 0, 'L');

    // Footer.
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(30, 194);
    $pdf->Cell(237, 5, 'Prepared with tScrub — verifiable disk sanitisation  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    // Drives from reports that predate the ToolVersion column carry no
    // per-drive version. Fall back to the chain-of-custody tool version so the
    // annex stays consistent with the summary's "Tool / version" line instead
    // of showing a bare "tScrub".
    foreach ($drives as $i => $d) {
        if (trim((string)($d['tool_version'] ?? '')) === '' && $toolVersion !== '') {
            $drives[$i]['tool_version'] = $toolVersion;
        }
    }

    // ---- Annex A ----
    cert_render_annex($pdf, 10.0, $W, $H, $certId, $cocid, $drives, $g['reports']);

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

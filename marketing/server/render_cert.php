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
require_once __DIR__ . '/cert_terms.php';

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
    if ($s === 'STALLED')   return 'Not sanitised — sanitize stalled mid-operation (power-cycle required)';
    if ($s === 'UNKNOWN')   return 'Not sanitised — outcome unknown';
    if ($s === 'DRY-RUN')   return 'Dry run — no sanitisation performed';

    $m = strtoupper(trim((string)$method));
    $c = strtoupper(trim((string)$cls));

    // Named software-overwrite standards (v1.11.70+). The METHOD field carries
    // the standard's own label ("DoD Short", "Zero fill", …), which matches
    // none of the aliases below and none of the strpos rules, so these rows used
    // to fall through to the class wording "Sanitisation (Clear) - Verified" —
    // the same sentence for every standard, with the name printed nowhere in
    // the method summary. Older reports carry the literal "nwipe Quick", which
    // the NWIPE rule further down still handles.
    $overwrite = [
        'ZERO FILL'        => 'Zero fill',
        'PRNG STREAM'      => 'PRNG stream',
        'DOD 5220.22-M'    => 'DoD 5220.22-M',
        'DOD SHORT'        => 'DoD Short',
        'GUTMANN'          => 'Gutmann',
        'HMG IS5 ENHANCED' => 'HMG IS5 Enhanced',
    ];
    if (isset($overwrite[$m])) {
        return 'Software Overwrite — ' . $overwrite[$m] . ' (Clear) - Verified';
    }

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
        if (strpos($m, 'STALLED') !== false) return 'Not sanitised — sanitize stalled mid-operation (power-cycle required)';
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
    if ($u === 'FROZEN' || $u === 'BLOCKED' || $u === 'FAILED' || $u === 'UNKNOWN' || $u === 'STALLED') return [220, 38, 38];
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

/**
 * Label for the annex "Verification" cell: what KIND of read-back evidence the
 * appliance recorded for this drive.
 *
 *   sentinel          - self-planted sentinel sectors were re-read and were gone
 *   overwrite-sample  - N% of the address space was read back and found zero
 *                       (only possible after a software overwrite, where the
 *                       whole drive once held a known pattern)
 *   ce-exempt         - a crypto erase destroyed the media key, so a content
 *                       read-back cannot add evidence either way
 *
 * 'none'/blank returns '', which lets the whole column disappear when no drive
 * carries a technique (e.g. a firmware-erase-only certificate).
 */
function cert_verify_label(string $technique, string $pct = ''): string {
    $t = strtolower(trim($technique));
    if ($t === '' || $t === 'none' || $t === 'na' || $t === 'n/a') return '';
    if ($t === 'overwrite-sample') {
        $p = trim($pct);
        if ($p !== '' && preg_match('/^[0-9]{1,3}$/', $p)) return 'Sampled ' . $p . '%';
        return 'Sampled';
    }
    if ($t === 'sentinel') return 'Sentinel';
    if ($t === 'ce-exempt') return 'CE exempt';
    return '';
}

/** Compact (single-line) sanitisation method label for the fleet table. */
function cert_method_short(string $method, string $cls, string $status): string {
    $s = strtoupper(trim($status));
    if ($s === 'DESTROYED') return 'Physical destruction';
    if ($s === 'FAILED')    return 'Erasure failed';
    if ($s === 'BLOCKED')   return 'Blocked (Block SID)';
    if ($s === 'FROZEN')    return 'Frozen';
    if ($s === 'STALLED')   return 'Stalled (power-cycle)';
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
function cert_render_table(TCPDF $pdf, float $x, float $W, float $H, string $certId, array $drives, float $startY = 28.0): void {
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
        'type'     => ['label' => 'Type',         'w' => 11, 'align' => 'C'],
        'size'     => ['label' => 'Drive size',   'w' => 18, 'align' => 'C'],
        'bus'      => ['label' => 'Bus',          'w' => 12, 'align' => 'C'],
        'cls'      => ['label' => 'Class',        'w' => 24, 'align' => 'C'],
        'method'   => ['label' => 'Method',       'w' => 42, 'align' => 'L'],
        'passes'   => ['label' => 'Passes',       'w' => 14, 'align' => 'C'],
        'eraser'   => ['label' => 'Eraser',       'w' => 26, 'align' => 'C'],
        // Kind of read-back evidence (sentinel / sampled overwrite coverage /
        // crypto-erase exemption). Hidden entirely when no drive carries one,
        // so a firmware-only certificate is unchanged.
        'verify'   => ['label' => 'Verification', 'w' => 22, 'align' => 'C'],
        'tool'     => ['label' => 'Tool',         'w' => 24, 'align' => 'C'],
        'system'   => ['label' => 'Machine',      'w' => 32, 'align' => 'L'],
        'sysserial'=> ['label' => 'Machine SN',   'w' => 23, 'align' => 'C'],
        'status'   => ['label' => 'Status',       'w' => 15, 'align' => 'C'],
        'format'   => ['label' => 'Formatted',    'w' => 18, 'align' => 'C'],
        'psid'     => ['label' => 'PSID',         'w' => 14, 'align' => 'C'],
        'ppi'      => ['label' => 'PPI',          'w' => 14, 'align' => 'C'],
        'ppi_cleared' => ['label' => 'PPI Cleared', 'w' => 16, 'align' => 'C'],
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
            case 'passes': return trim((string)($d['passes'] ?? ''));
            case 'eraser': return (string)($d['erase_engine'] ?? '');
            case 'verify': return cert_verify_label(
                (string)($d['verify_technique'] ?? ''),
                (string)($d['verify_coverage_pct'] ?? ''));
            case 'tool': {
                $tv = trim((string)($d['tool_version'] ?? ''));
                return $tv !== '' ? 'tScrub ' . $tv : 'tScrub';
            }
            case 'system':    return (string)($d['system'] ?? '');
            case 'sysserial': return (string)($d['sysserial'] ?? '');
            case 'format': {
                $fr = strtolower(trim((string)($d['format_result'] ?? '')));
                $fs = strtolower(trim((string)($d['format_fs'] ?? '')));
                if ($fr === 'ok' && $fs !== '') return strtoupper($fs);
                return '';
            }
            case 'psid': {
                $p = strtolower(trim((string)($d['psid_revert'] ?? '')));
                if ($p === 'yes') return 'Yes';
                if ($p === 'failed') return 'Failed';
                if ($p === 'na') return 'N/A';
                return '';
            }
            case 'ppi': {
                $p = strtolower(trim((string)($d['ppi_requested'] ?? '')));
                return $p === 'yes' ? 'Yes' : '';
            }
            case 'ppi_cleared': {
                $p = strtolower(trim((string)($d['ppi_cleared'] ?? '')));
                return $p === 'yes' ? 'Yes' : '';
            }
            case 'status': return (string)($d['status'] ?? '');
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
            case 'passes': return trim((string)($d['passes'] ?? '')) === '';
            case 'eraser': return trim((string)($d['erase_engine'] ?? '')) === '';
            case 'verify': return cert_verify_label(
                (string)($d['verify_technique'] ?? ''),
                (string)($d['verify_coverage_pct'] ?? '')) === '';
            case 'tool':   return trim((string)($d['tool_version'] ?? '')) === '';
            case 'system':    return trim((string)($d['system'] ?? '')) === '';
            case 'sysserial': return trim((string)($d['sysserial'] ?? '')) === '';
            case 'format': {
                $fr = strtolower(trim((string)($d['format_result'] ?? '')));
                return $fr !== 'ok';
            }
            case 'psid': {
                $p = strtolower(trim((string)($d['psid_revert'] ?? '')));
                return $p !== 'yes' && $p !== 'failed' && $p !== 'na';
            }
            case 'ppi': {
                $p = strtolower(trim((string)($d['ppi_requested'] ?? '')));
                return $p !== 'yes';
            }
            case 'ppi_cleared': {
                $p = strtolower(trim((string)($d['ppi_cleared'] ?? '')));
                return $p !== 'yes';
            }
            case 'status': return trim((string)($d['status'] ?? '')) === '';
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
    $minOneLine = ['status' => 'COMPLETED', 'ts' => '2026-09-21 10:30', 'tool' => 'tScrub v1.11.15', 'cls' => 'SANITISATION'];
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

    $drawHeader($startY);

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
    $pdf->Cell($W - 2 * $x, 4.5, 'Item-level record of ' . count($drives) . ' media item(s) processed under Chain of Custody ID ' . $cocid . '.', 0, 1, 'C');

    if ($drives === []) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, 60);
        // Not 'No devices recorded': a job can consist entirely of attested destruction,
        // in which case the next page is the substantive record.
        $pdf->Cell($W - 2 * $x, 8, 'No appliance-verified media in this job.', 0, 1, 'C');
        return;
    }

    // "How the data was destroyed" — one line per method used, printed above the
    // per-drive detail so the certificate states its technique, not just its
    // standard. Failures are deliberately excluded (the page-1 banner and the
    // Annex status column carry them); see
    // research/cert-destruction-evidence/README.md.
    $breakdown = cert_method_breakdown($drives);
    $y = 30.0;
    if ($breakdown !== []) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, $y);
        $pdf->Cell($W - 2 * $x, 5, 'DESTRUCTION METHOD SUMMARY', 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $y + 5.0, $x + ($W - 2 * $x), $y + 5.0);
        $pdf->SetDrawColor(0, 0, 0);
        $y += 6.4;

        $pdf->SetFont('helvetica', '', 7.5);
        foreach ($breakdown as $b) {
            $label = method_label((string)$b['method'], (string)$b['cls'], (string)$b['status']);
            $line  = '• ' . $label
                . ' — ' . ($b['level'] !== '' ? $b['level'] : 'Not sanitised')
                . ' — ' . $b['technique']
                . ' — ' . $b['n'] . ' media item(s)';
            $pdf->SetTextColor(51, 65, 85);
            $pdf->SetX($x);
            $pdf->MultiCell($W - 2 * $x, 3.8, $line, 0, 'L');
            $y = $pdf->GetY() + 0.6;
        }

        $disposition = cert_media_disposition($drives);
        if ($disposition !== '') {
            $pdf->SetFont('helvetica', 'I', 7.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetX($x);
            $pdf->MultiCell($W - 2 * $x, 3.8, 'Media disposition: ' . $disposition . '.', 0, 'L');
            $pdf->SetFont('helvetica', '', 7.5);
            $y = $pdf->GetY() + 1.2;
        }
        $y += 2.0;
    }

    // Dense one-row-per-drive table, then the report manifest (own page).
    cert_render_table($pdf, $x, $W, $H, $certId, $drives, max(28.0, $y));
    cert_render_manifest($pdf, $x, $W, $H, $certId, $reports);
}

/**
 * Render one certificate (one Chain of Custody ID) into its own PDF.
 * Returns the PDF bytes, its SHA-256, the computed counts, and the sorted
 * drive rows (so the caller can persist them in the same order).
 */
/**
 * Annex A appendix: the manually recorded (attested) destruction.
 *
 * Everything drawn here is an operator attestation, not instrument-verified
 * evidence, so every block carries md_attested_qualifier() and nothing on this
 * section may imply a readback or a NIST Clear/Purge level. The section manages its
 * own Y coordinate so it can grow without disturbing page 1, whose layout is
 * fixed-coordinate (see research/manual-destruction/README.md §10).
 *
 * Returns the Y position after the section.
 *
 * NOT YET WIRED: nothing calls this until step (2) of the plan, so it cannot affect
 * certificate issuance.
 */
function cert_render_attested_section($pdf, array $attested, float $startY, string $certId = '',
                                       float $W = 297.0, float $H = 210.0): float
{
    require_once __DIR__ . '/manual_destruction.php';

    $devices = $attested['devices'] ?? [];
    $loose   = $attested['media'] ?? [];
    if (!$devices && !$loose) {
        return $startY;
    }

    $counts = md_attested_counts($attested);
    $split  = md_attested_split($attested);
    $retained = (int)$split['retained']['devices'] + (int)$split['retained']['media'];
    $x = 30.0;
    $w = 237.0;
    $y = $startY;

    // NOTE the page is A4 LANDSCAPE: 297 x 210. This check used to compare against a
    // hardcoded 268 — a figure that only makes sense on a PORTRAIT page (297 tall) — so
    // on the real document it sat 58mm BELOW the paper. The explicit AddPage() could not
    // fire before the content had already run off the bottom edge, and TCPDF then reports
    // a clamped Y, so the guard never fired at all: every attested record after the first
    // page's worth was drawn past the foot and clipped. A 24-device job showed its first
    // few entries and then simply stopped. The limit comes from the page geometry the
    // caller passes in — NOT from getPageHeight(), which warns and returns nothing in
    // this TCPDF build (that is why the renderer carries 297/210 as literals).
    $limit = $H - 12.0;

    $pageStart = function () use ($pdf, $W, $H, $x, $w, $certId): void {
        $pdf->SetFillColor(250, 249, 246);
        $pdf->Rect(0, 0, $W, $H, 'F');
        if ($certId === '') { return; }
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($x, 16);
        $pdf->Cell($w, 4, 'Certificate ID: ' . $certId . '  |  Page '
            . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');
    };

    $pageStart();

    // Measure the block before writing it, rather than guessing from the media count. The
    // old estimate ($rows * 7.0) ignored the device and the method lines altogether, so a
    // block could overflow even with the limit correct. The fonts are the ones the
    // renderers use, and the slack covers the gaps they add between lines.
    $blockHeight = function (array $dev) use ($pdf, $w): float {
        $h = 4.0;
        $pdf->SetFont('helvetica', 'B', 8.5);
        $h += $pdf->getStringHeight($w, md_device_line($dev));
        $pdf->SetFont('helvetica', '', 8);
        $h += $pdf->getStringHeight($w - 4.0, md_attested_line($dev));
        foreach (($dev['media'] ?? []) as $m) {
            $h += $pdf->getStringHeight($w - 8.0, md_attested_media_line($m, $dev)) + 0.2;
        }
        return $h;
    };

    // Heading is neutral only when a retained item is present. A factory reset is not a
    // physical destruction, and the heading must not file it under one.
    $heading = $retained > 0
        ? 'OPERATOR ATTESTATION — NOT INSTRUMENT-VERIFIED'
        : 'PHYSICALLY DESTROYED — OPERATOR ATTESTATION';
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, 4.5, $heading, 0, 1, 'L');
    $y += 5.0;

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->SetXY($x, $y);
    $pdf->MultiCell($w, 3.8, md_attested_summary($split), 0, 'L');
    $y = $pdf->GetY() + 1.6;

    foreach ($devices as $dev) {
        // Keep a whole block together: start a new page rather than split a unit from its
        // media or run the block off the bottom.
        if ($y + $blockHeight($dev) + 2.0 > $limit) {
            $pdf->AddPage();
            $pageStart();
            $y = $startY;
        }
        $y = cert_render_attested_device($pdf, $dev, $y, $x, $w, true, $H);
    }

    foreach ($loose as $m) {
        if ($y + 16.0 > $limit) {
            $pdf->AddPage();
            $pageStart();
            $y = $startY;
        }
        $y = cert_render_attested_device($pdf, $m, $y, $x, $w, false, $H);
    }

    // The footnote is part of the claim, so it may not be left off the bottom either.
    if ($y + 12.0 > $limit) {
        $pdf->AddPage();
        $pageStart();
        $y = $startY;
    }

    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($x, $y + 1.0);
    $pdf->MultiCell($w, 3.6, md_attested_footnote($retained > 0), 0, 'L');

    return $pdf->GetY() + 2.0;
}

/**
 * One attested item: the unit, what was done to it, who attested it, and the media
 * that were destroyed with it ($withMedia false for a standalone destroyed drive,
 * which has no host and must not be given one).
 */
function cert_render_attested_device($pdf, array $dev, float $y, float $x, float $w, bool $withMedia = true,
                                     float $H = 210.0): float
{
    require_once __DIR__ . '/manual_destruction.php';

    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, $y);
    $pdf->MultiCell($w, 3.8, md_device_line($dev), 0, 'L');
    $y = $pdf->GetY() + 0.4;

    // "Destroyed — Shredded (Acme Shredding, 2026-10-13)" or, for a factory reset,
    // "Factory reset — device retained (2026-10-13)", followed by who attested it. The
    // verb comes from the method, so a retained device is never called destroyed. The
    // method label is a noun ("Shredded"), so it cannot follow "Destroyed by" without
    // reading as a mistake; a dash also matches how the dashboard words the same row.
    $line = md_attested_line($dev);
    $by = trim((string)($dev['attested_by'] ?? ''));
    if ($by !== '') { $line .= ' · attested by ' . $by; }
    $wit = trim((string)($dev['destroy_witness'] ?? ''));
    if ($wit !== '') { $line .= ' · witnessed by ' . $wit; }
    $ref = trim((string)($dev['evidence_ref'] ?? ''));
    if ($ref !== '') { $line .= ' · evidence ' . $ref; }

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->SetXY($x + 4.0, $y);
    $pdf->MultiCell($w - 4.0, 3.6, $line, 0, 'L');
    $y = $pdf->GetY();

    foreach (($dev['media'] ?? []) as $m) {
        if (!$withMedia) { break; }
        // A single unit can carry more media than a page holds (a 24-bay chassis), so the
        // block's own rows have to break as well — the caller only guarantees that the
        // block STARTS on a page it can mostly fit on.
        if ($y > $H - 20.0) {
            $pdf->AddPage();
            $y = 28.0;
        }
        $y = cert_render_attested_media_row($pdf, $m, $y, $x + 4.0, $w - 4.0, $dev);
    }

    return $y + 2.4;
}

/**
 * One attested medium beneath its host unit: what it was, its identity, and — when it
 * differs from the unit it came out of — its own outcome. The wording lives in
 * md_attested_media_line() so the certificate and the dashboard cannot describe the same
 * medium differently.
 */
function cert_render_attested_media_row($pdf, array $m, float $y, float $x, float $w, array $dev = []): float
{
    require_once __DIR__ . '/manual_destruction.php';

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->SetXY($x + 4.0, $y);
    $pdf->MultiCell($w - 4.0, 3.6, md_attested_media_line($m, $dev), 0, 'L');

    return $pdf->GetY() + 0.2;
}

/**
 * The band the page-1 attestation paragraph is drawn in.
 *
 * It is fixed, because the signature blocks, badges and issuer block below it are
 * placed at absolute Y positions. Its height is therefore a hard budget for the
 * wording: page 1 is landscape A4, the heading sits at y=118 and the operator
 * signature block at y=140, so the paragraph gets the 17mm between them — four
 * lines of 3.8mm.
 *
 * The paragraph is not a constant: a mixed job appends a caveat and a job with
 * factory-reset items appends a retention clause, so the text grows with the shape
 * of the job. Anything that adds words here has to be measured against this budget
 * rather than assumed to fit — which is exactly how the mixed wording came to print
 * through the operator's name.
 */
function cert_attestation_band(): array
{
    return [
        'x'    => 30.0,
        'y'    => 123.0,
        'w'    => 237.0,
        'h'    => 140.0 - 123.0,   // signature block Y minus the paragraph's start Y
        'line' => 3.8,
        'max'  => 8.5,             // the size it is drawn at when the wording fits
        'min'  => 7.4,
    ];
}

/**
 * The size to draw the attestation paragraph at: the largest step at which it fits the
 * band, so a long job shape shrinks the type instead of printing through the signature
 * block. Returns the smallest step when even that will not fit, in which case the
 * wording is over budget and needs shortening — not a smaller font.
 *
 * Pure (needs TCPDF only for the measurement): the caller passes the paragraph in.
 */
function cert_attestation_size($pdf, string $text, float $w, float $availH, float $max = 8.5, float $min = 7.4): float
{
    $size = $min;
    foreach ([$max, 8.2, 7.8, $min] as $step) {
        if ($step > $max) { continue; }
        $pdf->SetFont('helvetica', '', $step);
        $size = $step;
        if ($pdf->getStringHeight($w, $text) <= $availH) { break; }
    }
    return $size;
}

/**
 * The page-1 attestation sentence, for the shape of job being certified.
 *
 * Three genuinely different documents share this paragraph:
 *   1. verified only — everything was sanitised by the appliance;
 *   2. mixed         — verified items, plus items the operator recorded as destroyed;
 *   3. attested only — nothing ran through the appliance at all.
 *
 * 'tScrub was used as the sanitisation tool' may only be claimed where it is true, so
 * case 3 never contains it, and a retained (factory-reset) item adds what it needs
 * rather than quietly widening the destroy claim to cover a device handed back.
 *
 * Every clause here is load-bearing, and the band above is only four lines, so the
 * wording is deliberately as short as it can be while still saying: what was destroyed,
 * by what means, under what custody, that the tool did the sanitising, and that the
 * attested items were not sanitised by it and are not instrument-verified.
 */
function cert_attestation_text(string $toolName, int $devices, int $attItems, int $attRetained): string
{
    $sanitisedClaim = 'This is to certify that the data on the storage devices listed in Annex A was destroyed — by in-place sanitisation '
        . '(Clear / Purge), or by physical destruction where recorded — under controlled chain-of-custody from receipt to final '
        . 'verification, in accordance with the methods, techniques, standards and levels set out herein. '
        . $toolName . ' was used as the sanitisation tool.';

    if ($devices > 0 && $attItems === 0) {
        return $sanitisedClaim;
    }

    if ($devices > 0) {
        // 'recorded there', not 'recorded by the operator named above': the name in the
        // signature block is the account that generated this PDF, and a job like COCID
        // 33380 was attested by two different people (14 items by one, 10 by another).
        // Attributing the recording to the page-1 name would be a false statement about a
        // named individual. The section names the person against each item.
        return $sanitisedClaim . ' The further items in the operator attestation section were not sanitised by this tool: they are '
            . 'recorded there as physically destroyed'
            . ($attRetained > 0
                ? ', or — where a factory reset is recorded — as reset to factory settings and retained'
                : '')
            . '. They are ' . md_attested_qualifier() . '.';
    }

    // Pure attestation. Saying 'tScrub was used as the sanitisation tool' here would be
    // simply untrue — the tool never ran — so the certificate claims only what the
    // operator has actually attested to.
    $outcome = $attRetained > 0
        ? 'taken out of service as recorded in that section — physically destroyed, or reset to factory settings and retained —'
        : 'physically destroyed, as recorded in that section,';
    $claimKind = $attRetained > 0 ? 'operator-attested outcomes only' : 'destruction by operator attestation only';
    $text = 'This is to certify that the items listed in the operator attestation section were ' . $outcome
        . ' under controlled chain-of-custody from receipt to final verification. No data in this job was sanitised in place by '
        . $toolName . ', and no appliance-verified sanitisation is claimed: this certificate records '
        . $claimKind . ', and the attested items are ' . md_attested_qualifier() . '.';
    if ($attRetained > 0) {
        $text .= ' A factory reset destroys no hardware, so a reset device remains in circulation and no claim is made about it '
            . 'beyond the declaration above.';
    }
    return $text;
}

function render_certificate_pdf(array $g, string $certId, bool $canSign, array $issuer = [], array $destroyed = [], array $attested = []): array {
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
        // RETAINED joins DESTROYED as a settled outcome: a factory reset is not a pending
        // sanitisation, and relabelling one here would put it in the 'not destroyed' warning.
        if ($st === 'COMPLETED' || $st === 'DESTROYED' || $st === 'RETAINED') continue;
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

    // Attested (manually recorded) destruction is a different class of evidence from a
    // verified sanitisation, and the two figures are never summed. Counted here so the
    // claim on page 1 and the annex cannot disagree.
    require_once __DIR__ . '/manual_destruction.php';
    $attCounts  = md_attested_counts($attested);
    $attDevices = (int)$attCounts['devices'];
    $attMedia   = (int)$attCounts['media'];
    $attSplit   = md_attested_split($attested);
    $attItems   = count($attested['devices'] ?? []) + count($attested['media'] ?? []);

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

    // A pure-attestation job has no appliance run, so there are no run timestamps and the
    // certification date would print N/A even though the operator recorded exactly when
    // each item was destroyed. Fall back to those dates rather than certify 'N/A'.
    if ($range === 'N/A' && $attItems > 0) {
        // Only dates in the canonical Y-m-d form are used for the RANGE. An unparseable
        // one (a spreadsheet-typed 13/10/2026, say) is still printed verbatim against its
        // own item in the attested section, but mixing formats into a plain string sort
        // can produce a range whose start falls after its end — worse than no range.
        $destroyDates = [];
        $wantDate = function ($row) use (&$destroyDates): void {
            $d = trim((string)($row['destroy_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { $destroyDates[] = $d; }
        };
        foreach (($attested['devices'] ?? []) as $dv) {
            $wantDate($dv);
            foreach (($dv['media'] ?? []) as $m) { $wantDate($m); }
        }
        foreach (($attested['media'] ?? []) as $m) { $wantDate($m); }

        if ($destroyDates !== []) {
            sort($destroyDates);
            $lo = $destroyDates[0];
            $hi = $destroyDates[count($destroyDates) - 1];
            $range = $lo === $hi ? $lo : $lo . ' to ' . $hi;
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
        // The appliance writes the literal sentinel "N/A" for missing values;
        // treat it as empty so the cert renders an em dash instead of "N/A".
        $norm = function (string $v): string {
            $t = trim($v);
            return ($t === '' || strcasecmp($t, 'N/A') === 0) ? '' : $t;
        };
        $v = $norm((string)($g[$key] ?? ''));
        if ($v !== '') return $v;
        return $norm((string)($first[$key] ?? ''));
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

    // The claim is the sentence a customer reads, so the verified and attested figures
    // are stated separately. Summing them would claim more than the evidence supports.
    // A pure-attestation job has no verified media at all, where '0 media items' on its
    // own would read as though nothing had been destroyed.
    $subParts = ['Chain of Custody ID ' . $cocid];
    if ($devices > 0)  { $subParts[] = $devices . ($devices === 1 ? ' verified media item' : ' verified media items'); }
    if ($attItems > 0) { $subParts[] = $attItems . ($attItems === 1 ? ' item' : ' items') . ' destroyed by attestation'; }
    if ($devices === 0 && $attItems === 0) { $subParts[] = '0 media items'; }
    $subParts[] = 'issued ' . gmdate('Y-m-d H:i') . ' UTC';
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(10, 32);
    $pdf->Cell($W - 20, 5, implode('  ·  ', $subParts), 0, 1, 'C');

    // Outcome banner — states the claim ("data destroyed") plus, implicitly, the
    // how: a run that is part sanitised in place and part physically destroyed is
    // fully destroyed; only FAILED/BLOCKED/FROZEN/UNKNOWN/DRY-RUN amber-flag.
    $otherCount = $nonCompleted - $destroyedCount;
    if ($otherCount > 0) {
        // A failure is the one thing a reader must not miss, so it keeps the banner.
        $bannerTxt = $otherCount . ($otherCount === 1 ? ' MEDIA ITEM' : ' MEDIA ITEMS') . ' NOT DESTROYED — SEE ANNEX A';
        $bannerColor = [217, 119, 6];
    } elseif ($attItems > 0) {
        // Verified and attested are never summed: one is instrument-verified, the other
        // is a statement by the operator. Amber when nothing was instrument-verified, so
        // an attestation is never dressed up as a sanitisation.
        $bannerTxt = md_attested_banner(
            $devices,
            (int)$attSplit['destroyed']['devices'],
            (int)$attSplit['destroyed']['media'],
            (int)$attSplit['retained']['devices'],
            (int)$attSplit['retained']['media']
        );
        $bannerColor = $devices > 0 ? [5, 150, 105] : [217, 119, 6];
    } else {
        $bannerTxt = 'DATA DESTROYED ON ALL ' . $devices . ($devices === 1 ? ' MEDIA ITEM' : ' MEDIA ITEMS');
        $bannerColor = [5, 150, 105];
    }
    $pdf->SetFillColor($bannerColor[0], $bannerColor[1], $bannerColor[2]);
    $pdf->RoundedRect(30, 41, $W - 60, 7.5, 1.5, '1111', 'F');
    // A job can now carry three figures (verified, destroyed, retained) and the longest
    // combination runs past the rect. Shrink the font rather than truncating: a claim is
    // never allowed to end in an ellipsis.
    $bannerFont = 10.0;
    while ($bannerFont > 7.4 && $pdf->getStringWidth($bannerTxt, 'helvetica', 'B', $bannerFont) > ($W - 62)) {
        $bannerFont -= 0.5;
    }
    $pdf->SetFont('helvetica', 'B', $bannerFont);
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

    // "How we destroyed it": the distinct techniques used across the run, most
    // used first so the dominant technique is never the one that gets
    // truncated. Capped at two — the full per-method list is in Annex A.
    $techniqueCounts = [];
    foreach ($drives as $d) {
        $st = strtoupper(trim((string)($d['status'] ?? '')));
        if ($st !== 'COMPLETED' && $st !== 'DESTROYED') continue;
        $t = cert_technique_label((string)($d['method'] ?? ''), (string)($d['cls'] ?? ''), $st);
        $techniqueCounts[$t] = ($techniqueCounts[$t] ?? 0) + 1;
    }
    arsort($techniqueCounts); // most-used first; PHP 8 keeps insertion order on ties
    $techniqueList = array_keys($techniqueCounts);
    $techniqueTxt  = '';
    if ($techniqueList !== []) {
        $techniqueTxt = implode('; ', array_slice($techniqueList, 0, 2));
        if (count($techniqueList) > 2) $techniqueTxt .= ' +' . (count($techniqueList) - 2) . ' more';
    }

    $erasureL = [
        ['Tool / version', $toolVersion !== '' ? 'tScrub ' . $toolVersion : 'tScrub'],
        ['Standard',       'NIST SP 800-88 Rev 1'],
        ['Level reached',  cert_level($drives)],
        ['Start',          ($g['first'] ?? null) !== null ? fmt_ts($g['first']) : ''],
        ['End',            ($g['last'] ?? null) !== null ? fmt_ts($g['last']) : ''],
    ];
    $erasureR = [
        ['Total duration',    $totalDuration],
        ['Technique',         $techniqueTxt],
        ['Media disposition', cert_media_disposition($drives)],
        ['Machines used',     $machinesUsed > 0 ? (string)$machinesUsed : ''],
        ['Runs consolidated', (string)$runs],
    ];

    $personnelL = [
        ['Operator',  $operator],
    ];
    if ($validator !== '') {
        $personnelL[] = ['Validator', $validator];
    }
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

    $pdf->SetTextColor(51, 65, 85);
    $toolName = ($toolVersion !== '' ? 'tScrub ' . $toolVersion : 'tScrub');
    $attRetained = (int)$attSplit['retained']['devices'] + (int)$attSplit['retained']['media'];
    $attestation = cert_attestation_text($toolName, (int)$devices, $attItems, $attRetained);

    // Drawn at the largest size that fits the band. The wording above is short enough to
    // fit at the full size for every job shape; this is the guard that keeps it that way,
    // because the alternative to shrinking is printing over the operator's name.
    $band = cert_attestation_band();
    $pdf->SetFont('helvetica', '', cert_attestation_size($pdf, $attestation, $band['w'], $band['h'], $band['max'], $band['min']));
    $pdf->SetXY($band['x'], $band['y']);
    $pdf->MultiCell($band['w'], $band['line'], $attestation, 0, 'L');

    // Signature blocks. The operator line carries the erasure completion date;
    // the validator line is only drawn when a validator was actually supplied
    // (the account model has no second signatory to fall back to).
    $signDate = (($g['last'] ?? null) !== null) ? fmt_date($g['last']) : gmdate('Y-m-d');
    $sigBlock = function (float $sx, float $sy, float $sw, string $role, string $name, string $date) use ($pdf): void {
        $title = $name !== '' ? $name : '________________________';
        if ($date !== '') {
            $title .= '   ·   ' . $date;
        }
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($sx, $sy);
        $pdf->Cell($sw, 4.5, $title, 0, 0, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(148, 163, 184);
        $pdf->Line($sx, $sy + 6.5, $sx + $sw, $sy + 6.5);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($sx, $sy + 7.5);
        $pdf->Cell($sw, 4, $role, 0, 0, 'L');
    };
    // 'Generated by', not 'Performed by': one certificate can cover a job in which several
    // people did the work, and the name here is the account that produced this PDF (the
    // job's own recorded operator is normally absent — every report so far carries the
    // appliance's 'N/A' sentinel). 'Performed by' asserted that this person did all of it,
    // which the certificate cannot know. Who did what is named per item, on the attested
    // records and in Annex A.
    $sigBlock(30, 140, 117, 'Generated by (operator) — name / date', $operator, $signDate);
    if ($validator !== '') {
        $sigBlock(150, 140, 117, 'Validated by (validator) — name / date', $validator, $signDate);
    }

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
    $scope = 'Item-level evidence is recorded in '
        . ($devices > 0 ? 'Annex A' : 'the operator attestation section')
        . (($devices > 0 && $attItems > 0) ? ' and the operator attestation section' : '')
        . ' and traceable by Certificate ID ' . $certId
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

    // Manually recorded (attested) destruction goes on its own page, so it can never
    // collide with the annex table or push page 1's fixed layout around. A
    // certificate with no attested records takes exactly the path it always did.
    if ($attested) {
        $pdf->AddPage();
        cert_render_attested_section($pdf, $attested, 28.0, $certId, $W, $H);
    }

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

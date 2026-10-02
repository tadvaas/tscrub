<?php
declare(strict_types=1);

/**
 * Comprehensive single-drive HEALTH report PDF (landscape A4).
 *
 * Rendered on demand from one drive snapshot in the Drives tab: identity,
 * SMART / health, security and machine context on a single page — no erasure
 * data. Reuses render_cert.php's TCPDF helpers (tScrubPDF, fmt_*, cert_*)
 * and the canonical logo.png brand mark.
 */

require_once __DIR__ . '/render_cert.php';

/**
 * @param array $d       one drive snapshot (group-drive shape).
 * @param bool  $canSign paid tier — enables the self-signed X.509 PDF signature.
 * @param array $issuer  optional certifier/customer block {name,reg,addr,phone}.
 * @return array{data:string, sha:string, serial:string}
 */
function render_drive_pdf(array $d, bool $canSign, array $issuer = []): array {
    $W = 297.0;
    $H = 210.0;

    $serial = trim((string)($d['serial'] ?? ''));
    $model  = trim((string)($d['model'] ?? ''));

    // Health snapshot: prefer the post-capture values when present.
    $smart   = ($d['smartpost'] ?? '') !== '' ? (string)$d['smartpost'] : (string)($d['smart'] ?? '');
    $temp    = ($d['tempcpost'] ?? '') !== '' ? (string)$d['tempcpost'] : (string)($d['tempc'] ?? '');
    $poh     = ($d['poweronhourspost'] ?? '') !== '' ? (string)$d['poweronhourspost'] : (string)($d['poweronhours'] ?? '');
    $realloc = ($d['reallocsectorspost'] ?? '') !== '' ? (string)$d['reallocsectorspost'] : (string)($d['reallocsectors'] ?? '');

    $signCert = __DIR__ . '/sign.crt';
    $signKey  = __DIR__ . '/sign.key';

    $pdf = new tScrubPDF();
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0, true);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetCreator('tScrub');
    $pdf->SetTitle('Drive Health Report');
    if ($canSign && is_file($signCert) && is_file($signKey)) {
        $pdf->setSignature(
            'file://' . $signCert,
            'file://' . $signKey,
            '',
            '',
            2,
            [
                'Name'        => 'tScrub',
                'Location'    => 'United Kingdom',
                'Reason'      => 'Drive Health Report',
                'ContactInfo' => 'https://tscrub.com',
            ]
        );
    }

    // ---- Page 1 ----
    $pdf->AddPage();
    $pdf->SetFillColor(250, 249, 246);
    $pdf->Rect(0, 0, $W, $H, 'F');
    cert_logo_mark($pdf, 14, 10);

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetXY($W - 175, 8);
    $pdf->Cell(165, 4, 'Drive Health Report: ' . ($serial !== '' ? $serial : '—') . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');

    $pdf->SetFont('helvetica', 'B', 26);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(10, 16);
    $pdf->Cell($W - 20, 12, 'DRIVE HEALTH REPORT', 0, 1, 'C');

    $sub = 'Serial ' . ($serial !== '' ? $serial : '—') . '  ·  ' . ($model !== '' ? $model : '—');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(10, 32);
    $pdf->Cell($W - 20, 5, $sub, 0, 1, 'C');

    // SMART health banner.
    $health = strtoupper(trim($smart));
    if ($health === 'PASS') {
        $banner = 'SMART HEALTH: PASS'; $bc = [5, 150, 105];
    } elseif ($health === 'FAIL') {
        $banner = 'SMART HEALTH: FAIL'; $bc = [220, 38, 38];
    } else {
        $banner = $health === 'UNSUP' ? 'SMART NOT SUPPORTED' : 'SMART HEALTH: NOT AVAILABLE'; $bc = [100, 116, 139];
    }
    $pdf->SetFillColor($bc[0], $bc[1], $bc[2]);
    $pdf->RoundedRect(30, 41, $W - 60, 7.5, 1.5, '1111', 'F');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(30, 41);
    $pdf->Cell($W - 60, 7.5, $banner, 0, 0, 'C');

    // Section primitives (same palette as the certificate).
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
        $pdf->Cell($labelW, 3.8, $label, 0, 0, 'L');
        $pdf->SetXY($sx + $labelW, $sy);
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($valueW, 3.8, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->Cell($valueW, 3.8, cert_fit($pdf, $value, $valueW - 0.4, 'B', 7.5), 0, 0, 'L');
        }
    };
    $section = function (float $sx, float $sy, float $sw, string $title, array $fields) use ($pdf, $sectionHeader, $row): float {
        $sectionHeader($sx, $sy, $sw, $title);
        $yy = $sy + 5.8;
        $labelW = 29.0;
        foreach ($fields as $f) {
            $row($sx, $yy, $labelW, $sw - $labelW, $f[0], $f[1], $f[2] ?? [11, 18, 32]);
            $yy += 3.8;
        }
        return $yy;
    };

    $identity = [
        ['Model',        $model],
        ['Serial',       $serial],
        ['Size',         (string)($d['size'] ?? '')],
        ['Bus',          (string)($d['bus'] ?? '')],
        ['Type',         type_label((string)($d['type'] ?? ''), $model)],
        ['Firmware',     (string)($d['firmware'] ?? '')],
        ['Sector size',  (string)($d['sector_size'] ?? '')],
        ['Sectors',      cert_grp((string)($d['sectors'] ?? ''))],
    ];

    $smartHealth = [
        ['SMART health',    $smart, cert_verdict_color($smart)],
        ['Temperature',     $temp !== '' ? $temp . ' °C' : ''],
        ['Power-on hours',  cert_grp($poh)],
        ['Power cycles',    cert_grp((string)($d['powercycles'] ?? ''))],
        ['Reallocated',     cert_grp($realloc)],
        ['Used %',          ($d['pctused'] ?? '') !== '' ? (string)$d['pctused'] . '%' : ''],
        ['Available spare', ($d['availspare'] ?? '') !== '' ? (string)$d['availspare'] . '%' : ''],
        ['TBW (TB)',        (string)($d['tbw_tb'] ?? '')],
    ];

    $machine = [
        ['System',        (string)($d['system'] ?? '')],
        ['System serial', (string)($d['sysserial'] ?? '')],
        ['Board',         (string)($d['board'] ?? '')],
        ['BIOS vendor',   (string)($d['bios_vendor'] ?? '')],
        ['TPM',           (string)($d['tpm'] ?? '')],
        ['Asset tag',     (string)($d['asset_tag'] ?? '')],
        ['SKU',           (string)($d['sku'] ?? '')],
        ['CPU',           (string)($d['cpu'] ?? '')],
    ];

    $colX = [16.0, 107.0, 198.0];
    $colW = 88.0;
    $section($colX[0], 52, $colW, 'DRIVE IDENTITY', $identity);
    $section($colX[1], 52, $colW, 'SMART HEALTH', $smartHealth);
    $section($colX[2], 52, $colW, 'MACHINE', $machine);

    // ---- Security & self-test (full width, 4 cells) ----
    $gridY = 92.0;
    $sectionHeader($colX[0], $gridY, $W - 2 * $colX[0], 'SECURITY & SELF-TEST');

    $gcell = function (float $cx, float $cy, float $cw, string $label, string $value, array $color = [11, 18, 32]) use ($pdf): void {
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($cx, $cy);
        $pdf->Cell($cw, 4, strtoupper($label), 0, 0, 'L');
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 9.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->SetXY($cx, $cy + 4.1);
            $pdf->Cell($cw, 5.2, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 9.5);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->SetXY($cx, $cy + 4.1);
            $pdf->Cell($cw, 5.2, cert_fit($pdf, $value, $cw - 1.0, 'B', 9.5), 0, 0, 'L');
        }
    };

    $gw = ($W - 2 * $colX[0]) / 4.0;
    $gx = [$colX[0], $colX[0] + $gw, $colX[0] + 2 * $gw, $colX[0] + 3 * $gw];
    $gy = $gridY + 6.5;
    $secCells = [
        ['HPA',       (string)($d['hpa'] ?? ''),        [11, 18, 32]],
        ['DCO',       (string)($d['dco'] ?? ''),        [11, 18, 32]],
        ['SED / OPAL',(string)($d['sed_status'] ?? ''), [11, 18, 32]],
        ['Self-test', (string)($d['selftest'] ?? ''),   cert_verdict_color((string)($d['selftest'] ?? ''))],
    ];
    for ($i = 0; $i < 4; $i++) {
        $gcell($gx[$i], $gy, $gw, $secCells[$i][0], $secCells[$i][1], $secCells[$i][2]);
    }
    $gy += 9.4;

    // Point-in-time note.
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($colX[0], $gy + 2);
    $pdf->Cell($W - 2 * $colX[0], 4, 'SMART and self-test values are point-in-time captures; this document records the drive health at the time of inspection.', 0, 1, 'L');

    // Certifier + footer.
    if (($issuer['name'] ?? '') !== '') {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetXY($colX[0], $gy + 7);
        $pdf->Cell($W - 2 * $colX[0], 4, 'Prepared for ' . $issuer['name'], 0, 1, 'L');
    }

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(20, 194);
    $pdf->Cell($W - 40, 5, 'Prepared with tScrub — drive health record  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    $data = $pdf->Output('', 'S');
    return [
        'data'   => $data,
        'sha'    => strtolower(hash('sha256', $data)),
        'serial' => $serial,
    ];
}

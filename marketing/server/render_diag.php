<?php
declare(strict_types=1);

/**
 * Shared Device Diagnostics Report PDF renderer.
 *
 * Produces the printable/emailable ITAD-triage report (the boot-time "Devices"
 * tab snapshot) as a two-page landscape-A4 PDF:
 *   page 1 — summary (identity, security, hardware, personnel, audit badges)
 *   page 2 — storage inventory table.
 *
 * Mirrors render_cert.php's TCPDF pipeline (tScrubPDF subclass, same palette,
 * badges, QR and X.509 signature) but is an INFORMATION report — never a
 * destruction attestation.
 *
 * Built exclusively from TCPDF's native Cell / MultiCell / Line primitives.
 * No writeHTML anywhere (deliberate): HTML rendering in TCPDF is imprecise and
 * this layout uses fixed coordinates so every element is exactly placed.
 */

require_once __DIR__ . '/render_cert.php'; // tScrubPDF + fmt_ts/fmt_date/type_label

/**
 * Fit a value into an exact width budget using the PDF's real font metrics,
 * ellipsising at a character boundary so no glyph is ever clipped mid-cell.
 */
function diag_fit(TCPDF $pdf, string $s, float $widthMm, string $style = 'B', float $size = 8.5): string {
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

/** Group integer digits with thousands separators for readability (3907029168 → 3,907,029,168). */
function diag_grp(string $v): string {
    if ($v === '' || !preg_match('/^\d+$/', $v)) {
        return $v;
    }
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $v);
}

/** Rebuild the appliance-shaped payload from the stored (transformed) one. */
function diag_stored_to_raw(array $p): array {
    $sys = (string)($p['system'] ?? '');
    $mfr = (string)($p['manufacturer'] ?? '');
    $prd = (string)($p['product'] ?? '');
    if ($mfr === '' && $prd === '' && $sys !== '') {
        $prd = $sys;   // legacy rows only carried the combined "system"
    }
    return [
        'report_id'          => (string)($p['report_id'] ?? ''),
        'digital_identifier' => (string)($p['digital_identifier'] ?? ''),
        'tool_version'       => (string)($p['tool_version'] ?? ''),
        'manufacturer'       => $mfr,
        'product'            => $prd,
        'serial'             => (string)($p['sysserial'] ?? $p['sysSerial'] ?? ''),
        'uuid'               => (string)($p['systemuuid'] ?? ''),
        'board_serial'       => (string)($p['bbserial'] ?? $p['bbSerial'] ?? ''),
        'chassis_serial'     => (string)($p['chassisserial'] ?? ''),
        'chassis_type'       => (string)($p['chassistype'] ?? ''),
        'sku'                => (string)($p['sku'] ?? ''),
        'asset_tag'          => (string)($p['asset_tag'] ?? ''),
        'bios_version'       => (string)($p['biosversion'] ?? ''),
        'bios_date'          => (string)($p['biosdate'] ?? ''),
        'bios_vendor'        => (string)($p['bios_vendor'] ?? ''),
        'bios_lock'          => (string)($p['bioslock'] ?? ''),
        'bios_lock_method'   => (string)($p['bioslockmethod'] ?? ''),
        'tpm'                => (string)($p['tpm'] ?? ''),
        'secure_boot'        => (string)($p['secure_boot'] ?? ''),
        'cpu'                => (string)($p['cpu'] ?? ''),
        'gpu'                => (string)($p['gpu'] ?? ''),
        'ram'                => (string)($p['ram'] ?? ''),
        'dimms'              => (string)($p['dimms'] ?? ''),
        'battery'            => (string)($p['battery'] ?? ''),
        'macs'               => (string)($p['macs'] ?? ''),
        'storage_controllers' => (string)($p['storage_controllers'] ?? ''),
        'operator'           => (string)($p['operator'] ?? ''),
        'validator'          => (string)($p['validator'] ?? ''),
        'media_source'       => (string)($p['media_source'] ?? ''),
        'media_destination'  => (string)($p['media_destination'] ?? ''),
        'selftest_cpu'       => (string)($p['selftest_cpu'] ?? ''),
        'drives'             => is_array($p['drives'] ?? null) ? $p['drives'] : [],
    ];
}

/**
 * Page 2 — per-drive storage inventory. Each drive is rendered as a readable
 * card (a 4-column label/value grid) rather than a dense horizontal table, so
 * the full captured detail — identity, geometry, security and every SMART
 * attribute — stays legible even with long model/serial/sector values.
 */
function diag_render_storage(TCPDF $pdf, float $x, float $W, float $H, string $reportId, array $drives): void {
    $bg = function () use ($pdf, $W, $H): void {
        $pdf->SetFillColor(250, 249, 246);
        $pdf->Rect(0, 0, $W, $H, 'F');
    };
    $pageLabel = function () use ($pdf, $W, $reportId): void {
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($W - 175, 8);
        $pdf->Cell(165, 4, 'Report ID: ' . $reportId . '  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'R');
    };

    $pdf->AddPage();
    $bg();
    $pageLabel();

    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY($x, 10);
    $pdf->Cell($W - 2 * $x, 10, 'STORAGE INVENTORY', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($x, 21);
    $pdf->Cell($W - 2 * $x, 4.5, 'Diagnostics type: Information — SMART and self-test results are point-in-time and do not attest to data erasure.', 0, 1, 'C');

    if ($drives === []) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, 60);
        $pdf->Cell($W - 2 * $x, 8, 'No storage devices detected.', 0, 1, 'C');
        return;
    }

    $verdictColor = function (string $v): array {
        $u = strtoupper(trim($v));
        if ($u === 'PASS')    return [5, 150, 105];
        if ($u === 'FAIL')    return [220, 38, 38];
        if ($u === 'UNKNOWN') return [217, 119, 6];
        return [148, 163, 184];   // UNSUP / N/A / empty
    };

    // One label/value cell: label in small caps above the bold value.
    $cell = function (float $cx, float $cy, float $cw, string $label, string $value, array $color = [11, 18, 32]) use ($pdf): void {
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($cx, $cy);
        $pdf->Cell($cw, 4, strtoupper($label), 0, 0, 'L');
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 9.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->SetXY($cx, $cy + 4.1);
            $pdf->Cell($cw, 5.5, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 9.5);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->SetXY($cx, $cy + 4.1);
            $pdf->Cell($cw, 5.5, diag_fit($pdf, $value, $cw - 1.0, 'B', 9.5), 0, 0, 'L');
        }
    };

    $colW  = ($W - 2 * $x) / 4.0;
    $cellH = 10.4;
    $cols  = [$x, $x + $colW, $x + 2 * $colW, $x + 3 * $colW];

    $pdf->SetY(32);
    $idx = 0;
    foreach ($drives as $d) {
        $idx++;
        $cardH = 6.5 + 6 * $cellH + 3.0;   // header + rule + 6 rows + bottom pad
        if ($pdf->GetY() + $cardH > $H - 12) {
            $pdf->AddPage();
            $bg();
            $pageLabel();
        }

        $y = $pdf->GetY() + 3.0;

        // Card header.
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, $y);
        $pdf->Cell($W - 2 * $x, 5, 'DEVICE ' . $idx . ' · ' . diag_fit($pdf, (string)($d['device'] ?? ''), 90, 'B', 11), 0, 0, 'L');
        $y += 5.4;
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $y, $W - $x, $y);
        $pdf->SetDrawColor(0, 0, 0);
        $y += 2.6;

        // Row 1 — identity.
        $cell($cols[0], $y, $colW, 'Model',       (string)($d['model'] ?? ''));
        $cell($cols[1], $y, $colW, 'Serial',      (string)($d['serial'] ?? ''));
        $cell($cols[2], $y, $colW, 'Size',        (string)($d['size'] ?? ''));
        $cell($cols[3], $y, $colW, 'Bus',         (string)($d['bus'] ?? ''));
        $y += $cellH;

        // Row 2 — media type + geometry.
        $cell($cols[0], $y, $colW, 'Type',        strtoupper((string)($d['type'] ?? '')));
        $cell($cols[1], $y, $colW, 'Firmware',    (string)($d['firmware'] ?? ''));
        $cell($cols[2], $y, $colW, 'Sector size', (string)($d['sector_size'] ?? ''));
        $cell($cols[3], $y, $colW, 'Sectors',     diag_grp((string)($d['sectors'] ?? '')));
        $y += $cellH;

        // Row 3 — security + overall health.
        $sed = !empty($d['opal_locked']) ? 'Locked' : 'No';
        $cell($cols[0], $y, $colW, 'SED / OPAL', $sed, !empty($d['opal_locked']) ? [220, 38, 38] : [11, 18, 32]);
        $cell($cols[1], $y, $colW, 'HPA',         (string)($d['hpa'] ?? ''));
        $cell($cols[2], $y, $colW, 'DCO',         (string)($d['dco'] ?? ''));
        $cell($cols[3], $y, $colW, 'SMART health', (string)($d['smart'] ?? ''), $verdictColor((string)($d['smart'] ?? '')));
        $y += $cellH;

        // Row 4 — SMART counters.
        $cell($cols[0], $y, $colW, 'Temp',          ($d['temp'] ?? '') !== '' ? (string)$d['temp'] . ' °C' : '');
        $cell($cols[1], $y, $colW, 'Power-on hours', diag_grp((string)($d['poh'] ?? '')));
        $cell($cols[2], $y, $colW, 'Power cycles',   diag_grp((string)($d['cycles'] ?? '')));
        $cell($cols[3], $y, $colW, 'Reallocated',    diag_grp((string)($d['realloc'] ?? '')));
        $y += $cellH;

        // Row 5 — wear.
        $cell($cols[0], $y, $colW, 'Used',  ($d['pct_used'] ?? '') !== '' ? (string)$d['pct_used'] . '%' : '');
        $cell($cols[1], $y, $colW, 'Spare', ($d['spare'] ?? '') !== '' ? (string)$d['spare'] . '%' : '');
        $cell($cols[2], $y, $colW, 'TBW',   ($d['tbw'] ?? '') !== '' ? (string)$d['tbw'] . ' TB' : '');
        // column 4 intentionally empty on this row
        $y += $cellH;

        // Row 6 — self-tests (two wide cells).
        $cell($cols[0], $y, 2 * $colW, 'Self-test (SMART log)', (string)($d['selftest'] ?? ''));
        $cell($cols[2], $y, 2 * $colW, 'Self-test (run)',       (string)($d['selftest_run'] ?? ''), $verdictColor((string)($d['selftest_run'] ?? '')));

        $pdf->SetY($y + $cellH);
    }
}

/**
 * Render one boot-time diagnostics snapshot into its own PDF.
 *
 * @param array  $d        the appliance payload (the same shape consumed by
 *                         reports_lib.php store_diagnostics_report()).
 * @param array  $issuer   optional certifier/customer block {name,reg,addr,phone}.
 * @param bool   $canSign  paid tier — enables the self-signed X.509 PDF signature.
 * @param string $sigState report Ed25519 state: attributed|valid|invalid|none.
 * @return array{data:string, sha:string, report_id:string, devices:int, drives:array}
 */
function render_diagnostics_pdf(array $d, array $issuer = [], bool $canSign = false, string $sigState = 'none'): array {
    $reportId  = trim((string)($d['report_id'] ?? ''));
    $digitalId = trim((string)($d['digital_identifier'] ?? ''));
    $toolVer   = trim((string)($d['tool_version'] ?? ''));
    $drives    = is_array($d['drives'] ?? null) ? $d['drives'] : [];

    usort($drives, function ($a, $b) {
        return strcmp((string)($a['device'] ?? ''), (string)($b['device'] ?? ''));
    });

    $W = 297.0;
    $H = 210.0;
    $signCert = __DIR__ . '/sign.crt';
    $signKey  = __DIR__ . '/sign.key';

    $pdf = new tScrubPDF();
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0, true);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->SetCreator('tScrub');
    $pdf->SetTitle('tScrub Device Diagnostics Report');
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
                'Reason'      => 'Device Diagnostics Report',
                'ContactInfo' => 'https://tscrub.com',
            ]
        );
    }

    // ---- Page 1: summary ----
    $pdf->AddPage();
    // Plain off-white paper background (no decorative certificate frame).
    $pdf->SetFillColor(250, 249, 246);
    $pdf->Rect(0, 0, $W, $H, 'F');

    $pdf->SetFont('helvetica', 'B', 30);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(10, 24);
    $pdf->Cell($W - 20, 14, 'tScrub Device Diagnostics Report', 0, 1, 'C');

    $sub = 'Report ID: ' . ($reportId !== '' ? $reportId : 'N/A')
        . '   ·   ' . gmdate('Y-m-d H:i') . ' UTC'
        . ($toolVer !== '' ? '   ·   tScrub ' . $toolVer : '');
    $pdf->SetFont('helvetica', '', 13);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(10, 42);
    $pdf->Cell($W - 20, 6, $sub, 0, 1, 'C');

    // ---- section primitives (fixed coordinates, no HTML) ----
    $sectionHeader = function (float $x, float $y, float $w, string $title) use ($pdf): void {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 0, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $y + 5.4, $x + $w, $y + 5.4);
        $pdf->SetDrawColor(0, 0, 0);
    };

    $row = function (float $x, float $y, float $labelW, float $valueW, string $label, string $value, array $valueColor = [11, 18, 32]) use ($pdf): void {
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, $y);
        $pdf->Cell($labelW, 4.3, $label, 0, 0, 'L');

        $pdf->SetXY($x + $labelW, $y);
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 8.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($valueW, 4.3, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor($valueColor[0], $valueColor[1], $valueColor[2]);
            $pdf->Cell($valueW, 4.3, diag_fit($pdf, $value, $valueW, 'B', 8.5), 0, 0, 'L');
        }
    };

    $section = function (float $x, float $y, float $w, string $title, array $fields) use ($pdf, $sectionHeader, $row): float {
        $sectionHeader($x, $y, $w, $title);
        $yy = $y + 7.0;
        $labelW = 42.0;
        foreach ($fields as $f) {
            $row($x, $yy, $labelW, $w - $labelW, $f[0], $f[1], $f[2] ?? [11, 18, 32]);
            $yy += 4.3;
        }
        return $yy;
    };

    // ---- field sets ----
    $asset = [
        ['Manufacturer',     (string)($d['manufacturer'] ?? '')],
        ['Model',            (string)($d['product'] ?? '')],
        ['Chassis',          (string)($d['chassis_type'] ?? '')],
        ['SKU',              (string)($d['sku'] ?? '')],
        ['System serial',    (string)($d['serial'] ?? '')],
        ['Board serial',     (string)($d['board_serial'] ?? '')],
        ['Chassis serial',   (string)($d['chassis_serial'] ?? '')],
        ['System UUID',      (string)($d['uuid'] ?? '')],
        ['Asset tag',        (string)($d['asset_tag'] ?? '')],
    ];

    $biosLock = strtoupper(trim((string)($d['bios_lock'] ?? '')));
    if ($biosLock === 'LOCKED')          { $lockLabel = 'Locked';   $lockColor = [220, 38, 38]; }
    elseif ($biosLock === 'UNLOCKED')    { $lockLabel = 'Unlocked'; $lockColor = [5, 150, 105]; }
    else                                 { $lockLabel = $biosLock !== '' ? $biosLock : ''; $lockColor = [217, 119, 6]; }
    $biosVersion = trim((string)($d['bios_version'] ?? ''));
    if ($biosVersion !== '' && trim((string)($d['bios_date'] ?? '')) !== '') {
        $biosVersion .= ' · ' . trim((string)$d['bios_date']);
    }
    $sec = [
        ['BIOS lock',    $lockLabel, $lockColor],
        ['TPM',          (string)($d['tpm'] ?? '')],
        ['Secure Boot',  (string)($d['secure_boot'] ?? '')],
        ['BIOS version', $biosVersion],
        ['BIOS vendor',  (string)($d['bios_vendor'] ?? '')],
    ];

    $hw = [
        ['CPU',                 (string)($d['cpu'] ?? '')],
        ['Memory',              (string)($d['ram'] ?? '')],
        ['DIMMs',               (string)($d['dimms'] ?? '')],
        ['GPU',                 (string)($d['gpu'] ?? '')],
        ['Battery',             (string)($d['battery'] ?? '')],
        ['MACs',                (string)($d['macs'] ?? '')],
        ['Storage controllers', (string)($d['storage_controllers'] ?? '')],
    ];

    $personnel = [
        ['Operator',          (string)($d['operator'] ?? '')],
        ['Validator',         (string)($d['validator'] ?? '')],
        ['Media source',      (string)($d['media_source'] ?? '')],
        ['Media destination', (string)($d['media_destination'] ?? '')],
    ];
    if (trim((string)($issuer['name'] ?? '')) !== '') {
        $personnel[] = ['Customer', trim((string)$issuer['name'])];
    }

    $section(20, 58, 125, 'ASSET IDENTITY', $asset);
    $section(152, 58, 125, 'SECURITY STATE', $sec);
    $section(20, 112, 125, 'HARDWARE INVENTORY', $hw);
    $section(152, 112, 125, 'PERSONNEL & AUTHORITY', $personnel);

    // ---- diagnostics-type / self-test summary line ----
    $cpuTest = strtoupper(trim((string)($d['selftest_cpu'] ?? '')));
    $hasStorage = false;
    foreach ($drives as $dv) {
        if (trim((string)($dv['selftest_run'] ?? '')) !== '') { $hasStorage = true; break; }
    }
    if ($cpuTest === '' && !$hasStorage) {
        $diag = 'DIAGNOSTICS TYPE: Information — no component self-tests were run';
    } else {
        $parts = ['DIAGNOSTICS TYPE: Information'];
        if ($cpuTest !== '') { $parts[] = 'CPU self-test: ' . $cpuTest; }
        if ($hasStorage)     { $parts[] = 'Storage self-test: performed'; }
        $diag = implode('     ·     ', $parts);
    }
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(11, 18, 32);
    $pdf->SetXY(20, 156);
    $pdf->Cell(257, 5, diag_fit($pdf, $diag, 257, 'B', 9), 0, 1, 'L');

    // ---- audit badges ----
    if ($digitalId !== '') { $intTxt = 'SHA-256 RECORDED';      $intColor = [5, 150, 105]; }
    else                   { $intTxt = 'SHA-256 NOT RECORDED';   $intColor = [148, 163, 184]; }

    if (!$canSign)                       { $sigTxt = 'FREE TIER — NO VERIFICATION'; $sigColor = [100, 116, 139]; }
    elseif ($sigState === 'attributed')  { $sigTxt = 'REPORT SIGNATURE VALID';      $sigColor = [5, 150, 105]; }
    elseif ($sigState === 'valid')       { $sigTxt = 'SIGNATURE UNATTRIBUTED';      $sigColor = [217, 119, 6]; }
    elseif ($sigState === 'invalid')     { $sigTxt = 'REPORT SIGNATURE INVALID';    $sigColor = [220, 38, 38]; }
    else                                 { $sigTxt = 'REPORT NOT SIGNED';           $sigColor = [71, 85, 105]; }

    $pdf->setCellPaddings(1.6, 0, 1.6, 0);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->SetTextColor($intColor[0], $intColor[1], $intColor[2]);
    $pdf->SetXY(30, 164);
    $pdf->Cell(88, 8, $intTxt, 1, 0, 'C');
    $pdf->SetTextColor($sigColor[0], $sigColor[1], $sigColor[2]);
    $pdf->SetXY(124, 164);
    $pdf->Cell(88, 8, $sigTxt, 1, 0, 'C');
    $pdf->setCellPaddings(0, 0, 0, 0);

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(30, 178);
    $pdf->Cell(200, 4, 'Digital identifier: ' . ($digitalId !== '' ? $digitalId : 'N/A'), 0, 0, 'L');

    // ---- verification QR ----
    $verifyUrl = 'https://tscrub.com/verify?report=' . rawurlencode($reportId);
    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', 238, 158, 28, 28, ['border' => 0, 'padding' => 0], 'N');
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(238, 187);
    $pdf->Cell(28, 4, 'Verify online', 0, 0, 'C');

    // ---- footer ----
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(20, 194);
    $pdf->Cell($W - 40, 5, 'Prepared with tScrub — device diagnostics  |  Page ' . $pdf->getAliasNumPage() . ' of ' . $pdf->getAliasNbPages(), 0, 0, 'C');

    // ---- Page 2: storage inventory ----
    diag_render_storage($pdf, 10.0, $W, $H, $reportId, $drives);

    $data = $pdf->Output('', 'S');
    return [
        'data'      => $data,
        'sha'       => hash('sha256', $data),
        'report_id' => $reportId,
        'devices'   => count($drives),
        'drives'    => $drives,
    ];
}

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
require_once __DIR__ . '/grading.php';

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

/**
 * The tScrub brand mark — the single shared logo asset (site/public/logo.png:
 * the green rounded square + white "t" used by the website and favicon).
 * deploy-server.sh copies that PNG into the server dir at deploy time, so the
 * PDF embeds the exact same artwork instead of re-drawing the mark.
 */
function diag_logo_mark(TCPDF $pdf, float $x, float $y, float $size = 12.0): void {
    $logo = __DIR__ . '/logo.png';
    if (!is_file($logo)) {
        return; // asset missing (partial deploy) — skip rather than fail the report
    }
    $pdf->Image($logo, $x, $y, $size, $size, 'PNG');
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
        'board'              => (string)($p['board'] ?? ''),
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
        'cpu_spec'           => (string)($p['cpu_spec'] ?? ''),
        'gpu'                => (string)($p['gpu'] ?? ''),
        'ram'                => (string)($p['ram'] ?? ''),
        'dimms'              => (string)($p['dimms'] ?? ''),
        'display'            => (string)($p['display'] ?? ''),
        'wifi'               => (string)($p['wifi'] ?? ''),
        'battery'            => (string)($p['battery'] ?? ''),
        'macs'               => (string)($p['macs'] ?? ''),
        'storage_controllers' => (string)($p['storage_controllers'] ?? ''),
        'operator'           => (string)($p['operator'] ?? ''),
        'validator'          => (string)($p['validator'] ?? ''),
        'media_source'       => (string)($p['media_source'] ?? ''),
        'media_destination'  => (string)($p['media_destination'] ?? ''),
        'selftest_cpu'       => (string)($p['selftest_cpu'] ?? ''),
        'usb_devices'        => (string)($p['usb_devices'] ?? ''),
        'pci_devices'        => (string)($p['pci_devices'] ?? ''),
        'smbios'             => (string)($p['smbios'] ?? ''),
        'interfaces'         => (string)($p['interfaces'] ?? ''),
        'uefi_boot_entries'  => (string)($p['uefi_boot_entries'] ?? ''),
        'peripherals'        => (string)($p['peripherals'] ?? ''),
        'bios_lockdown'      => isset($p['bios_lockdown']) ? (int)$p['bios_lockdown'] : 0,
        'family'             => (string)($p['family'] ?? ''),
        'system_version'     => (string)($p['system_version'] ?? ''),
        'board_version'      => (string)($p['board_version'] ?? ''),
        'cpu_socket'         => (string)($p['cpu_socket'] ?? ''),
        'cpu_family'         => (string)($p['cpu_family'] ?? ''),
        'cpu_id'             => (string)($p['cpu_id'] ?? ''),
        'cpu_voltage'        => (string)($p['cpu_voltage'] ?? ''),
        'bios_revision'      => (string)($p['bios_revision'] ?? ''),
        'bios_firmware_revision' => (string)($p['bios_firmware_revision'] ?? ''),
        'chassis_lock'       => (string)($p['chassis_lock'] ?? ''),
        'chassis_state'      => (string)($p['chassis_state'] ?? ''),
        'onboard_devices'    => (string)($p['onboard_devices'] ?? ''),
        'oem_strings'        => (string)($p['oem_strings'] ?? ''),
        'battery_model'      => (string)($p['battery_model'] ?? ''),
        'battery_chemistry'  => (string)($p['battery_chemistry'] ?? ''),
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

    // Every storage page carries the same header (logo, report/page label,
    // title and diagnostics-type note) and starts the cards at the same Y,
    // so spilled pages never overlap the header.
    $pageHeader = function () use ($pdf, $W, $x, $bg, $pageLabel): void {
        $pdf->AddPage();
        $bg();
        $pageLabel();
        diag_logo_mark($pdf, 14, 10);

        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, 10);
        $pdf->Cell($W - 2 * $x, 10, 'STORAGE INVENTORY', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, 21);
        $pdf->Cell($W - 2 * $x, 4.5, 'Diagnostics type: Information — SMART and self-test results are point-in-time and do not attest to data erasure.', 0, 1, 'C');

        $pdf->SetY(32);
    };
    $pageHeader();

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

    $gradeColor = function (string $g): array {
        switch ($g) {
            case 'A': return [5, 150, 105];
            case 'B': return [13, 148, 136];
            case 'C': return [217, 119, 6];
            case 'D': return [220, 38, 38];
            default:  return [148, 163, 184];   // '?' ungraded
        }
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
        $cardH = 6.5 + 7 * $cellH + 3.0;   // header + rule + 7 rows + bottom pad
        if ($pdf->GetY() + $cardH > $H - 12) {
            $pageHeader();
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
        $y += $cellH;

        // Row 7 — refurb grade (full width).
        $g = drive_grade($d);
        $gradeTxt = 'Refurb grade ' . $g['grade'];
        if ($g['reasons'] !== []) { $gradeTxt .= ' — ' . implode('; ', $g['reasons']); }
        $cell($cols[0], $y, $W - 2 * $x, 'Resale grade', $gradeTxt, $gradeColor($g['grade']));

        $pdf->SetY($y + $cellH);
    }
}

/** Parse the peripherals "webcam:1; touchscreen:0; …" string into ordered [label, Yes/No] pairs. */
function diag_peripherals_pairs(string $s): array {
    $map = [];
    foreach (explode(';', $s) as $kv) {
        $p = explode(':', $kv);
        if (count($p) === 2) $map[trim($p[0])] = trim($p[1]);
    }
    $out = [];
    foreach (['webcam' => 'Webcam', 'touchscreen' => 'Touchscreen', 'fingerprint' => 'Fingerprint', 'accelerometer' => 'Accelerometer', 'audio' => 'Audio'] as $k => $label) {
        if (($map[$k] ?? '') === '1')      $out[] = [$label, 'Yes'];
        elseif (($map[$k] ?? '') === '0')  $out[] = [$label, 'No'];
    }
    return $out;
}

/** Format the peripherals "webcam:1; touchscreen:0; …" string for the PDF. */
function diag_peripherals_human(string $s): string {
    $parts = [];
    foreach (diag_peripherals_pairs($s) as [$label, $val]) {
        $parts[] = $label . ': ' . $val;
    }
    return implode('  ·  ', $parts);
}

/** Parse the chassis-state "Boot: X · Power: Y · …" summary into [label, value] pairs. */
function diag_chassis_state_pairs(string $s): array {
    $out = [];
    foreach (explode('·', $s) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $p = explode(':', $part, 2);
        if (count($p) !== 2) continue;
        $label = trim($p[0]);
        $val   = trim($p[1]);
        if ($label !== '' && $val !== '') $out[] = [$label, $val];
    }
    return $out;
}

/**
 * Colour for one chassis-state value:
 *   Safe / None               → green (healthy / no intrusion)
 *   Warning                   → amber (degraded)
 *   Critical / Non-recoverable → red (fault)
 *   anything else (Unknown, …) → grey (no signal)
 */
function diag_chassis_state_color(string $v): array {
    $v = strtolower(trim($v));
    if ($v === 'safe' || $v === 'none') {
        return [5, 150, 105];
    }
    if (str_contains($v, 'critical') || str_contains($v, 'non-recoverable')) {
        return [220, 38, 38];
    }
    if (str_contains($v, 'warning')) {
        return [217, 119, 6];
    }
    return [148, 163, 184];
}

/** True for loopback/tunnel/software NICs that carry no asset value. */
function diag_iface_virtual(string $name): bool {
    return (bool)preg_match('/^(lo|sit|tun|tap|veth|br|bond|dummy|docker|virbr|vboxnet|vmnet|vlan)/i', $name);
}

/**
 * Turn the raw lspci -nn blob (or the sysfs fallback) into short, readable
 * device lines — one "Class: Vendor Device" per meaningful device, with
 * chipset glue (host/PCI/ISA bridges, SMBus, root ports) dropped.
 */
function diag_pci_human(string $raw): array {
    $out = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;

        // Chipset infrastructure — no asset value for the reader.
        if (preg_match('/\b(host bridge|pci bridge|isa bridge|smbus|root port|signal processing controller|communication controller|serial controller)\b/i', $line)) continue;
        // RAM/PMC memory controller is chipset glue; keep NVMe ("Non-Volatile memory controller").
        if (preg_match('/(?<!non-volatile )memory controller\b/i', $line)) continue;

        if (preg_match('/^[0-9a-f:.]+\s+\[[0-9a-f]{6}\]\s+(.+?)\s+[0-9a-f]{4}:[0-9a-f]{4}$/i', $line, $m)) {
            // sysfs fallback: "0000:00:1f.3 [040300] Multimedia 8086:a170"
            $line = $m[1];
        } else {
            // lspci -nn: "00:1f.6 Ethernet controller [0200]: Intel … [8086:15d7] (rev 21)"
            $line = preg_replace('/^[0-9a-f:.]+\s+/', '', $line);
            $line = preg_replace('/\s*\[[0-9a-f]{4}\]/', '', $line);
            $line = preg_replace('/\s+\[[0-9a-f]{4}:[0-9a-f]{4}\]/', '', $line);
            $line = preg_replace('/\s*\(rev\s+[0-9a-f]+\)/', '', $line);
            $line = preg_replace('/\s*\([A-Z][0-9]? step\)/i', '', $line);
        }

        // Vendor-name noise ("Intel Corporation" -> "Intel", etc.).
        $line = preg_replace('/\b(?:Corporation|Electronics Co\.?[, ]*\s*Ltd\.?|Semiconductor Co\.?[, ]*\s*Ltd\.?|Co\.?[, ]*\s*Ltd\.?|Inc\.?|Ltd\.?|LLC|Company|and subsidiaries)\b/i', ' ', $line);
        $line = trim(preg_replace('/\s{2,}/', ' ', $line), " \t,:;");
        if ($line === '') continue;
        $out[] = $line;
    }
    return $out;
}

/**
 * Turn the raw USB "vid:pid manufacturer product" list into readable device
 * lines — Linux Foundation root hubs and host controllers are dropped, the
 * placeholder "Generic" manufacturer is removed, and common bare vid:pids are
 * given a friendly name.
 */
function diag_usb_human(string $raw): array {
    $known = [
        '8087:0025' => 'Intel Wireless-AC 9260 Bluetooth',
        '8087:0026' => 'Intel AX201 Bluetooth',
        '8087:0029' => 'Intel AX200 Bluetooth',
        '8087:0032' => 'Intel AX210 Bluetooth',
        '8087:0033' => 'Intel AX211 Bluetooth',
        '8087:0036' => 'Intel BE200 Bluetooth',
        '8087:0a2b' => 'Intel Bluetooth',
        '8087:0aa7' => 'Intel Wireless-AC 3168 Bluetooth',
        '8087:0aaa' => 'Intel Bluetooth 9460/9560',
    ];
    $out = [];
    foreach (preg_split('/\r?\n|;\s*/', trim($raw)) as $it) {
        $it = trim((string)$it);
        if ($it === '') continue;
        if (preg_match('/^1d6b:/i', $it)) continue;                 // Linux Foundation root hub
        if (preg_match('/host controller/i', $it)) continue;         // internal USB controllers
        $it = preg_replace('/^([0-9a-f]{4}:[0-9a-f]{4})\s+Generic\s+/i', '$1 ', $it);
        if (preg_match('/^([0-9a-f]{4}):([0-9a-f]{4})$/i', $it, $m)) {
            // Bare vendor:product ID (no name) — name the common Intel Bluetooth IDs.
            $key = strtolower($m[1] . ':' . $m[2]);
            $it = $known[$key] ?? $it;
        } else {
            // Named entry — drop the vendor:product ID wherever it appears.
            $it = preg_replace('/^[0-9a-f]{4}:[0-9a-f]{4}\s+/', '', $it);
            $it = preg_replace('/\s*\([0-9a-f]{4}:[0-9a-f]{4}\)\s*$/', '', $it);
        }
        $it = trim(preg_replace('/\s{2,}/', ' ', $it), " \t,:;");
        if ($it === '') continue;
        $out[] = $it;
    }
    return $out;
}

/**
 * Page 3 — hardware annex: the extended machine inventory (NICs, storage
 * controllers, PCI, USB, boot entries, peripherals, firmware state), laid out
 * Blancco-style as full-width labelled rows and stacked lists. Rendered between
 * the summary page and the storage annex.
 */
function diag_render_hardware(TCPDF $pdf, float $x, float $W, float $H, string $reportId, array $d): void {
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
    $pageHeader = function () use ($pdf, $W, $x, $bg, $pageLabel): void {
        $pdf->AddPage();
        $bg();
        $pageLabel();
        diag_logo_mark($pdf, 14, 10);
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, 10);
        $pdf->Cell($W - 2 * $x, 10, 'HARDWARE INVENTORY', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, 21);
        $pdf->Cell($W - 2 * $x, 4.5, 'Full asset record — point-in-time inventory captured at boot (see the JSON download for the raw SMBIOS dump).', 0, 1, 'C');
    };
    $pageHeader();

    $y = 30.0;
    $labelW = 50.0;

    // Add a page + header when the next block won't fit.
    $need = function (float $h) use (&$y, $pageHeader, $H): void {
        if ($y + $h > $H - 14) { $pageHeader(); $y = 30.0; }
    };

    $sub = function (string $title) use (&$y, $need, $pdf, $x, $W): void {
        $need(11.0);
        $y += 3.0; // breathing room above the section heading
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(11, 18, 32);
        $pdf->SetXY($x, $y);
        $pdf->Cell($W - 2 * $x, 5, $title, 0, 1, 'L');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x, $y + 5.4, $W - $x, $y + 5.4);
        $pdf->SetDrawColor(0, 0, 0);
        $y += 7.4;
    };

    $row = function (string $label, string $value, array $color = [11, 18, 32]) use (&$y, $need, $pdf, $x, $W, $labelW): void {
        $need(5.0);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, $y);
        $pdf->Cell($labelW, 4.0, $label, 0, 0, 'L');
        $pdf->SetXY($x + $labelW, $y);
        $empty = trim($value) === '' || strcasecmp(trim($value), 'N/A') === 0;
        if ($empty) {
            $pdf->SetFont('helvetica', '', 8.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($W - 2 * $x - $labelW, 4.0, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->Cell($W - 2 * $x - $labelW, 4.0, diag_fit($pdf, $value, $W - 2 * $x - $labelW - 1, 'B', 8.5), 0, 0, 'L');
        }
        $y += 4.6;
    };

    $list = function (string $label, array $lines) use (&$y, $need, $row, $pdf, $x, $W, $labelW, $H, $pageHeader): void {
        if ($lines === []) { $row($label, ''); return; }
        $need(5.0);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, $y);
        $pdf->Cell($labelW, 4.0, $label, 0, 0, 'L');
        $firstY = $y;
        foreach ($lines as $ln) {
            if ($y + 4.6 > $H - 14) { $pageHeader(); $y = 30.0; }
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(11, 18, 32);
            $pdf->SetXY($x + $labelW, $y);
            $pdf->Cell($W - 2 * $x - $labelW, 4.0, diag_fit($pdf, $ln, $W - 2 * $x - $labelW - 1, '', 7.5), 0, 0, 'L');
            $y += 4.6;
        }
        if ($y === $firstY) { $y += 4.6; }
    };

    $semicolon = function (string $v): array {
        $v = trim((string)$v);
        if ($v === '' || strcasecmp($v, 'N/A') === 0) return [];
        return array_filter(array_map('trim', explode(';', $v)), fn($s) => $s !== '');
    };
    $newline = function (string $v): array {
        $v = trim((string)$v);
        if ($v === '' || strcasecmp($v, 'N/A') === 0) return [];
        return array_filter(array_map('trim', explode("\n", $v)), fn($s) => $s !== '');
    };

    // Generic "label: value · label: value" row whose values are colour-coded
    // by the supplied resolver — used for peripheral presence and chassis state
    // so the status vocabulary renders consistently.
    $verdictRow = function (string $label, array $pairs, callable $colorFor) use (&$y, $need, $row, $pdf, $x, $W, $labelW): void {
        if ($pairs === []) { $row($label, ''); return; }
        $need(5.0);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, $y);
        $pdf->Cell($labelW, 4.0, $label, 0, 0, 'L');
        $pdf->SetXY($x + $labelW, $y);
        $first = true;
        foreach ($pairs as [$itemLabel, $val]) {
            if (!$first) {
                $pdf->SetFont('helvetica', '', 8.5);
                $pdf->SetTextColor(148, 163, 184);
                $pdf->Cell($pdf->GetStringWidth('  ·  ') + 0.2, 4.0, '  ·  ', 0, 0, 'L');
            }
            $first = false;
            $pdf->SetFont('helvetica', '', 8.5);
            $pdf->SetTextColor(11, 18, 32);
            $pdf->Cell($pdf->GetStringWidth($itemLabel . ': ') + 0.2, 4.0, $itemLabel . ': ', 0, 0, 'L');
            [$r, $g, $b] = $colorFor($val);
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor($r, $g, $b);
            $pdf->Cell($pdf->GetStringWidth($val) + 0.2, 4.0, $val, 0, 0, 'L');
        }
        $y += 4.6;
    };

    // Peripheral presence — Yes = green, No = red.
    $periphRow = function (string $value) use ($verdictRow): void {
        $verdictRow('Presence', diag_peripherals_pairs($value), static function (string $v): array {
            return $v === 'Yes' ? [5, 150, 105] : [220, 38, 38];
        });
    };

    // Chassis power/thermal/security states, colour-coded per value.
    $chassisRow = function (string $value) use ($verdictRow): void {
        $verdictRow('Chassis state', diag_chassis_state_pairs($value), 'diag_chassis_state_color');
    };

    $sub('PROCESSOR');
    $row('CPU', trim((string)($d['cpu'] ?? '')));
    $row('Cores / threads', trim((string)($d['cpu_spec'] ?? '')));
    $row('Socket', trim((string)($d['cpu_socket'] ?? '')));
    $row('Family', trim((string)($d['cpu_family'] ?? '')));
    $row('CPU ID', trim((string)($d['cpu_id'] ?? '')));
    $row('Voltage', trim((string)($d['cpu_voltage'] ?? '')));

    $sub('MEMORY');
    $row('Total', trim((string)($d['ram'] ?? '')));
    $list('DIMMs', $semicolon((string)($d['dimms'] ?? '')));

    $sub('GRAPHICS & DISPLAY');
    $row('GPU', trim((string)($d['gpu'] ?? '')));
    $row('Display', trim((string)($d['display'] ?? '')));
    $row('Wi-Fi', trim((string)($d['wifi'] ?? '')));
    $row('Battery', trim((string)($d['battery'] ?? '')));
    $row('Battery model', trim((string)($d['battery_model'] ?? '')));
    $row('Battery chemistry', trim((string)($d['battery_chemistry'] ?? '')));

    $storedGrade = trim((string)($d['refurb_grade'] ?? ''));
    $gradeColor = ['I-A' => [5, 150, 105], 'I-B' => [13, 148, 136], 'I-C' => [217, 119, 6], 'I-D' => [234, 88, 12], 'I-F' => [100, 116, 139]];
    $row('Refurb grade', $storedGrade, $gradeColor[$storedGrade] ?? [148, 163, 184]);

    // Operator notes (e.g. "damaged screen, missing battery") — free-form and
    // word-wrapped so no condition note is truncated away. Empty → omitted.
    $storedNotes = trim((string)($d['notes'] ?? ''));
    if ($storedNotes !== '') {
        $noteW = $W - 2 * $x - $labelW - 1;
        $noteLines = [];
        foreach (preg_split('/\r?\n/', $storedNotes) as $para) {
            $para = trim($para);
            if ($para === '') continue;
            $cur = '';
            foreach (preg_split('/\s+/', $para) as $w) {
                $trial = $cur === '' ? $w : $cur . ' ' . $w;
                if ($pdf->getStringWidth($trial, 'helvetica', '', 7.5) <= $noteW) {
                    $cur = $trial;
                } else {
                    if ($cur !== '') $noteLines[] = $cur;
                    $cur = $w;
                }
            }
            if ($cur !== '') $noteLines[] = $cur;
        }
        $list('Operator notes', $noteLines);
    }

    $sub('NETWORK INTERFACES');
    $ifaces = array_values(array_filter($semicolon((string)($d['interfaces'] ?? (string)($d['macs'] ?? ''))), static function (string $e): bool {
        $name = trim(explode(' ', $e)[0] ?? '');
        return $name === '' || !diag_iface_virtual($name);
    }));
    $list('Interfaces', $ifaces);

    $sub('STORAGE');
    $list('Controllers', $semicolon((string)($d['storage_controllers'] ?? '')));

    $sub('PCI DEVICES');
    $list('Devices', diag_pci_human((string)($d['pci_devices'] ?? '')));

    $sub('USB DEVICES');
    $list('Devices', diag_usb_human((string)($d['usb_devices'] ?? '')));

    $sub('ONBOARD DEVICES');
    $list('Devices', $semicolon((string)($d['onboard_devices'] ?? '')));

    $sub('PERIPHERALS');
    $periphRow((string)($d['peripherals'] ?? ''));

    $sub('FIRMWARE STATE');
    $row('Secure Boot', trim((string)($d['secure_boot'] ?? '')));
    $row('BIOS lockdown', !empty($d['bios_lockdown']) ? 'Suspected' : 'None', !empty($d['bios_lockdown']) ? [220, 38, 38] : [5, 150, 105]);
    $row('BIOS revision', trim((string)($d['bios_revision'] ?? '')));
    $row('Firmware revision', trim((string)($d['bios_firmware_revision'] ?? '')));
    $list('Boot entries', $semicolon((string)($d['uefi_boot_entries'] ?? '')));

    $sub('SYSTEM & CHASSIS');
    $row('System family', trim((string)($d['family'] ?? '')));
    $row('System version', trim((string)($d['system_version'] ?? '')));
    $row('Board version', trim((string)($d['board_version'] ?? '')));
    $row('Chassis lock', trim((string)($d['chassis_lock'] ?? '')));
    $chassisRow((string)($d['chassis_state'] ?? ''));
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
    diag_logo_mark($pdf, 14, 12);

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
        $pdf->Cell($labelW, 4.0, $label, 0, 0, 'L');

        $pdf->SetXY($x + $labelW, $y);
        if ($value === '') {
            $pdf->SetFont('helvetica', '', 8.5);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->Cell($valueW, 4.0, '—', 0, 0, 'L');
        } else {
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor($valueColor[0], $valueColor[1], $valueColor[2]);
            $pdf->Cell($valueW, 4.0, diag_fit($pdf, $value, $valueW, 'B', 8.5), 0, 0, 'L');
        }
    };

    $section = function (float $x, float $y, float $w, string $title, array $fields) use ($pdf, $sectionHeader, $row): float {
        $sectionHeader($x, $y, $w, $title);
        $yy = $y + 7.0;
        $labelW = 42.0;
        foreach ($fields as $f) {
            $row($x, $yy, $labelW, $w - $labelW, $f[0], $f[1], $f[2] ?? [11, 18, 32]);
            $yy += 5.2;
        }
        return $yy;
    };

    // Full-width section laid out as a two-column label:value grid (used for
    // ASSET IDENTITY so its ten fields fill five short rows instead of ten tall
    // ones, keeping page 1 balanced without blank columns).
    $gridSection = function (float $x, float $y, float $w, string $title, array $fields) use ($pdf, $sectionHeader): void {
        $sectionHeader($x, $y, $w, $title);
        $yy = $y + 7.0;
        $colW = $w / 2.0;
        $labelW = 30.0;
        $n = count($fields);
        for ($i = 0; $i < $n; $i += 2) {
            for ($c = 0; $c < 2; $c++) {
                $idx = $i + $c;
                if ($idx >= $n) break;
                $f = $fields[$idx];
                $cx = $x + $c * $colW;
                $pdf->SetFont('helvetica', '', 8.5);
                $pdf->SetTextColor(100, 116, 139);
                $pdf->SetXY($cx, $yy);
                $pdf->Cell($labelW, 4.0, $f[0], 0, 0, 'L');
                $pdf->SetXY($cx + $labelW, $yy);
                if ($f[1] === '') {
                    $pdf->SetFont('helvetica', '', 8.5);
                    $pdf->SetTextColor(148, 163, 184);
                    $pdf->Cell($colW - $labelW, 4.0, '—', 0, 0, 'L');
                } else {
                    $pdf->SetFont('helvetica', 'B', 8.5);
                    $pdf->SetTextColor($f[2][0] ?? 11, $f[2][1] ?? 18, $f[2][2] ?? 32);
                    $pdf->Cell($colW - $labelW, 4.0, diag_fit($pdf, $f[1], $colW - $labelW - 1, 'B', 8.5), 0, 0, 'L');
                }
            }
            $yy += 5.2;
        }
    };

    // ---- field sets ----
    $asset = [
        ['Manufacturer',     (string)($d['manufacturer'] ?? '')],
        ['Model',            (string)($d['product'] ?? '')],
        ['Chassis',          (string)($d['chassis_type'] ?? '')],
        ['SKU',              (string)($d['sku'] ?? '')],
        ['System serial',    (string)($d['serial'] ?? '')],
        ['Board',            (string)($d['board'] ?? '')],
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
        ['BIOS lock',        $lockLabel, $lockColor],
        ['BIOS lock method', (string)($d['bios_lock_method'] ?? '')],
        ['BIOS lockdown',    !empty($d['bios_lockdown']) ? 'Suspected' : 'None', !empty($d['bios_lockdown']) ? [220, 38, 38] : [5, 150, 105]],
        ['TPM',              (string)($d['tpm'] ?? '')],
        ['Secure Boot',      (string)($d['secure_boot'] ?? '')],
        ['BIOS version',     $biosVersion],
        ['BIOS vendor',      (string)($d['bios_vendor'] ?? '')],
    ];

    // Normalise the appliance "N/A" sentinel and omit the validator entirely
    // when none was supplied (mirrors the certificate's personnel strategy).
    $pNorm = function (string $v): string {
        $t = trim($v);
        return ($t === '' || strcasecmp($t, 'N/A') === 0) ? '' : $t;
    };
    $operator   = $pNorm((string)($d['operator'] ?? ''));
    $validator  = $pNorm((string)($d['validator'] ?? ''));
    $mediaSrc   = $pNorm((string)($d['media_source'] ?? ''));
    $mediaDst   = $pNorm((string)($d['media_destination'] ?? ''));

    $personnel = [['Operator', $operator]];
    if ($validator !== '') {
        $personnel[] = ['Validator', $validator];
    }
    $personnel[] = ['Media source', $mediaSrc];
    $personnel[] = ['Media destination', $mediaDst];
    if (trim((string)($issuer['name'] ?? '')) !== '') {
        $personnel[] = ['Customer', trim((string)$issuer['name'])];
    }

    $gridSection(20, 56, 257, 'ASSET IDENTITY', $asset);
    $section(20, 96, 155, 'SECURITY STATE', $sec);
    $section(182, 96, 95, 'PERSONNEL & AUTHORITY', $personnel);

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

    // ---- Page 2: hardware annex (extended inventory) ----
    diag_render_hardware($pdf, 20.0, $W, $H, $reportId, $d);

    // ---- Page 3: storage inventory ----
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

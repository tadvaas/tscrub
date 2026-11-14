<?php
declare(strict_types=1);

/**
 * Refurb grading — drive (A/B/C/D/?) + device (I-A–I-F) rubrics.
 *
 * Pure functions (no DB) so they are trivially unit-testable and shared by the
 * API, the dashboard data endpoints and both PDF renderers. The drive rubric
 * thresholds are Backblaze's "5 SMART stats" research: see
 * research/refurb-grading/competitors.md. A grade is a heuristic, not a
 * guarantee and not a standards conformance claim.
 */

/** Numeric parse: empty / non-numeric → null (never silently 0). */
function grade_num(?string $v): ?float {
    $t = trim((string)$v);
    if ($t === '' || !is_numeric($t)) return null;
    return (float)$t;
}

/**
 * Normalize a drive row to one canonical key set. Accepts both the raw
 * appliance keys (poh/cycles/realloc/pct_used/spare/tbw) used by the
 * diagnostics payload and render_diag.php, and the server's mapped keys
 * (poweronhours/powercycles/reallocsectors/pctused/availspare/tbw_tb) used by
 * parse_reports() / certificate_drives / load_drives().
 */
function grade_normalize(array $d): array {
    return [
        'smart'        => strtoupper(trim((string)($d['smart'] ?? ''))),
        'smart_post'   => strtoupper(trim((string)($d['smartpost'] ?? ''))),
        'poh'          => grade_num((string)($d['poh'] ?? $d['poweronhours'] ?? '')),
        'cycles'       => grade_num((string)($d['cycles'] ?? $d['powercycles'] ?? '')),
        'realloc'      => grade_num((string)($d['realloc'] ?? $d['reallocsectors'] ?? '')),
        'realloc_post' => grade_num((string)($d['realloc_post'] ?? $d['reallocsectorspost'] ?? '')),
        'used'         => grade_num((string)($d['pct_used'] ?? $d['pctused'] ?? '')),
        'spare'        => grade_num((string)($d['spare'] ?? $d['availspare'] ?? '')),
        'tbw'          => grade_num((string)($d['tbw'] ?? $d['tbw_tb'] ?? '')),
        'selftest'     => strtoupper(trim((string)($d['selftest'] ?? ''))),
        // Tape media health (the tape analogue of SMART) — keyed from the
        // report columns, which are generation-independent (MAM + TapeAlert).
        'tape_life'    => strtolower(trim((string)($d['tapemedialife'] ?? ''))),
        'tape_loads'   => grade_num((string)($d['tapeloadcount'] ?? '')),
        'tape_written' => grade_num((string)($d['tapewrittenmib'] ?? '')),
        'tape_manufactured' => trim((string)($d['tapemanufactured'] ?? '')),
    ];
}

/**
 * Drive resale grade from the captured SMART. Returns
 * ['grade' => 'A'|'B'|'C'|'D'|'?', 'reasons' => string[], 'graded' => bool].
 */
function drive_grade(array $d): array {
    $n = grade_normalize($d);
    $reasons = [];
    $grade = 'A';

    // Tape cartridges have no SMART; grade them from the TapeAlert media-life
    // verdict and MAM wear instead (the tape analogue of SMART).
    if (strtoupper(trim((string)($d['bus'] ?? ''))) === 'TAPE') {
        return tape_grade($d, $n);
    }

    // No usable SMART (USB bridge, unsupported device, or never captured) →
    // ungraded. Never a false pass.
    if ($n['smart'] === '' || $n['smart'] === 'UNSUP') {
        return [
            'grade'   => '?',
            'reasons' => ['SMART unavailable — reconnect via SATA/NVMe for a full grade'],
            'graded'  => false,
        ];
    }

    // D — failing. Any one of these fails the drive.
    if ($n['smart'] === 'FAIL') { $reasons[] = 'SMART health FAIL'; $grade = 'D'; }
    if ($n['realloc'] !== null && $n['realloc'] >= 5) { $reasons[] = 'reallocated sectors ≥ 5'; $grade = 'D'; }
    if ($n['used'] !== null && $n['used'] >= 90) { $reasons[] = 'wear ≥ 90%'; $grade = 'D'; }
    if ($n['spare'] !== null && $n['spare'] < 10) { $reasons[] = 'available spare < 10%'; $grade = 'D'; }
    if (strpos($n['selftest'], 'FAIL') !== false) { $reasons[] = 'self-test failed'; $grade = 'D'; }

    // C — fair (when not failing).
    if ($grade !== 'D') {
        if ($n['realloc'] !== null && $n['realloc'] >= 1) { $reasons[] = 'reallocated sectors present'; $grade = 'C'; }
        if ($n['poh'] !== null && $n['poh'] >= 26280) { $reasons[] = 'power-on hours ≥ 3 years'; $grade = 'C'; }
        if ($n['used'] !== null && $n['used'] >= 50) { $reasons[] = 'wear ≥ 50%'; $grade = 'C'; }
        if ($n['spare'] !== null && $n['spare'] < 20) { $reasons[] = 'available spare < 20%'; $grade = 'C'; }
    }

    // B — good (when not C/D): moderate wear or age.
    if ($grade !== 'D' && $grade !== 'C') {
        if ($n['used'] !== null && $n['used'] >= 10) { $grade = 'B'; }
        elseif ($n['spare'] !== null && $n['spare'] < 50) { $grade = 'B'; }
        elseif ($n['poh'] !== null && $n['poh'] >= 8760) { $grade = 'B'; }
    }

    // Post-wipe degradation is a flag, never a downgrade of a healthier pre
    // state — but it is always surfaced as a review signal.
    $deg = [];
    if ($n['smart_post'] === 'FAIL') { $deg[] = 'SMART FAIL after wipe'; }
    if ($n['realloc'] !== null && $n['realloc_post'] !== null && $n['realloc_post'] > $n['realloc']) {
        $deg[] = 'reallocated sectors increased during wipe';
    }
    if ($deg !== []) { $reasons[] = implode('; ', $deg); }

    return ['grade' => $grade, 'reasons' => $reasons, 'graded' => true];
}

/**
 * Native cartridge capacity (MiB) for an LTO generation, parsed from the
 * "LTO-N" / "LTO-N WORM" Type label. Used to turn the MAM "Total MiB written
 * in medium life" counter into an approximate full-pass count (a wear signal,
 * surfaced as a reason — never a grade input). Native capacities are the
 * decimal TB/GB specs converted to MiB, matching the MAM "Maximum capacity in
 * partition [MiB]" figures.
 */
function tape_native_capacity_mib(string $type): ?int {
    if (!preg_match('/LTO-([1-9])/i', $type, $m)) return null;
    static $cap = [
        1 => 95367,      // 100 GB
        2 => 190734,     // 200 GB
        3 => 381469,     // 400 GB
        4 => 762939,     // 800 GB (bench-verified MAM figure)
        5 => 1430511,    // 1.5 TB
        6 => 2384185,    // 2.5 TB
        7 => 5722045,    // 6 TB
        8 => 11444091,   // 12 TB
        9 => 17166137,   // 18 TB
    ];
    return $cap[(int)$m[1]] ?? null;
}

/**
 * Cartridge resale grade from the TapeAlert media-life verdict plus the MAM
 * wear counters. The drive's own verdict is authoritative: `expired` is a
 * recycle (D), `nearing` is reusable-with-caution (C), `ok` is reusable (A).
 * Wear context (full passes, load count, age) is surfaced as reasons, never as
 * hard fails — LTO media is archival and the vendor verdict is the health call.
 */
function tape_grade(array $d, array $n): array {
    $reasons = [];
    $life = $n['tape_life'];
    if ($life === 'expired') {
        $grade = 'D';
        $reasons[] = 'tape media life expired';
    } elseif ($life === 'nearing') {
        $grade = 'C';
        $reasons[] = 'tape media life nearing end';
    } elseif ($life === 'ok') {
        $grade = 'A';
    } else {
        return [
            'grade'   => '?',
            'reasons' => ['tape health unavailable — check the drive and cartridge'],
            'graded'  => false,
        ];
    }

    $cap = tape_native_capacity_mib((string)($d['type'] ?? ''));
    if ($n['tape_written'] !== null && $cap !== null && $cap > 0) {
        $passes = (int)round($n['tape_written'] / $cap);
        if ($passes > 0) $reasons[] = "~$passes full passes written";
    }
    if ($n['tape_loads'] !== null) {
        $reasons[] = 'load count ' . (int)$n['tape_loads'];
    }
    if ($n['tape_manufactured'] !== '') {
        $reasons[] = 'manufactured ' . $n['tape_manufactured'];
    }

    return ['grade' => $grade, 'reasons' => $reasons, 'graded' => true];
}

/**
 * Structured per-drive resale health report (identity + verdict + usage/wear +
 * defects + thermal + self-test).
 */
function drive_health_report(array $d): array {
    $n = grade_normalize($d);
    $g = drive_grade($d);
    return [
        'identity' => [
            'model'    => trim((string)($d['model'] ?? '')),
            'serial'   => trim((string)($d['serial'] ?? '')),
            'firmware' => trim((string)($d['firmware'] ?? '')),
            'size'     => trim((string)($d['size'] ?? '')),
            'bus'      => trim((string)($d['bus'] ?? '')),
            'type'     => trim((string)($d['type'] ?? '')),
        ],
        'grade'    => $g['grade'],
        'reasons'  => $g['reasons'],
        'smart'    => $n['smart'],
        'usage'    => [
            'power_on_hours' => $n['poh'],
            'power_cycles'   => $n['cycles'],
            'tbw_tb'         => $n['tbw'],
        ],
        'defects' => [
            'reallocated_sectors' => $n['realloc'],
            'reallocated_post'    => $n['realloc_post'],
        ],
        'wear' => [
            'percent_used'    => $n['used'],
            'available_spare' => $n['spare'],
        ],
        'thermal' => [
            'temp_c'      => grade_num((string)($d['tempc'] ?? $d['temp'] ?? '')),
            'temp_c_post' => grade_num((string)($d['tempcpost'] ?? $d['temp_post'] ?? '')),
        ],
        'self_test' => $n['selftest'],
    ];
}

/** Firmware placeholder identity values that carry no uniqueness — a shared QEMU
 *  sentinel (or "N/A") once keyed every placeholder machine to one bogus device.
 *  Used by device_key() to skip them when a better field is available. */
function device_key_placeholder(string $v): bool {
    static $bad = [
        'n/a', 'na', 'none', 'unknown', 'not specified', 'oem',
        'to be filled by o.e.m.', 'default string',
        'system product name', 'system manufacturer', '0',
        '00000000-0000-0000-0000-000000000000',
        '03000200-0400-0500-0006-000700080009',
    ];
    return in_array(strtolower(trim($v)), $bad, true);
}

/** The stable machine key used by load_devices(): sysserial → bbserial → uuid.
 *  Placeholder firmware values are skipped so a machine reporting a real uuid
 *  still keys on it; if every field is a placeholder the first non-empty value
 *  is used (legacy behaviour, keeps historical rows addressable). */
function device_key(array $g): string {
    $first = '';
    foreach (['sysserial', 'sysSerial', 'bbserial', 'bbSerial', 'systemuuid'] as $k) {
        $v = trim((string)($g[$k] ?? ''));
        if ($v === '') continue;
        if ($first === '') $first = $v;
        if (!device_key_placeholder($v)) return strtolower($v);
    }
    return strtolower($first);
}

/**
 * Device inbound-grading scheme (I-A…I-F), assessed manually by the operator:
 *   I-A  High refurb potential   (green)
 *   I-B  Refurb possible         (green)
 *   I-C  Low refurb potential    (orange)
 *   I-D  Scrap / parts likely    (orange)
 *   I-F  Faulty / dead           (grey)
 * Determined by the power/security, visual/physical, screen and
 * serious-damage checks (see the Devices-tab picker help text). There is no
 * safe automatic suggestion — the checks are visual/manual — so the grade is
 * the operator's pick.
 */
function valid_device_grade(string $grade): bool {
    return $grade === '' || in_array(strtoupper($grade), ['I-A', 'I-B', 'I-C', 'I-D', 'I-F'], true);
}

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

/** The stable machine key used by load_devices(): sysserial → bbserial → uuid. */
function device_key(array $g): string {
    foreach (['sysserial', 'sysSerial', 'bbserial', 'bbSerial', 'systemuuid'] as $k) {
        $v = trim((string)($g[$k] ?? ''));
        if ($v !== '') return strtolower($v);
    }
    return '';
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

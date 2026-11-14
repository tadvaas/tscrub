<?php
declare(strict_types=1);

/**
 * Live smoke test for the BIOS-unlock verdict plumbing.
 *
 * - applies the idempotent schema migration (verdict column + widened detail)
 * - reports the live column shape
 * - runs unlock_latest() over the most recent real commands, which is exactly
 *   what GET /api/bios/unlock returns to the dashboard
 *
 * Run from ~/webs/tscrub-form with the real db.php/org.php in place.
 */

require __DIR__ . '/bios_unlock.php';

unlock_ensure_schema();

echo "=== bios_unlock column shape ===\n";
foreach (db()->query('SHOW COLUMNS FROM bios_unlock')->fetchAll() as $c) {
    if (in_array($c['Field'], ['detail', 'verdict', 'result', 'status'], true)) {
        printf("  %-9s %-14s %s\n", $c['Field'], $c['Type'], $c['Default'] === null ? 'NULL' : (string)$c['Default']);
    }
}

echo "\n=== recent commands (what the dashboard will now show) ===\n";
$rows = db()->query('SELECT id, user_id, serial, status, result, detail FROM bios_unlock ORDER BY id DESC LIMIT 8')->fetchAll();
if ($rows === []) {
    echo "  (no bios_unlock rows)\n";
}
foreach ($rows as $r) {
    $v = unlock_verdict((string)$r['result'], (string)$r['detail'], '');
    printf(
        "  id=%-3s %-14s %-12s status=%-10s verdict=%-14s detail=%d chars\n",
        $r['id'],
        (string)$r['serial'],
        (string)$r['result'],
        (string)$r['status'],
        $v,
        mb_strlen((string)$r['detail'])
    );
}

if ($rows !== []) {
    $first = $rows[0];
    echo "\n=== unlock_latest() round trip (the dashboard's endpoint) ===\n";
    $u = unlock_latest((int)$first['user_id'], (string)$first['serial']);
    foreach ($u as $k => $val) {
        $val = is_string($val) ? $val : json_encode($val);
        printf("  %-12s %s\n", $k, mb_substr((string)$val, 0, 120));
    }
}

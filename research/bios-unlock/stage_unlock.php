<?php
declare(strict_types=1);

/**
 * Stage a real BIOS-unlock command and read back what the appliance reported.
 *
 *   php stage_unlock.php stage <user_id> <serial> <uuid> <password>
 *   php stage_unlock.php show  <id>
 *   php stage_unlock.php latest <user_id> <serial>     # what GET /api/bios/unlock returns
 *
 * Same DB path the dashboard's "Send" button uses (unlock_enqueue), so the
 * appliance claims it exactly as it would a dashboard-staged command.
 */

require __DIR__ . '/bios_unlock.php';

$cmd = $argv[1] ?? '';

if ($cmd === 'stage') {
    $userId = (int)($argv[2] ?? 0);
    $serial = (string)($argv[3] ?? '');
    $uuid   = (string)($argv[4] ?? '');
    $pwd    = (string)($argv[5] ?? '');
    $id = unlock_enqueue($userId, $serial, $uuid, $pwd);
    echo "staged id=$id user=$userId serial=$serial at ", gmdate('H:i:s'), "\n";
} elseif ($cmd === 'show') {
    $id = (int)($argv[2] ?? 0);
    $stmt = db()->prepare('SELECT id, serial, status, result, verdict, CHAR_LENGTH(detail) AS dlen, detail, created_at, dispatched_at, resolved_at, (password_enc = "") AS pwd_purged FROM bios_unlock WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if ($r === false) {
        echo "row $id not found\n";
        exit(1);
    }
    foreach ($r as $k => $v) {
        printf("%-14s %s\n", $k, (string)$v);
    }
} elseif ($cmd === 'latest') {
    $userId = (int)($argv[2] ?? 0);
    $serial = (string)($argv[3] ?? '');
    $u = unlock_latest($userId, $serial);
    foreach ($u as $k => $v) {
        printf("%-12s %s\n", $k, is_string($v) ? $v : json_encode($v));
    }
} else {
    echo "usage: stage <user_id> <serial> <uuid> <password> | show <id> | latest <user_id> <serial>\n";
}

<?php
declare(strict_types=1);

/**
 * End-to-end check of POST /api/bios/unlock/result → stored verdict, using a
 * throwaway row so a real command is never touched.
 *
 *   php result_roundtrip.php insert          # creates a dispatched row, prints its id
 *   php result_roundtrip.php check <id>      # prints result/verdict/detail length
 *   php result_roundtrip.php delete <id>     # removes the row
 *
 * The POST itself is made from the Mac with a real API token (so the route,
 * its auth and its WHERE clause are all exercised), then `check` reads back what
 * landed in the table.
 */

require __DIR__ . '/bios_unlock.php';

$cmd = $argv[1] ?? '';
$id  = (int)($argv[2] ?? 0);

if ($cmd === 'insert') {
    unlock_ensure_schema();
    db()->prepare('INSERT INTO bios_unlock (user_id, serial, uuid, password_enc, status, dispatched_at) VALUES (?, ?, "", "", "dispatched", UTC_TIMESTAMP())')
        ->execute([5, 'VERDICT-TEST']);
    echo (int)db()->lastInsertId(), "\n";
} elseif ($cmd === 'check') {
    $stmt = db()->prepare('SELECT id, status, result, verdict, detail, CHAR_LENGTH(detail) AS dlen FROM bios_unlock WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if ($r === false) {
        echo "row not found\n";
        exit(1);
    }
    printf("status=%s result=%s verdict=%s detail_chars=%s\n", $r['status'], $r['result'], $r['verdict'], $r['dlen']);
    printf("detail tail: ...%s\n", mb_substr((string)$r['detail'], -70));
} elseif ($cmd === 'delete') {
    db()->prepare('DELETE FROM bios_unlock WHERE id = ? AND serial = "VERDICT-TEST"')->execute([$id]);
    echo "deleted rows: ", db()->query('SELECT COUNT(*) FROM bios_unlock WHERE serial = "VERDICT-TEST"')->fetchColumn(), " remaining\n";
} else {
    echo "usage: insert | check <id> | delete <id>\n";
}

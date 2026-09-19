<?php
declare(strict_types=1);

/**
 * One-time migration: import legacy JSON certificate registry files
 * (certificates/*.json) into MySQL. Safe to re-run (skips existing IDs).
 *
 * Usage: php migrate.php
 */

require_once __DIR__ . '/db.php';

$dir = __DIR__ . '/certificates';
if (!is_dir($dir)) {
    fwrite(STDOUT, "No certificates directory found at {$dir} — nothing to migrate.\n");
    exit(0);
}

$files = glob($dir . '/*.json');
$total = count($files);
$imported = 0;
$skipped = 0;
$failed = 0;

foreach ($files as $file) {
    $jsonName = basename($file, '.json');
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || empty($data['cert'])) {
        fwrite(STDOUT, "SKIP  {$jsonName}: invalid JSON\n");
        $skipped++;
        continue;
    }

    try {
        $exists = db()->prepare('SELECT id FROM certificates WHERE cert_id = ?');
        $exists->execute([(string)$data['cert']]);
        if ($exists->fetch()) {
            fwrite(STDOUT, "SKIP  {$data['cert']}: already present\n");
            $skipped++;
            continue;
        }

        $issued = (string)($data['issued'] ?? '');
        $issuedSql = $issued !== ''
            ? gmdate('Y-m-d H:i:s', strtotime($issued) ?: time())
            : gmdate('Y-m-d H:i:s');

        db()->beginTransaction();
        $stmt = db()->prepare(
            'INSERT INTO certificates
             (cert_id, cocid, user_id, devices, methods, runs, first_ts, last_ts, sha_state, sig_state, pdf_sha256, issued_at)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string)$data['cert'],
            (string)($data['cocid'] ?? ''),
            (int)($data['devices'] ?? 0),
            (int)($data['methods'] ?? 0),
            (int)($data['runs'] ?? 0),
            isset($data['first']) ? (string)$data['first'] : null,
            isset($data['last']) ? (string)$data['last'] : null,
            (string)($data['sha_state'] ?? 'unverified'),
            (string)($data['sig_state'] ?? 'none'),
            (string)($data['pdf_sha256'] ?? ''),
            $issuedSql,
        ]);
        $dbId = (int)db()->lastInsertId();

        $rq = db()->prepare('INSERT INTO certificate_reports (certificate_id, report_name, sha256, state) VALUES (?, ?, ?, ?)');
        foreach ((array)($data['reports'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $rq->execute([
                $dbId,
                (string)($r['name'] ?? ''),
                (string)($r['sha'] ?? ''),
                (string)($r['state'] ?? ''),
            ]);
        }
        db()->commit();
        fwrite(STDOUT, "OK    {$data['cert']}\n");
        $imported++;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        fwrite(STDOUT, "FAIL  {$jsonName}: {$e->getMessage()}\n");
        $failed++;
    }
}

fwrite(STDOUT, "\nDone. imported={$imported} skipped={$skipped} failed={$failed} total={$total}\n");
exit($failed > 0 ? 1 : 0);
